<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Activity;

use ExeQue\Lumberjack\Exceptions\ForgetActorFailedException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * Asks Lumberjack to forget every activity of one actor, such as when the account
 * behind it is deleted. Unlike recording, this fails loudly: a deletion that did not
 * happen must not look like one that did.
 */
class ActivityActors
{
    public const int TIMEOUT = 10;

    /** @var list<Reference>|null the actors forgotten while faked */
    private ?array $faked = null;

    public function __construct(
        private readonly Http $http,
        private readonly string $url,
        private readonly ?string $token,
    ) {
    }

    /**
     * @return int activities deleted
     *
     * @throws ForgetActorFailedException
     */
    public function forget(Model|string $actor, string|int|null $id = null): int
    {
        $reference = Reference::of($actor, $id) ?? throw ForgetActorFailedException::noActor();

        if ($this->faked !== null) {
            $this->faked[] = $reference;

            return 0;
        }

        if ($this->url === '' || $this->token === null || $this->token === '') {
            throw ForgetActorFailedException::notConfigured();
        }

        try {
            $response = $this->http->withToken($this->token)->timeout(self::TIMEOUT)->acceptJson()
                ->retry(3, 200, fn (Throwable $e): bool => $e instanceof ConnectionException, throw: false)
                ->delete($this->url.'/api/activity/actor', $reference->toArray());
        } catch (Throwable $e) {
            throw ForgetActorFailedException::unreachable($e);
        }

        if (! $response->successful()) {
            throw ForgetActorFailedException::refused($response->status());
        }

        return (int) $response->json('deleted', 0);
    }

    /**
     * Keeps the actors to forget instead of asking Lumberjack.
     */
    public function fake(): void
    {
        $this->faked = [];
    }

    /**
     * @return list<Reference>
     */
    public function forgotten(): array
    {
        return $this->faked ?? [];
    }
}
