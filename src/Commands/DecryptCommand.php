<?php

declare(strict_types=1);

namespace EncryptedEnv\Commands;

use CodeIgniter\CLI\CLI;
use EncryptedEnv\Crypto\AesGcmCipher;
use EncryptedEnv\Env\EnvFileTransaction;
use EncryptedEnv\Env\EnvFileTransformer;

final class DecryptCommand extends EncryptedEnvCommand
{
    protected $name = 'encrypted-env:decrypt';
    protected $description = 'Decrypt .env values after explicit confirmation';

    protected $arguments = [
        'file' => 'Optional path to the .env file.',
    ];

    protected $options = [
        '--file' => 'Path to the .env file (defaults to the project .env).',
        '--yes' => 'Confirm writing plaintext secrets to the .env file.',
    ];

    public function run(array $params)
    {
        return $this->executeSafely(function () use ($params): int {
            if (! $this->confirm(
                'Decrypting writes plaintext secrets to the .env file. Continue?',
                'yes'
            )) {
                CLI::write('Decryption cancelled.', 'yellow');

                return 1;
            }

            $key = $this->masterKey();
            $transformer = new EnvFileTransformer(new AesGcmCipher($key));
            $result = (new EnvFileTransaction())->transform(
                $this->envPath($params),
                static fn (string $contents): string => $transformer->transform($contents, false),
                'decrypt',
                true,
                true
            );

            if (! $result['changed']) {
                CLI::write('No encrypted values found; file unchanged.', 'yellow');

                return 0;
            }

            CLI::write('Environment values decrypted; output permissions set to 0600.', 'yellow');
            CLI::write('Encrypted backup: ' . $result['backup']);
            CLI::write('Secret values were not displayed.');

            return 0;
        }, $params);
    }
}
