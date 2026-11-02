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
        $entrypoints = ['spark' => 'bootSpark'];

        foreach ($this->webEntrypoints($projectRoot) as $file) {
            $entrypoints[$file] = 'bootWeb';
        }

        foreach ($entrypoints as $file => $method) {
            $path = rtrim($projectRoot, '/\\') . '/' . $file;

            if (!is_file($path) || !is_readable($path) || !is_writable($path)) {
                throw new RuntimeException("File unavailable: {$file}");
            }

            $original = file_get_contents($path);

            if ($original === false) {
                throw new RuntimeException("Cannot read: {$file}");
            }

            if (
                str_contains($original, '\\EncryptedEnv\\Bootstrap\\EncryptedBoot::' . $method)
                || str_contains($original, '\\EncryptedEnv\\Env\\EncryptedDotEnv')
            ) {
                $results[$file] = 'already installed';
                continue;
            }

            $target = 'exit(Boot::' . $method . '($paths));';

            $autoload = $file === 'spark'
                ? "require_once __DIR__ . '/vendor/autoload.php';"
                : $this->webAutoloadStatement($file);

            if (substr_count($original, $target) === 1) {
                $replacement = 'exit(\\EncryptedEnv\\Bootstrap\\EncryptedBoot::'
                    . $method . '($paths));';
                $bootClassPath = 'vendor/infinityinnovationsdev/codeigniter4-encrypted-env'
                    . '/src/Bootstrap/EncryptedBoot.php';
                $bootClass = $file === 'spark'
                    ? "require_once __DIR__ . '/{$bootClassPath}';"
                    : str_replace(
                        '/vendor/autoload.php',
                        '/' . $bootClassPath,
                        $autoload
                    );

                $updated = str_replace($target, $replacement, $original);

                if (!str_contains($updated, $bootClass)) {
                    $bootLine = 'require $paths->systemDirectory . \'/Boot.php\';';

                    if (substr_count($updated, $bootLine) !== 1) {
                        throw new RuntimeException(
                            "Cannot locate framework bootstrap in {$file}."
                        );
                    }

                    $updated = str_replace(
                        $bootLine,
                        $bootLine . PHP_EOL . $bootClass,
                        $updated
                    );
                }
            } else {
                $legacyTarget = '(new CodeIgniter\\Config\\DotEnv(ROOTPATH))->load();';

                if (substr_count($original, $legacyTarget) !== 1) {
                    throw new RuntimeException(
                        "Unsupported or customized bootstrap in {$file}; no changes made."
                    );
                }

                $legacyReplacement = '(new \\EncryptedEnv\\Env\\EncryptedDotEnv(ROOTPATH))->load();';

                if (!str_contains($original, $autoload)) {
                    $legacyReplacement = $autoload . PHP_EOL . $legacyReplacement;
                }

                $updated = str_replace(
                    $legacyTarget,
                    $legacyReplacement,
                    $original
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

    /**
     * @return list<string>
     */
    private function webEntrypoints(string $projectRoot): array
    {
        $entrypoints = [];
        $foundWebFile = false;

        foreach (['public/index.php', 'index.php'] as $file) {
            $path = rtrim($projectRoot, '/\\') . '/' . $file;

            if (!is_file($path)) {
                continue;
            }

            $foundWebFile = true;
            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new RuntimeException("Cannot read: {$file}");
            }

            if (
                str_contains($contents, 'exit(Boot::bootWeb($paths));')
                || str_contains(
                    $contents,
                    '(new CodeIgniter\\Config\\DotEnv(ROOTPATH))->load();'
                )
                || str_contains(
                    $contents,
                    '\\EncryptedEnv\\Bootstrap\\EncryptedBoot::bootWeb'
                )
                || str_contains($contents, '\\EncryptedEnv\\Env\\EncryptedDotEnv')
            ) {
                $entrypoints[] = $file;
            }
        }

        if ($entrypoints === []) {
            $reason = $foundWebFile
                ? 'No supported CodeIgniter web bootstrap found in public/index.php or index.php.'
                : 'No web entrypoint found in public/index.php or index.php.';

            throw new RuntimeException($reason);
        }

        return $entrypoints;
    }

    private function webAutoloadStatement(string $file): string
    {
        return $file === 'public/index.php'
            ? "require_once dirname(__DIR__) . '/vendor/autoload.php';"
            : "require_once __DIR__ . '/vendor/autoload.php';";
    }
}
