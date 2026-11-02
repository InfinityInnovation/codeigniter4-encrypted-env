<?php

declare(strict_types=1);

namespace EncryptedEnv\Env;

use RuntimeException;

final class EnvFileManager
{
    public function __construct(
        private readonly EnvFileTransformer $transformer
    ) {
    }

    public function process(string $path, bool $encrypt = true): void
    {
        if (is_link($path) || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Invalid or unreadable .env file.');
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException('Unable to read .env file.');
        }

        $updated = $this->transformer->transform($content, $encrypt);

        if ($updated === $content) {
            return;
        }

        $permissions = fileperms($path);

        if ($permissions === false) {
            throw new RuntimeException('Unable to read file permissions.');
        }

        $mode = $permissions & 0777;
        $directory = dirname($path);

        $temporary = tempnam($directory, '.encrypted-env-');

        if ($temporary === false) {
            throw new RuntimeException('Unable to create temporary file.');
        }

        $backup = null;

        try {
            if (!chmod($temporary, 0600)) {
                throw new RuntimeException('Unable to secure temporary file.');
            }

            if (file_put_contents($temporary, $updated, LOCK_EX) !== strlen($updated)) {
                throw new RuntimeException('Unable to write temporary file.');
            }

            if (!chmod($temporary, $mode)) {
                throw new RuntimeException('Unable to restore file permissions.');
            }

            clearstatcache(true, $path);

            if (is_link($path) || !is_file($path) || file_get_contents($path) !== $content) {
                throw new RuntimeException('.env file changed during processing.');
            }

            $backup = tempnam($directory, '.encrypted-env-backup-');

            if ($backup === false) {
                throw new RuntimeException('Unable to create backup.');
            }

            if (!chmod($backup, 0600)) {
                throw new RuntimeException('Unable to secure backup.');
            }

            if (file_put_contents($backup, $content, LOCK_EX) !== strlen($content)) {
                throw new RuntimeException('Unable to write backup.');
            }

            if (!rename($temporary, $path)) {
                throw new RuntimeException('Unable to replace .env file.');
            }

            $temporary = '';
        } finally {
            if ($temporary !== '' && is_file($temporary)) {
                unlink($temporary);
            }

            // Never retain a backup containing sensitive environment values.
            if ($backup !== null && is_file($backup)) {
                unlink($backup);
            }
        }
    }
}
