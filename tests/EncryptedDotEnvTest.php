<?php

declare(strict_types=1);

use EncryptedEnv\Crypto\AesGcmCipher;
use EncryptedEnv\Env\EncryptedDotEnv;
use PHPUnit\Framework\TestCase;

final class EncryptedDotEnvTest extends TestCase
{
    private string $directory;
    private string $key;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            . '/encrypted-env-' . bin2hex(random_bytes(8));

        mkdir($this->directory, 0700);

        $this->key = random_bytes(32);
    }

    protected function tearDown(): void
    {
        @unlink($this->directory . '/.env');
        @rmdir($this->directory);

        foreach ([
            'TEST_ENCRYPTED_VALUE',
            'TEST_PLAIN_VALUE',
            'TEST_SYSTEM_PRIORITY',
        ] as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
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
