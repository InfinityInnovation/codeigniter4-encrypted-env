<?php

declare(strict_types=1);

namespace EncryptedEnv\Env;

use RuntimeException;

final class EnvFileTransaction
{
    /**
     * @return array{changed: bool, backup: ?string}
     */
    public function transform(
        string $path,
        callable $transform,
        string $operation,
        bool $secureOutput = false,
        bool $requirePlaintext = false
    ): array {
        $this->assertTarget($path);
        $original = file_get_contents($path);

        if ($original === false) {
            throw new RuntimeException('Unable to read .env file.');
        }

        $updated = $transform($original);

        if (! is_string($updated)) {
            throw new RuntimeException('Environment transformation returned invalid data.');
        }

        if ($updated === $original) {
            return ['changed' => false, 'backup' => null];
        }

        if ($requirePlaintext && preg_match(
            '/^[ \t]*[A-Za-z_][A-Za-z0-9_.]*[ \t]*=[ \t]*[\'"]?enc:v1:/m',
            $updated
        ) === 1) {
            throw new RuntimeException(
                'Refusing to write a partially decrypted .env file.'
            );
        }

        $permissions = fileperms($path);

        if ($permissions === false) {
            throw new RuntimeException('Unable to read .env file permissions.');
        }

        $directory = dirname($path);
        $mode = $secureOutput ? 0600 : ($permissions & 0777);
        $temporary = $this->writeTemporary($directory, $updated, $mode);
        $backup = null;

        try {
            clearstatcache(true, $path);
            $this->assertTarget($path);

            if (file_get_contents($path) !== $original) {
                throw new RuntimeException('.env file changed during processing.');
            }

            $backup = $this->writeBackup($path, $original, $operation);

            if (! rename($temporary, $path)) {
                throw new RuntimeException('Unable to atomically replace .env file.');
            }

            $temporary = '';

            return ['changed' => true, 'backup' => $backup];
        } finally {
            if ($temporary !== '' && is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function restore(string $path, string $backupPath): ?string
    {
        $this->assertTarget($path);

        $backupRealPath = realpath($backupPath);

        if (
            is_link($backupPath)
            || ! is_file($backupPath)
            || ! is_readable($backupPath)
            || $backupRealPath === false
            || (
                DIRECTORY_SEPARATOR !== '\\'
                && ((fileperms($backupRealPath) ?: 0) & 0077) !== 0
            )
            || preg_match(
                '/^' . preg_quote(basename($path), '/') . '\.encrypted-env-[a-z0-9-]+-\d{8}-\d{6}-[a-f0-9]{8}\.bak$/',
                basename($backupRealPath)
            ) !== 1
            || realpath(dirname($path)) !== realpath(dirname($backupRealPath))
        ) {
            throw new RuntimeException('Invalid or unreadable encrypted-env backup.');
        }

        $backup = file_get_contents($backupPath);

        if ($backup === false) {
            throw new RuntimeException('Unable to read environment backup.');
        }

        $result = $this->transform(
            $path,
            static fn (): string => $backup,
            'restore',
            true,
            false
        );

        if (! $result['changed']) {
            return null;
        }

        return $result['backup']
            ?? throw new RuntimeException('Unable to preserve the replaced environment file.');
    }

    private function assertTarget(string $path): void
    {
        if (is_link($path) || ! is_file($path) || ! is_readable($path) || ! is_writable($path)) {
            throw new RuntimeException('Invalid or inaccessible .env file.');
        }

        if (DIRECTORY_SEPARATOR !== '\\') {
            $permissions = fileperms($path);

            if (
                $permissions === false
                || ($permissions & 0444) === 0
                || ($permissions & 0222) === 0
            ) {
                throw new RuntimeException('Invalid or inaccessible .env file.');
            }
        }

        if (realpath($path) === false || realpath(dirname($path)) === false) {
            throw new RuntimeException('Invalid .env file path.');
        }
    }

    private function writeTemporary(string $directory, string $content, int $mode): string
    {
        $path = tempnam($directory, '.encrypted-env-');

        if ($path === false) {
            throw new RuntimeException('Unable to create temporary environment file.');
        }

        try {
            $this->writeSecureFile($path, $content, $mode);
        } catch (\Throwable $exception) {
            if (is_file($path)) {
                unlink($path);
            }

            throw $exception;
        }

        return $path;
    }

    private function writeBackup(string $path, string $content, string $operation): string
    {
        $operation = preg_replace('/[^a-z0-9-]/i', '-', $operation) ?? 'update';
        $directory = dirname($path);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $backup = $path . '.encrypted-env-' . $operation . '-'
                . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.bak';
            $handle = @fopen($backup, 'x');

            if ($handle === false) {
                continue;
            }

            fclose($handle);

            try {
                $this->writeSecureFile($backup, $content, 0600);
            } catch (\Throwable $exception) {
                unlink($backup);
                throw $exception;
            }

            return $backup;
        }

        throw new RuntimeException('Unable to create a unique environment backup.');
    }

    private function writeSecureFile(string $path, string $content, int $mode): void
    {
        if (DIRECTORY_SEPARATOR !== '\\' && ! chmod($path, 0600)) {
            throw new RuntimeException('Unable to secure environment file.');
        }

        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Unable to open environment file for writing.');
        }

        try {
            $written = 0;
            $length = strlen($content);

            while ($written < $length) {
                $bytes = fwrite($handle, substr($content, $written));

                if ($bytes === false || $bytes === 0) {
                    throw new RuntimeException('Unable to write environment file.');
                }

                $written += $bytes;
            }

            if (! fflush($handle) || (function_exists('fsync') && ! fsync($handle))) {
                throw new RuntimeException('Unable to flush environment file to disk.');
            }
        } finally {
            fclose($handle);
        }

        if (DIRECTORY_SEPARATOR !== '\\' && ! chmod($path, $mode)) {
            throw new RuntimeException('Unable to set environment file permissions.');
        }
    }
}
