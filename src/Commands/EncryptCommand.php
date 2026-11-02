<?php

declare(strict_types=1);

namespace EncryptedEnv\Commands;

use CodeIgniter\CLI\CLI;
use EncryptedEnv\Crypto\AesGcmCipher;
use EncryptedEnv\Diagnostics\ProjectInspector;
use EncryptedEnv\Env\EnvFileTransaction;
use EncryptedEnv\Env\EnvFileTransformer;

final class EncryptCommand extends EncryptedEnvCommand
{
    protected $name = 'encrypted-env:encrypt';
    protected $description = 'Encrypt eligible values in the .env file';

    protected $arguments = [
        'file' => 'Optional path to the .env file.',
    ];

    protected $options = [
        '--file' => 'Path to the .env file (defaults to the project .env).',
    ];

    public function run(array $params)
    {
        return $this->executeSafely(function () use ($params): int {
            $key = $this->masterKey();
            $transformer = new EnvFileTransformer(new AesGcmCipher($key));
            $path = $this->envPath($params);
            $result = (new EnvFileTransaction())->transform(
                $path,
                static fn (string $contents): string => $transformer->transform($contents, true),
                'encrypt'
            );

            if (! $result['changed']) {
                CLI::write('No eligible plaintext values found; file unchanged.', 'yellow');

                return 0;
            }

            CLI::write('Environment values encrypted; no secret values were displayed.', 'green');
            CLI::write('Protected backup: ' . $result['backup']);

            return 0;
        }, $params);
    }
}
