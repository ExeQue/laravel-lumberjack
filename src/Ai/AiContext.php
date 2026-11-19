<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Ai;

use Closure;
use ExeQue\Lumberjack\Activity\Reference;
use Generator;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use Traversable;

/**
 * Who AI calls are made for while a callback runs: the actor, the key's owner (a tenant
 * bringing its own key) and the key's label. Scopes nest; an inner one overrides only what
 * it sets, and ends when its callback returns or throws. Not fiber-safe: scopes are one
 * stack per process.
 *
 * A stream is read after the callback has returned, so its scope goes with it: a generator
 * comes back wrapped, entering the scope while it runs, a streamed HTTP response runs its
 * callback in the scope, and a stream object with an invocation id (Laravel AI's
 * StreamableAgentResponse) is bound by that id to the scope it was returned in. A run
 * started from inside another (a sub-agent in a tool, a conversation title) shares its
 * binding and what its agent says (HasLumberjackAiOwner and the like).
 *
 * A call's scope, from the weakest: the scopes open around the code reading its stream,
 * then the stream's binding, then what the agents of the run and the runs around it say
 * (inner over outer), then the scopes opened inside the run (by its tools or sub-agents).
 * Each scope remembers the run it was opened inside, so where or when the stream is read
 * does not matter.
 */
final class AiContext
{
    /** Runs remembered at most. */
    public const int MAX_RUNS = 100;

    /** Laravel AI's record of the run a call is made from inside of; by name, as it is optional. */
    private const string PARENT_INVOCATION = 'Laravel\\Ai\\Gateway\\ParentInvocation';

    /** @var list<array{scope: array{by: ?Reference, owner: ?Reference, label: ?string}, in: ?string}> open scopes and the run each was opened inside */
    private array $scopes = [];

    /**
     * Runs by invocation id, least recently used first: the run and the runs it was started
     * inside of (chain), the scope its stream is bound to, and what its agent says (null
     * until it starts).
     *
     * @var array<string, array{chain: non-empty-list<string>, scope: ?array{by: ?Reference, owner: ?Reference, label: ?string}, agent: ?array{by: ?Reference, owner: ?Reference, label: ?string}}>
     */
    private array $runs = [];

    /** @var array<string, true> runs still going (started or bound, not done), the longest without news first, dropped first past the cap */
    private array $going = [];

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function run(Closure $callback, ?Reference $by = null, ?Reference $owner = null, ?string $label = null): mixed
    {
        $scope = ['by' => $by, 'owner' => $owner, 'label' => $label];
        $result = $this->within($scope, $callback);

        // The whole scope here, the binding of the run it is made inside of included.
        return $this->carry($result, $this->within($scope, fn (): array => $this->current()));
    }

    /**
     * The scope of a call of this invocation, or of the one it is made inside of. The
     * innermost wins per field.
     *
     * @return array{by: ?Reference, owner: ?Reference, label: ?string}
     */
    public function current(?string $invocationId = null): array
    {
        $key = isset($this->runs[$invocationId ?? '']) ? $invocationId : self::parentInvocation();
        $chain = [];

        // Up to the innermost run with a stream of its own: that binding was made with what is
        // around it, so scopes opened outside it since are the reader's.
        foreach ($key !== null && isset($this->runs[$key]) ? $this->touch($this->runs[$key]['chain']) : [] as $id) {
            $chain[] = $id;

            if (($this->runs[$id]['scope'] ?? null) !== null) {
                break;
            }
        }

        $reader = $inside = [];

        foreach ($this->scopes as $frame) {
            if (in_array($frame['in'], $chain, true)) {
                $inside[] = $frame['scope'];
            } else {
                $reader[] = $frame['scope'];
            }
        }

        $layers = [];

        foreach (array_reverse($chain) as $id) {
            $layers[] = $this->runs[$id]['scope'] ?? null;
            $layers[] = $this->runs[$id]['agent'] ?? null;
        }

        $current = ['by' => null, 'owner' => null, 'label' => null];

        foreach ([...$reader, ...array_filter($layers), ...$inside] as $scope) {
            $current = [
                'by' => $scope['by'] ?? $current['by'],
                'owner' => $scope['owner'] ?? $current['owner'],
                'label' => $scope['label'] ?? $current['label'],
            ];
        }

        return $current;
    }

    /**
     * A run of this invocation starts here, inside the run it is made from (if any). What its
     * agent says is read once, at its first start.
     *
     * @param  Closure(): array{by: ?Reference, owner: ?Reference, label: ?string}  $agent
     * @return array{by: ?Reference, owner: ?Reference, label: ?string}  what its agent says
     */
    public function starting(string $invocationId, Closure $agent): array
    {
        $agent = $this->register($invocationId)['agent'] ?? $agent();
        $this->going($invocationId);

        // Reading it may have started other runs: kept only while the run is still known.
        if (isset($this->runs[$invocationId])) {
            $this->runs[$invocationId]['agent'] = $agent;
        }

        return $agent;
    }

    /**
     * The run is done (null: every run, as when the request or job ends): it may be dropped
     * once it is the least recently used.
     */
    public function finished(?string $invocationId = null): void
    {
        if ($invocationId === null) {
            $this->going = [];
        } else {
            unset($this->going[$invocationId]);
        }
    }

    /** Whether the run is known still, its binding and chain with it. */
    public function knows(string $invocationId): bool
    {
        return isset($this->runs[$invocationId]);
    }

    /**
     * What the agent of this invocation said when it started, if it did.
     *
     * @return array{by: ?Reference, owner: ?Reference, label: ?string}|null
     */
    public function agent(string $invocationId): ?array
    {
        return $this->runs[$invocationId]['agent'] ?? null;
    }

    /**
     * Lets go of what streams were bound to. Open scopes unwind on their own, when their
     * callbacks return.
     */
    public function clear(): void
    {
        $this->runs = [];
        $this->going = [];
    }

    /**
     * @template TResult
     *
     * @param  array{by: ?Reference, owner: ?Reference, label: ?string}  $scope
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function within(array $scope, Closure $callback): mixed
    {
        $depth = count($this->scopes);
        $this->scopes[] = ['scope' => $scope, 'in' => self::parentInvocation()];

        try {
            return $callback();
        } finally {
            $this->scopes = array_slice($this->scopes, 0, $depth);
        }
    }

    /**
     * @param  array{by: ?Reference, owner: ?Reference, label: ?string}  $scope
     */
    private function carry(mixed $result, array $scope): mixed
    {
        if ($result instanceof Generator) {
            return $this->scoped($result, $scope);
        }

        if (class_exists(StreamedResponse::class) && $result instanceof StreamedResponse && $result->getCallback() !== null) {
            $callback = $result->getCallback();
            $result->setCallback(fn (mixed ...$arguments): mixed => $this->within($scope, fn (): mixed => $callback(...$arguments)));
        }

        $id = $result instanceof Traversable && isset($result->invocationId) && is_string($result->invocationId) ? $result->invocationId : null;

        // The innermost scope it comes back through binds it.
        if ($id !== null) {
            $this->register($id);
            $this->going($id);

            if (isset($this->runs[$id])) {
                $this->runs[$id]['scope'] ??= $scope;
            }
        }

        return $result;
    }

    /**
     * The run, registered inside the one it is made from when new. Kept until the next
     * request or job (a title generated after the stream still needs it).
     *
     * @return array{chain: non-empty-list<string>, scope: ?array{by: ?Reference, owner: ?Reference, label: ?string}, agent: ?array{by: ?Reference, owner: ?Reference, label: ?string}}
     */
    private function register(string $invocationId): array
    {
        if (isset($this->runs[$invocationId])) {
            $this->touch($this->runs[$invocationId]['chain']);

            return $this->runs[$invocationId];
        }

        $parent = self::parentInvocation();
        $chain = $parent === null ? [] : $this->touch($this->runs[$parent]['chain'] ?? [$parent]);

        // ponytail: past the cap the least recently used run that is done is dropped (runs
        // around a running one are used with it); only when every run is going, the oldest.
        if (count($this->runs) >= self::MAX_RUNS) {
            // Nor the runs around one still going: a child still running needs its parent's binding.
            $kept = array_flip(array_merge($chain, ...array_map(fn (string $id): array => $this->runs[$id]['chain'] ?? [], array_keys($this->going))));
            $done = array_diff_key($this->runs, $this->going, $kept);
            unset($this->runs[array_key_first($done === [] ? $this->runs : $done)]);
        }

        return $this->runs[$invocationId] = ['chain' => [$invocationId, ...$chain], 'scope' => null, 'agent' => null];
    }

    /** Marks the run going, and the runs around it going still, the outermost first. */
    private function going(string $invocationId): void
    {
        // The runs around it are still going only if they were: a late child never revives a finished parent.
        foreach (array_reverse($this->runs[$invocationId]['chain'] ?? [$invocationId]) as $id) {
            if ($id === $invocationId || isset($this->going[$id])) {
                unset($this->going[$id]);
                $this->going[$id] = true;
            }
        }

        // ponytail: bounded too; past the cap the run longest without news is taken as done.
        while (count($this->going) > self::MAX_RUNS) {
            unset($this->going[array_key_first($this->going)]);
        }
    }

    /**
     * Marks the run and the runs around it used, the outermost first, so neither a run still
     * going nor one it was started inside of is the one dropped.
     *
     * @param  list<string>  $chain
     * @return list<string>
     */
    private function touch(array $chain): array
    {
        foreach (array_reverse($chain) as $id) {
            if (isset($this->runs[$id])) {
                $entry = $this->runs[$id];
                unset($this->runs[$id]);
                $this->runs[$id] = $entry;
            }
        }

        return $chain;
    }

    /** The Laravel AI run a call is made from inside of, such as in a tool of a streamed agent. */
    private static function parentInvocation(): ?string
    {
        try {
            $current = class_exists(self::PARENT_INVOCATION) ? (self::PARENT_INVOCATION)::current() : null;
        } catch (Throwable) {
            // A Laravel AI that changed it must never fail the app's own callback.
            return null;
        }

        return is_array($current) && is_string($current[0] ?? null) ? $current[0] : null;
    }

    /**
     * The generator, run inside the scope one step at a time, so code reading it between
     * steps stays outside. Stopping early ends it inside the scope too.
     *
     * @param  array{by: ?Reference, owner: ?Reference, label: ?string}  $scope
     */
    private function scoped(Generator $inner, array $scope): Generator
    {
        try {
            while ($this->within($scope, fn (): bool => $inner->valid())) {
                try {
                    $sent = yield $inner->key() => $inner->current();
                } catch (Throwable $e) {
                    // Thrown in by the reader: the inner generator may catch it and go on.
                    $this->within($scope, fn (): mixed => $inner->throw($e));

                    continue;
                }

                $this->within($scope, fn (): mixed => $inner->send($sent));
            }

            return $inner->getReturn();
        } finally {
            // The last reference: a generator left mid-way runs its finally blocks now, in scope.
            $this->within($scope, function () use (&$inner): void {
                $inner = null;
            });
        }
    }
}
