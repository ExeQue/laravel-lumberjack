<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Ai;

use Closure;
use ExeQue\Lumberjack\Activity\Actor;
use ExeQue\Lumberjack\Activity\Reference;
use ExeQue\Lumberjack\Contracts\ActsAsLumberjackActor;
use ExeQue\Lumberjack\Contracts\HasLumberjackAiActor;
use ExeQue\Lumberjack\Contracts\HasLumberjackAiLabel;
use ExeQue\Lumberjack\Contracts\HasLumberjackAiOwner;
use ExeQue\Lumberjack\Recorder;
use ExeQue\Lumberjack\Support\ExceptionContext;
use ExeQue\Lumberjack\Support\Safely;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Turns an AiCall into the ai_call event. AI calls are accounting: every one is recorded,
 * never sampled and never held back with an unsampled run.
 *
 * The actor, the key's owner and its label come from, first to last: what the call was
 * recorded with by hand, the resolver, the agent making it (HasLumberjackAiOwner and the like),
 * the Lumberjack::ai() scopes setting them, and (the actor only) the logged in user of the request being served. A queue worker or
 * a command has no default actor: a user logged in there may be left from the last job.
 */
final class AiCallRecorder
{
    /** @var (Closure(AiCall): ?array<string, mixed>)|null */
    private ?Closure $resolver = null;

    public function __construct(
        private readonly Recorder $recorder,
        private readonly bool $enabled = true,
        private readonly ?ExceptionContext $context = null,
    ) {
    }

    /**
     * @param  (Closure(AiCall): ?array<string, mixed>)|null  $resolver  returns by, owner and label to use, or null
     */
    public function resolveKeysUsing(?Closure $resolver): void
    {
        $this->resolver = $resolver;
    }

    /**
     * @param  array{by?: ActsAsLumberjackActor|Actor|Model|null, owner?: ActsAsLumberjackActor|Actor|Model|null, label?: ?string}  $given
     * @param  array{by: ?Reference, owner: ?Reference, label: ?string}|null  $scope  the scope it was made in, when it is recorded later
     * @param  array{by: ?Reference, owner: ?Reference, label: ?string}|null  $agent  what the agent making it says, from agentScope()
     */
    public function record(AiCall $call, array $given = [], ?array $scope = null, ?array $agent = null): void
    {
        if (! $this->enabled) {
            return;
        }

        $scope ??= $this->scopeFor($call->invocationId);
        $resolved = $this->resolve($call);

        $by = self::reference($given['by'] ?? null) ?? $resolved['by'] ?? $agent['by'] ?? $scope['by'] ?? $this->user();
        $owner = self::reference($given['owner'] ?? null) ?? $resolved['owner'] ?? $agent['owner'] ?? $scope['owner'];
        $label = $given['label'] ?? $resolved['label'] ?? $agent['label'] ?? $scope['label'];

        $this->recorder->record([
            'type' => 'ai_call',
            'source' => $call->source,
            'provider' => $call->provider,
            'driver' => $call->driver,
            'model' => $call->model,
            'operation' => $call->operation,
            'tokens' => [
                'input' => $call->inputTokens,
                'output' => $call->outputTokens,
                'cache_read' => $call->cacheReadTokens,
                'cache_write' => $call->cacheWriteTokens,
                'reasoning' => $call->reasoningTokens,
            ],
            'duration_ms' => $call->durationMs === null ? null : round($call->durationMs, 3),
            'status' => $call->failed() ? 'error' : 'ok',
            'error' => $call->error,
            'key' => $call->keySha256 === null && $owner === null && $label === null ? null : [
                'sha256' => $call->keySha256,
                'label' => $label,
                'owner' => $owner?->toArray(),
            ],
            'actor' => $by?->toArray(),
            'invocation_id' => $call->invocationId,
            'step' => $call->step,
        ]);
    }

    /**
     * One that cannot be told (its toLumberjackActor() throws, a morph map without it) is
     * said once and left out; the call is recorded without it.
     */
    private static function reference(mixed $value): ?Reference
    {
        try {
            return $value instanceof ActsAsLumberjackActor || $value instanceof Actor || $value instanceof Model ? Reference::of($value) : null;
        } catch (Throwable $e) {
            // The class only: a message may hold what it was working with.
            Safely::problem('Lumberjack could not tell who an AI call is for: '.$e::class);

            return null;
        }
    }

    /**
     * A run of this invocation starts: an agent prompted or streamed, a sub-agent in a tool.
     * What its agent says is read once per run.
     *
     * @return array{by: ?Reference, owner: ?Reference, label: ?string}
     */
    public function starting(string $invocationId, object $agent): array
    {
        return $this->recorder->aiContext()->starting($invocationId, fn (): array => $this->agentScope($agent));
    }

    /** The run of this invocation is done (null: every run). */
    public function finished(?string $invocationId = null): void
    {
        $this->recorder->aiContext()->finished($invocationId);
    }

    /** Whether the run of this invocation is known still, with its binding. */
    public function knows(string $invocationId): bool
    {
        return $this->recorder->aiContext()->knows($invocationId);
    }

    /**
     * What the agent of this invocation said when its run started.
     *
     * @return array{by: ?Reference, owner: ?Reference, label: ?string}|null
     */
    public function agentFor(string $invocationId): ?array
    {
        return $this->recorder->aiContext()->agent($invocationId);
    }

    /**
     * The scope a call of this invocation is made in right now.
     *
     * @return array{by: ?Reference, owner: ?Reference, label: ?string}
     */
    public function scopeFor(?string $invocationId): array
    {
        return $this->recorder->aiContext()->current($invocationId);
    }

    /**
     * What an agent says its calls are made for, through the contracts it implements. One
     * that throws is said once and left out; the others still count.
     *
     * @return array{by: ?Reference, owner: ?Reference, label: ?string}
     */
    public function agentScope(object $agent): array
    {
        $ask = function (string $field, Closure $read) use ($agent): mixed {
            try {
                return $read();
            } catch (Throwable $e) {
                // The classes only: a message may hold what the agent was working with.
                Safely::problem("Lumberjack could not read the AI {$field} of agent ".$agent::class.': '.$e::class);

                return null;
            }
        };

        return [
            'by' => $agent instanceof HasLumberjackAiActor ? self::reference($ask('actor', $agent->lumberjackAiActor(...))) : null,
            'owner' => $agent instanceof HasLumberjackAiOwner ? self::reference($ask('owner', $agent->lumberjackAiOwner(...))) : null,
            'label' => $agent instanceof HasLumberjackAiLabel ? $ask('label', $agent->lumberjackAiLabel(...)) : null,
        ];
    }

    /**
     * What the resolver says. One that throws is said once and left out; the call is
     * recorded without it.
     *
     * @return array{by: ?Reference, owner: ?Reference, label: ?string}
     */
    private function resolve(AiCall $call): array
    {
        try {
            $resolved = $this->resolver !== null ? (($this->resolver)($call) ?? []) : [];

            return [
                'by' => self::reference($resolved['by'] ?? null),
                'owner' => self::reference($resolved['owner'] ?? null),
                'label' => is_string($resolved['label'] ?? null) ? $resolved['label'] : null,
            ];
        } catch (Throwable $e) {
            // The class only: a message may hold what the resolver was working with.
            Safely::problem('Lumberjack\'s AI key resolver failed: '.$e::class);

            return ['by' => null, 'owner' => null, 'label' => null];
        }
    }

    /** The logged in user of the request being served, when it can be an actor. */
    private function user(): ?Reference
    {
        try {
            return $this->context?->servesRequest() === true ? self::reference(auth()->user()) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
