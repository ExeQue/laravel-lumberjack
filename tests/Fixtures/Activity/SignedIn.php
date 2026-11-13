<?php

declare(strict_types=1);

namespace Tests\Fixtures\Activity;

use ExeQue\Lumberjack\Attributes\RecordsLumberjackActivity;

#[RecordsLumberjackActivity('user.signed_in', byUser: true)]
final class SignedIn
{
}
