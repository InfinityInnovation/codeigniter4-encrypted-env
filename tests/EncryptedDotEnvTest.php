<?php

declare(strict_types=1);

use EncryptedEnv\Crypto\AesGcmCipher;
use EncryptedEnv\Env\EncryptedDotEnv;
use PHPUnit\Framework\TestCase;

final class EncryptedDotEnvTest extends TestCase
{
    private string $directory;
    private string $key;
    private array $previousKeyConfiguration = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            . '/encrypted-env-' . bin2hex(random_bytes(8));

        mkdir($this->directory, 0700);

        $this->key = random_bytes(32);

        foreach ([
            'ENCRYPTED_ENV_MASTER_KEY',
            'ENCRYPTED_ENV_MASTER_KEY_FILE',
        ] as $name) {
            $this->previousKeyConfiguration[$name] = [
                'process' => getenv($name),
                'server' => $_SERVER[$name] ?? null,
                'env' => $_ENV[$name] ?? null,
            ];
            putenv($name);
            unset($_SERVER[$name], $_ENV[$name]);
        }
    }

    protected function tearDown(): void
    {
        @unlink($this->directory . '/.env');
        @unlink($this->directory . '/master.key');
        @rmdir($this->directory);

        foreach ([
            'TEST_ENCRYPTED_VALUE',
            'TEST_ENCRYPTED_SECOND',
            'TEST_ENCRYPTED_NESTED',
            'TEST_PLAIN_VALUE',
            'TEST_SYSTEM_PRIORITY',
        ] as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }

        foreach ($this->previousKeyConfiguration as $name => $values) {
            if ($values['process'] === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $values['process']);
            }

            if ($values['server'] === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $values['server'];
            }

            if ($values['env'] === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $values['env'];
            }
        }
    }

    private function writeEnv(string $content): void
    {
        file_put_contents(
            $this->directory . '/.env',
            $content
        );
    }

    public function testEncryptedValueIsDecrypted(): void
    {
        $cipher = new AesGcmCipher($this->key);

        $encrypted = $cipher->encrypt('secret123');

        $this->writeEnv(
            "TEST_ENCRYPTED_VALUE={$encrypted}\n"
        );

        $loader = new EncryptedDotEnv(
            $this->directory,
            base64_encode($this->key)
        );

        $loader->load();

        self::assertSame(
            'secret123',
            getenv('TEST_ENCRYPTED_VALUE')
        );
    }

    public function testFileKeyDecryptsMultipleAndNestedVariablesInExistingFormat(): void
    {
        $cipher = new AesGcmCipher($this->key);
        $this->writeEnv(
            'TEST_ENCRYPTED_VALUE=' . $cipher->encrypt('secret123') . PHP_EOL
            . 'TEST_ENCRYPTED_SECOND=' . $cipher->encrypt('another-secret') . PHP_EOL
            . 'TEST_ENCRYPTED_NESTED=${TEST_ENCRYPTED_VALUE}' . PHP_EOL
        );
        $keyPath = $this->directory . '/master.key';
        file_put_contents($keyPath, base64_encode($this->key) . PHP_EOL);

        putenv('ENCRYPTED_ENV_MASTER_KEY_FILE=' . $keyPath);
        putenv('ENCRYPTED_ENV_MASTER_KEY=' . base64_encode(random_bytes(32)));

        (new EncryptedDotEnv($this->directory))->load();

        self::assertSame('secret123', getenv('TEST_ENCRYPTED_VALUE'));
        self::assertSame('another-secret', getenv('TEST_ENCRYPTED_SECOND'));
        self::assertSame('secret123', getenv('TEST_ENCRYPTED_NESTED'));
    }

    public function testPlainValueIsPreserved(): void
    {
        $this->writeEnv(
            "TEST_PLAIN_VALUE=hello\n"
        );

        $loader = new EncryptedDotEnv(
            $this->directory,
            base64_encode($this->key)
        );

        $loader->load();

        self::assertSame(
            'hello',
            getenv('TEST_PLAIN_VALUE')
        );
    }

    public function testSystemVariableHasPriority(): void
    {
        putenv('TEST_SYSTEM_PRIORITY=system-value');

        $this->writeEnv(
            "TEST_SYSTEM_PRIORITY=file-value\n"
        );

        $loader = new EncryptedDotEnv(
            $this->directory,
            base64_encode($this->key)
        );

        $loader->load();

        self::assertSame(
            'system-value',
            getenv('TEST_SYSTEM_PRIORITY')
        );
    }
}
