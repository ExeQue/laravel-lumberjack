<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Exceptions;

use RuntimeException;
use Throwable;

class ForgetActorFailedException extends RuntimeException implements LumberjackExceptionInterface
{
    public static function noActor(): self
    {
        return new self('An actor to forget needs a type and a key, or a model with a key.');
    }

    public static function notConfigured(): self
    {
        return new self('Forgetting an actor needs LUMBERJACK_URL and LUMBERJACK_TOKEN.');
    }

    public static function unreachable(Throwable $previous): self
    {
        return new self('Lumberjack could not be reached to forget the actor: '.$previous->getMessage(), previous: $previous);
    }

    public static function refused(int $status): self
    {
        return new self(sprintf('Lumberjack answered %d when asked to forget the actor.', $status));
    }
}
