<?php

declare(strict_types=1);

namespace EncryptedEnv\Env;

use RuntimeException;

final class MasterKeyFile
{
    public function create(string $path, bool $overwrite = false): string
    {
        return $this->store($path, random_bytes(32), $overwrite);
    }

    public function store(string $path, string $key, bool $overwrite = false): string
    {
        if (strlen($key) !== 32) {
            throw new RuntimeException('Master key must contain exactly 32 bytes.');
        }

        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the key directory.');
        }

        $directory = realpath($directory);

        if ($directory === false || is_link($path)) {
            throw new RuntimeException('Invalid master key file path.');
        }

        $path = $directory . DIRECTORY_SEPARATOR . basename($path);

        if (file_exists($path) && ! $overwrite) {
            throw new RuntimeException('Master key file already exists; refusing to overwrite.');
        }

        $content = base64_encode($key) . PHP_EOL;
        $temporary = tempnam($directory, '.encrypted-env-key-');

        if ($temporary === false) {
            throw new RuntimeException('Unable to create temporary key file.');
        }

        try {
            if (DIRECTORY_SEPARATOR !== '\\' && ! chmod($temporary, 0600)) {
                throw new RuntimeException('Unable to secure the key file.');
            }

            $handle = fopen($temporary, 'wb');

            if ($handle === false) {
                throw new RuntimeException('Unable to open the key file for writing.');
            }

            try {
                $written = 0;
                $length = strlen($content);

                while ($written < $length) {
                    $bytes = fwrite($handle, substr($content, $written));

                    if ($bytes === false || $bytes === 0) {
                        throw new RuntimeException('Unable to write the key file.');
                    }

                    $written += $bytes;
                }

                if (! fflush($handle) || (function_exists('fsync') && ! fsync($handle))) {
                    throw new RuntimeException('Unable to flush the key file to disk.');
                }
            } finally {
                fclose($handle);
            }

            if (DIRECTORY_SEPARATOR !== '\\' && ! chmod($temporary, 0600)) {
                throw new RuntimeException('Unable to set key file permissions.');
            }

            if (file_exists($path) && ! $overwrite) {
                throw new RuntimeException('Master key file already exists; refusing to overwrite.');
            }

            if (! rename($temporary, $path)) {
                throw new RuntimeException('Unable to atomically create the key file.');
            }

            $temporary = '';
        } finally {
            if ($temporary !== '' && is_file($temporary)) {
                unlink($temporary);
            }
        }

        return $path;
    }

    /**
     * @return array{source: string, path: ?string, valid: bool, message: string}
     */
    public function check(MasterKeyResolver $resolver): array
    {
        $keyFile = $this->environmentValue('ENCRYPTED_ENV_MASTER_KEY_FILE');
        $directKey = $this->environmentValue('ENCRYPTED_ENV_MASTER_KEY');

        try {
            $key = $resolver->resolve();

            return [
                'source' => $keyFile !== null ? 'file' : 'environment',
                'path' => $keyFile,
                'valid' => true,
                'message' => 'Configured master key is valid.',
            ];
        } catch (\Throwable $exception) {
            return [
                'source' => $keyFile !== null
                    ? 'file'
                    : ($directKey !== null ? 'environment' : 'none'),
                'path' => $keyFile,
                'valid' => false,
                'message' => $exception->getMessage(),
            ];
        }
    }

    private function environmentValue(string $name): ?string
    {
        $value = getenv($name);

        if ($value !== false) {
            return $value;
        }

        foreach ([$_SERVER, $_ENV] as $environment) {
            if (isset($environment[$name]) && is_string($environment[$name])) {
                return $environment[$name];
            }
        }

        return null;
    }
}
