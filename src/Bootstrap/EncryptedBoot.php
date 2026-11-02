<?php

declare(strict_types=1);

namespace EncryptedEnv\Bootstrap;

use CodeIgniter\Boot;
use CodeIgniter\CLI\Console;
use Config\Paths;
use Config\Services;
use EncryptedEnv\Env\EncryptedDotEnv;

if (class_exists(Boot::class)) {
    final class EncryptedBoot extends Boot
    {
        protected static function loadDotEnv(Paths $paths): void
        {
            require_once $paths->systemDirectory . '/Config/DotEnv.php';
            require_once __DIR__ . '/../Crypto/AesGcmCipher.php';
            require_once __DIR__ . '/../Env/MasterKeyResolver.php';
            require_once __DIR__ . '/../Env/EncryptedDotEnv.php';

            $envDirectory = $paths->envDirectory
                ?? $paths->appDirectory . '/../';

            (new EncryptedDotEnv($envDirectory))->load();
        }
    }
} else {
    final class EncryptedBoot
    {
        public static function bootSpark(Paths $paths): int
        {
            self::loadDotEnv($paths);

            $app = Services::codeigniter();
            $app->initialize();

            $console = new Console();
            $suppress = array_search('--no-header', $_SERVER['argv'], true);

            if (is_int($suppress)) {
                unset($_SERVER['argv'][$suppress]);
                $suppress = true;
            }

            $console->showHeader($suppress);
            $exitCode = $console->run();

            return is_int($exitCode) ? $exitCode : EXIT_SUCCESS;
        }

        public static function bootWeb(Paths $paths): int
        {
            self::loadDotEnv($paths);

            $app = Services::codeigniter();
            $app->initialize();
            $app->setContext(is_cli() ? 'php-cli' : 'web');
            $app->run();

            return EXIT_SUCCESS;
        }

        private static function loadDotEnv(Paths $paths): void
        {
            require_once $paths->systemDirectory . '/Config/DotEnv.php';
            require_once __DIR__ . '/../Crypto/AesGcmCipher.php';
            require_once __DIR__ . '/../Env/MasterKeyResolver.php';
            require_once __DIR__ . '/../Env/EncryptedDotEnv.php';

            $envDirectory = $paths->envDirectory
                ?? $paths->appDirectory . '/../';

            (new EncryptedDotEnv($envDirectory))->load();
        }
    }
}
