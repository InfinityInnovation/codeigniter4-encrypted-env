<?php

declare(strict_types=1);

namespace EncryptedEnv\Commands;

use CodeIgniter\CLI\CLI;
use EncryptedEnv\Crypto\AesGcmCipher;
use EncryptedEnv\Env\EnvFileTransformer;
use RuntimeException;

final class TestCommand extends EncryptedEnvCommand
{
    protected $name = 'encrypted-env:test';
    protected $description = 'Test key resolution and encrypted environment integrity';

    protected $options = [
        '--file' => 'Path to the .env file (defaults to the project .env).',
    ];

    public function run(array $params)
    {
        return $this->executeSafely(function () use ($params): int {
            $key = $this->masterKey();
            $cipher = new AesGcmCipher($key);
            $probe = 'encrypted-env-self-test-' . bin2hex(random_bytes(8));
            $encrypted = $cipher->encrypt($probe);

            if ($cipher->decrypt($encrypted) !== $probe) {
                throw new RuntimeException('Cryptographic self-test failed.');
            }

            CLI::write('[OK] Master key resolution and AES-256-GCM round-trip.', 'green');

            $path = $this->envPath($params);

            if (! is_file($path) || ! is_readable($path)) {
                throw new RuntimeException('The .env file is missing or unreadable.');
            }

            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new RuntimeException('Unable to read .env file.');
            }

            (new EnvFileTransformer($cipher))->transform($contents, false);
            CLI::write('[OK] Encrypted .env values are valid.', 'green');

            return 0;
        }, $params);
    }
}
