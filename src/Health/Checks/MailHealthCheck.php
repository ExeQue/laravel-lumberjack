<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Health\Checks;

use ExeQue\Lumberjack\Contracts\HealthCheck;
use ExeQue\Lumberjack\Health\CheckResult;
use ExeQue\Lumberjack\Health\Concerns\ConfiguresHealthCheck;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;

/**
 * Connects to an SMTP mailer's server and logs in, without sending anything.
 */
final class MailHealthCheck implements HealthCheck
{
    use ConfiguresHealthCheck;

    public function __construct(
        private readonly ?string $mailer = null,
        private readonly int $degradeAfter = 2,
        private readonly int $recoverAfter = 5,
        private readonly int $timeout = 10,
        private readonly ?string $slug = null,
    ) {
    }

    public function run(): CheckResult
    {
        $mailer = $this->mailer ?? (string) config('mail.default');

        if (config("mail.mailers.{$mailer}.transport") !== 'smtp') {
            return CheckResult::ok("Mailer [{$mailer}] is not SMTP; not checked.");
        }

        $transport = Mail::mailer($mailer)->getSymfonyTransport();

        if (! $transport instanceof SmtpTransport) {
            return CheckResult::ok("Mailer [{$mailer}] is not SMTP; not checked.");
        }

        $start = hrtime(true);
        $transport->start();
        $latency = self::since($start);
        $transport->stop();

        return CheckResult::ok(metrics: ['latency_ms' => $latency]);
    }

    protected function defaultSlug(): string
    {
        return 'mail:'.($this->mailer ?? config('mail.default'));
    }
}
