<?php

declare(strict_types=1);

namespace EncryptedEnv\Diagnostics;

use EncryptedEnv\Env\MasterKeyFile;
use EncryptedEnv\Env\MasterKeyResolver;

final class ProjectInspector
{
    public function supportsCodeIgniterVersion(string $version): bool
    {
        return version_compare($version, '4.3.7', '>=')
            && version_compare($version, '5.0.0', '<');
    }

    /**
     * @return array<string, string|int|bool>
     */
    public function inspect(string $root): array
    {
        $envPath = $root . DIRECTORY_SEPARATOR . '.env';
        $sparkPath = $root . DIRECTORY_SEPARATOR . 'spark';
        $webPath = $root . DIRECTORY_SEPARATOR . 'public'
            . DIRECTORY_SEPARATOR . 'index.php';
        $envContent = is_file($envPath) ? file_get_contents($envPath) : false;
        $spark = is_file($sparkPath) ? file_get_contents($sparkPath) : false;
        $web = is_file($webPath) ? file_get_contents($webPath) : false;
        $bootstrap = $this->bootstrapStatus($root, $spark, $web);
        $key = (new MasterKeyFile())->check(new MasterKeyResolver());
        $composer = $this->composerStatus($root);

        return [
            'library_version' => $this->libraryVersion(dirname(__DIR__, 2)),
            'codeigniter_version' => defined('CodeIgniter\\CodeIgniter::CI_VERSION')
                ? constant('CodeIgniter\\CodeIgniter::CI_VERSION')
                : 'unavailable',
            'bootstrap' => $bootstrap,
            'env_exists' => is_string($envContent),
            'encrypted_variables' => is_string($envContent)
                ? $this->encryptedVariableCount($envContent)
                : 0,
            'key_source' => $key['source'],
            'key_valid' => $key['valid'],
            'key_message' => $key['message'],
            'composer_valid' => $composer,
        ];
    }

    public function encryptedVariableCount(string $content): int
    {
        preg_match_all(
            '/^[ \t]*[A-Za-z_][A-Za-z0-9_.]*[ \t]*=[ \t]*[\'"]?enc:v1:/m',
            $content,
            $matches
        );

        return count($matches[0]);
    }

    private function bootstrapStatus(string $root, string|false $spark, string|false $web): string
    {
        if (
            is_string($spark)
            && is_string($web)
            && str_contains($spark, 'EncryptedBoot::bootSpark')
            && str_contains($web, 'EncryptedBoot::bootWeb')
        ) {
            return 'modern-installed';
        }

        if (
            is_string($spark)
            && is_string($web)
            && str_contains($spark, 'EncryptedDotEnv')
            && str_contains($web, 'EncryptedDotEnv')
        ) {
            return 'legacy-installed';
        }

        $system = $root . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'codeigniter4' . DIRECTORY_SEPARATOR . 'framework'
            . DIRECTORY_SEPARATOR . 'system';

        if (is_file($system . DIRECTORY_SEPARATOR . 'Boot.php')) {
            return 'modern-not-installed';
        }

        if (is_file($system . DIRECTORY_SEPARATOR . 'bootstrap.php')) {
            return 'legacy-not-installed';
        }

        return 'unknown';
    }

    private function composerStatus(string $root): bool
    {
        $path = $root . DIRECTORY_SEPARATOR . 'composer.json';
        $contents = is_file($path) ? file_get_contents($path) : false;

        if (! is_string($contents)) {
            return false;
        }

        $composer = json_decode($contents, true);

        return is_array($composer)
            && isset($composer['require']['codeigniter4/framework'])
            && is_string($composer['require']['codeigniter4/framework'])
            && is_file($root . DIRECTORY_SEPARATOR . 'vendor'
                . DIRECTORY_SEPARATOR . 'autoload.php');
    }

    private function libraryVersion(string $packageRoot): string
    {
        $path = $packageRoot . DIRECTORY_SEPARATOR . 'composer.json';
        $contents = is_file($path) ? file_get_contents($path) : false;
        $composer = is_string($contents) ? json_decode($contents, true) : null;

        return is_array($composer) && isset($composer['version'])
            ? (string) $composer['version']
            : 'development';
    }
}
