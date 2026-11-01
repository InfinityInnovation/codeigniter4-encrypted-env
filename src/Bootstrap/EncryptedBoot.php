<?php

declare(strict_types=1);

namespace EncryptedEnv\Bootstrap;

use CodeIgniter\Boot;
use Config\Paths;
use EncryptedEnv\Env\EncryptedDotEnv;

final class EncryptedBoot extends Boot
{
    protected static function loadDotEnv(Paths $paths): void
    {
        require_once $paths->systemDirectory . '/Config/DotEnv.php';

        $envDirectory = $paths->envDirectory
            ?? $paths->appDirectory . '/../';

        (new EncryptedDotEnv($envDirectory))->load();
    }
}
