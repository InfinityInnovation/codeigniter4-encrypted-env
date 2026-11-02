<?php

declare(strict_types=1);

namespace EncryptedEnv\Commands;

use CodeIgniter\CLI\CLI;
use EncryptedEnv\Crypto\AesGcmCipher;
use EncryptedEnv\Diagnostics\ProjectInspector;
use EncryptedEnv\Env\EnvFileTransaction;
use EncryptedEnv\Env\EnvFileTransformer;
use EncryptedEnv\Env\MasterKeyFile;
use EncryptedEnv\Env\MasterKeyResolver;
use RuntimeException;

final class KeyRotateCommand extends EncryptedEnvCommand
{
    protected $name = 'encrypted-env:key:rotate';
    protected $description = 'Re-encrypt .env values with a new external master key';

    protected $arguments = [
        'new-key-file' => 'Absolute path for the newly generated external key.',
    ];

    protected $options = [
        '--new-key-file' => 'Absolute path for the newly generated external key.',
        '--file' => 'Path to the .env file (defaults to the project .env).',
        '--yes' => 'Confirm the key rotation and .env modification.',
    ];

    public function run(array $params)
    {
        return $this->executeSafely(function () use ($params): int {
            if (! $this->confirm(
                'Rotate the key and re-encrypt all eligible .env values?',
                'yes'
            )) {
                CLI::write('Key rotation cancelled.', 'yellow');

                return 1;
            }

            $oldKey = $this->masterKey();
            $positionals = array_values(array_filter(
                $params,
                static fn ($value): bool => is_string($value) && ! str_starts_with($value, '--')
            ));
            $keyPath = $this->option('new-key-file')
                ?? ($this->option('file') !== null
                    ? ($positionals[0] ?? null)
                    : ($positionals[1] ?? $positionals[0] ?? null));

            if ($this->hasOption('new-key-file') && $this->option('new-key-file') === null) {
                throw new RuntimeException(
                    'The --new-key-file option requires a valid key file path.'
                );
            }

            if (! is_string($keyPath) || $keyPath === '') {
                throw new RuntimeException('An external --new-key-file path is required.');
            }

            $path = $this->envPath($params);
            $keyPath = $this->validateExternalKeyPath($keyPath);
            $configuredKeyPath = (new MasterKeyResolver(
                $this->projectRoot()
            ))->configurationSource()['path'];

            if (
                is_string($configuredKeyPath)
                && $configuredKeyPath !== ''
                && realpath($configuredKeyPath) !== false
                && realpath($configuredKeyPath) === $keyPath
            ) {
                throw new RuntimeException('The new key path must differ from the active key file.');
            }

            if (file_exists($keyPath)) {
                throw new RuntimeException('The new key file already exists; choose a new path.');
            }

            if (! is_file($path) || ! is_readable($path) || is_link($path)) {
                throw new RuntimeException('Invalid or unreadable .env file.');
            }

            $contents = file_get_contents($path);

            if ($contents === false || (new ProjectInspector())->encryptedVariableCount($contents) === 0) {
                throw new RuntimeException('No encrypted .env values were found to rotate.');
            }

            $newKey = random_bytes(32);
            $keyFile = new MasterKeyFile();
            $keyFile->store($keyPath, $newKey);
            $createdKey = true;

            try {
                $oldTransformer = new EnvFileTransformer(new AesGcmCipher($oldKey));
                $newTransformer = new EnvFileTransformer(new AesGcmCipher($newKey));
                $transaction = new EnvFileTransaction();

                $result = $transaction->transform(
                    $path,
                    static function (string $current) use (
                        $oldTransformer,
                        $newTransformer
                    ): string {
                        $plain = $oldTransformer->transform($current, false);
                        $rotated = $newTransformer->transform($plain, true);

                        if ($newTransformer->transform($rotated, false) !== $plain) {
                            throw new RuntimeException('New key validation failed; rotation was not applied.');
                        }

                        return $rotated;
                    },
                    'rotate'
                );

                if (! $result['changed'] || $result['backup'] === null) {
                    throw new RuntimeException('No environment changes were produced by key rotation.');
                }

                $createdKey = false;
                CLI::write('Environment values re-encrypted and validated.', 'green');
                CLI::write('New key file: ' . $keyPath);
                CLI::write('Protected encrypted backup: ' . $result['backup']);
                CLI::write(
                    'Update ENCRYPTED_ENV_MASTER_KEY_FILE for CLI and web processes, '
                    . 'then verify the application before retiring the old key.',
                    'yellow'
                );

                return 0;
            } finally {
                if ($createdKey && is_file($keyPath)) {
                    unlink($keyPath);
                }
            }
        }, $params);
    }
}
