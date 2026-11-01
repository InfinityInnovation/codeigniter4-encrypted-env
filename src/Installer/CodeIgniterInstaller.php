<?php

declare(strict_types=1);

namespace EncryptedEnv\Installer;

use RuntimeException;

class CodeIgniterInstaller
{
    protected function writeFile(string $path, string $content): int|false
    {
        return file_put_contents($path, $content, LOCK_EX);
    }

    public function install(string $projectRoot): array
    {
        $results = [];
        $pending = [];

        foreach ([
            'spark' => 'bootSpark',
            'public/index.php' => 'bootWeb',
        ] as $file => $method) {
            $path = rtrim($projectRoot, '/\\') . '/' . $file;

            if (!is_file($path) || !is_readable($path) || !is_writable($path)) {
                throw new RuntimeException("File unavailable: {$file}");
            }

            $original = file_get_contents($path);

            if ($original === false) {
                throw new RuntimeException("Cannot read: {$file}");
            }

            if (str_contains($original, '\\EncryptedEnv\\Bootstrap\\EncryptedBoot::' . $method)) {
                $results[$file] = 'already installed';
                continue;
            }

            $target = 'exit(Boot::' . $method . '($paths));';

            if (substr_count($original, $target) !== 1) {
                throw new RuntimeException(
                    "Unsupported or customized bootstrap in {$file}; no changes made."
                );
            }

            $replacement = 'exit(\\EncryptedEnv\\Bootstrap\\EncryptedBoot::'
                . $method . '($paths));';

            $updated = str_replace($target, $replacement, $original);

            $autoload = $file === 'spark'
                ? "require_once __DIR__ . '/vendor/autoload.php';"
                : "require_once dirname(__DIR__) . '/vendor/autoload.php';";

            if (!str_contains($updated, $autoload)) {
                $bootLine = 'require $paths->systemDirectory . \'/Boot.php\';';

                if (substr_count($updated, $bootLine) !== 1) {
                    throw new RuntimeException(
                        "Cannot locate framework bootstrap in {$file}."
                    );
                }

                $updated = str_replace(
                    $bootLine,
                    $bootLine . PHP_EOL . $autoload,
                    $updated
                );
            }

            if ($updated === null) {
                throw new RuntimeException("Cannot update: {$file}");
            }

            $backup = $path . '.encrypted-env.bak';

            if (file_exists($backup)) {
                throw new RuntimeException("Backup already exists: {$backup}");
            }

            $pending[$file] = [
                'path' => $path,
                'backup' => $backup,
                'content' => $updated,
            ];
        }

        $createdBackups = [];

        try {
            foreach ($pending as $file => $change) {
                if (file_exists($change['backup'])) {
                    throw new RuntimeException("Backup already exists: {$file}");
                }

                if (!copy($change['path'], $change['backup'])) {
                    throw new RuntimeException("Cannot create backup: {$file}");
                }

                $createdBackups[] = $change['backup'];
            }

            foreach ($pending as $file => $change) {
                $written = $this->writeFile(
                    $change['path'],
                    $change['content']
                );

                if ($written === false || $written !== strlen($change['content'])) {
                    throw new RuntimeException("Cannot write: {$file}");
                }

                $results[$file] = 'installed';
            }
        } catch (\Throwable $exception) {
            $restoreErrors = [];

            foreach ($pending as $file => $change) {
                if (in_array($change['backup'], $createdBackups, true)) {
                    if (!copy($change['backup'], $change['path'])) {
                        $restoreErrors[] = $file;
                    }
                }
            }

            if ($restoreErrors !== []) {
                throw new RuntimeException(
                    'Installation failed; manual restoration required for: '
                    . implode(', ', $restoreErrors),
                    0,
                    $exception
                );
            }

            throw $exception;
        }

        return $results;
    }
}
