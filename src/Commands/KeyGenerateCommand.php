<?php

declare(strict_types=1);

namespace EncryptedEnv\Commands;

use CodeIgniter\CLI\CLI;
use EncryptedEnv\Env\MasterKeyFile;
use RuntimeException;

final class KeyGenerateCommand extends EncryptedEnvCommand
{
    protected $name = 'encrypted-env:key:generate';
    protected $description = 'Generate a secure external 256-bit master key';

    protected $arguments = [
        'path' => 'Absolute path to the new external key file.',
    ];

    protected $options = [
        '--path' => 'Absolute path to the new external key file.',
        '--force' => 'Confirm replacing an existing key file.',
    ];

    public function run(array $params)
    {
        return $this->executeSafely(function () use ($params): int {
            $path = $this->option('path') ?? ($params[0] ?? null);

            if ($this->hasOption('path') && $this->option('path') === null) {
                throw new RuntimeException('The --path option requires a valid key file path.');
            }

            if ($path === null && function_exists('stream_isatty') && stream_isatty(STDIN)) {
                $path = CLI::prompt('Absolute path for the external master key');
            }

            if (! is_string($path) || $path === '') {
                throw new RuntimeException('An external key file path is required.');
            }

            $path = $this->validateExternalKeyPath($path);
            $exists = file_exists($path);

            if ($exists && ! $this->confirm(
                'Replace the existing key? Encrypted values may become unrecoverable.',
                'force'
            )) {
                CLI::write('Key generation cancelled.', 'yellow');

                return 1;
            }

            $created = (new MasterKeyFile())->create($path, $exists);
            CLI::write('Master key generated at: ' . $created, 'green');
            CLI::write('The key contents were not displayed.');

            return 0;
        }, $params);
    }

}
