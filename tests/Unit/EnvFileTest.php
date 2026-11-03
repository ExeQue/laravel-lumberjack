<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use ExeQue\Lumberjack\Install\EnvFile;

it('writes a value that phpdotenv reads back as it was, $ and quotes included', function () {
    $path = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($path, "APP_NAME=Shop\n");
    $value = 'pa$s "w" ${APP_NAME} \\ end';

    (new EnvFile($path))->set('LUMBERJACK_TOKEN', $value);

    expect(Dotenv::parse((string) file_get_contents($path))['LUMBERJACK_TOKEN'])->toBe($value);
    unlink($path);
});
