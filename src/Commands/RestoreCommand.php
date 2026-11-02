<?php

declare(strict_types=1);

namespace EncryptedEnv\Commands;

use CodeIgniter\CLI\CLI;
use EncryptedEnv\Env\EnvFileTransaction;
use RuntimeException;

final class RestoreCommand extends EncryptedEnvCommand
{
    protected $name = 'encrypted-env:restore';
    protected $description = 'Restore a .env file from a protected package backup';

    protected $arguments = [
        'backup' => 'Path to the protected encrypted-env backup.',
    ];

    protected $options = [
        '--file' => 'Path to the destination .env file.',
        '--yes' => 'Confirm replacing the destination .env file.',
    ];

    public function run(array $params)
    {
        return $this->executeSafely(function () use ($params): int {
            $backup = null;

            foreach ($params as $parameter) {
                if (is_string($parameter) && ! str_starts_with($parameter, '--')) {
                    $backup = $parameter;
                    break;
                }
            }

            if (! is_string($backup) || $backup === '') {
                throw new RuntimeException('A backup file path is required.');
            }

            if (! $this->isAbsolutePath($backup)) {
                $backup = $this->projectRoot() . DIRECTORY_SEPARATOR . $backup;
            }

            if (! $this->confirm('Restore the environment file from this backup?', 'yes')) {
                CLI::write('Restore cancelled.', 'yellow');

                return 1;
            }

            $currentBackup = (new EnvFileTransaction())->restore(
                $this->envPath($params),
                $backup
            );

            if ($currentBackup === null) {
                CLI::write('Environment file already matches the backup.', 'yellow');

                return 0;
            }

            CLI::write('Environment file restored.', 'green');
            CLI::write('Protected backup of the replaced file: ' . $currentBackup);

            return 0;
        }, $params);
    }
}
