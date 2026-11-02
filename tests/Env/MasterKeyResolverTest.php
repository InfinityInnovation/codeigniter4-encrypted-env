<?php

declare(strict_types=1);

use EncryptedEnv\Env\MasterKeyResolver;
use PHPUnit\Framework\TestCase;

final class MasterKeyResolverTest extends TestCase
{
    private string $directory;
    private array $previousEnvironment = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            . '/encrypted-env-key-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);

        foreach ([
            'ENCRYPTED_ENV_MASTER_KEY',
            'ENCRYPTED_ENV_MASTER_KEY_FILE',
        ] as $name) {
            $this->previousEnvironment[$name] = [
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
        @chmod($this->directory . '/master.key', 0600);
        @unlink($this->directory . '/master.key');
        @rmdir($this->directory);

        foreach ($this->previousEnvironment as $name => $values) {
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

    public function testLoadsKeyFromExternalFileAndTrimsOnlyOuterWhitespace(): void
    {
        $key = random_bytes(32);
        file_put_contents(
            $this->directory . '/master.key',
            " \t" . base64_encode($key) . "\r\n "
        );
        putenv('ENCRYPTED_ENV_MASTER_KEY_FILE=' . $this->directory . '/master.key');

        self::assertSame($key, (new MasterKeyResolver())->resolve());
    }

    public function testLoadsKeyFromDirectEnvironmentVariable(): void
    {
        $key = random_bytes(32);
        putenv('ENCRYPTED_ENV_MASTER_KEY=' . base64_encode($key));

        self::assertSame($key, (new MasterKeyResolver())->resolve());
    }

    public function testFileConfigurationTakesPriorityOverDirectEnvironmentKey(): void
    {
        $fileKey = random_bytes(32);
        $environmentKey = random_bytes(32);
        file_put_contents(
            $this->directory . '/master.key',
            base64_encode($fileKey) . PHP_EOL
        );
        putenv('ENCRYPTED_ENV_MASTER_KEY_FILE=' . $this->directory . '/master.key');
        putenv('ENCRYPTED_ENV_MASTER_KEY=' . base64_encode($environmentKey));

        self::assertSame($fileKey, (new MasterKeyResolver())->resolve());
    }

    public function testExplicitConstructorKeyRemainsSupported(): void
    {
        $key = random_bytes(32);

        self::assertSame(
            $key,
            (new MasterKeyResolver())->resolve(base64_encode($key))
        );
    }

    public function testConfiguredFileTakesPriorityOverExplicitConstructorKey(): void
    {
        $fileKey = random_bytes(32);
        file_put_contents(
            $this->directory . '/master.key',
            base64_encode($fileKey)
        );
        putenv('ENCRYPTED_ENV_MASTER_KEY_FILE=' . $this->directory . '/master.key');

        self::assertSame(
            $fileKey,
            (new MasterKeyResolver())->resolve(base64_encode(random_bytes(32)))
        );
    }

    public function testInvalidConfiguredFileDoesNotFallBackToExplicitConstructorKey(): void
    {
        putenv('ENCRYPTED_ENV_MASTER_KEY_FILE=' . $this->directory . '/missing.key');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ENCRYPTED_ENV_MASTER_KEY_FILE');

        (new MasterKeyResolver())->resolve(base64_encode(random_bytes(32)));
    }

    public function testMissingConfiguredFileDoesNotFallBackToDirectKey(): void
    {
        putenv('ENCRYPTED_ENV_MASTER_KEY_FILE=' . $this->directory . '/missing.key');
        putenv('ENCRYPTED_ENV_MASTER_KEY=' . base64_encode(random_bytes(32)));

        try {
            (new MasterKeyResolver())->resolve();
            self::fail('An exception was expected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'ENCRYPTED_ENV_MASTER_KEY_FILE',
                $exception->getMessage()
            );
        }
    }

    public function testFileWithoutReadPermissionIsRejectedEvenWhenRunningAsRoot(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX permission bits are not portable to Windows.');
        }

        $path = $this->directory . '/master.key';
        file_put_contents($path, base64_encode(random_bytes(32)));
        chmod($path, 0000);
        clearstatcache(true, $path);
        self::assertSame(0, fileperms($path) & 0444);
        putenv('ENCRYPTED_ENV_MASTER_KEY_FILE=' . $path);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ENCRYPTED_ENV_MASTER_KEY_FILE');

        (new MasterKeyResolver())->resolve();
    }

    public function testEmptyFileIsRejected(): void
    {
        file_put_contents($this->directory . '/master.key', '');
        putenv('ENCRYPTED_ENV_MASTER_KEY_FILE=' . $this->directory . '/master.key');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid master key');

        (new MasterKeyResolver())->resolve();
    }

    public function testInvalidBase64FileIsRejected(): void
    {
        file_put_contents($this->directory . '/master.key', 'not-base64%%%');
        putenv('ENCRYPTED_ENV_MASTER_KEY_FILE=' . $this->directory . '/master.key');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid master key');

        (new MasterKeyResolver())->resolve();
    }

    public function testDecodedKeyWithIncorrectLengthIsRejected(): void
    {
        file_put_contents(
            $this->directory . '/master.key',
            base64_encode(random_bytes(31))
        );
        putenv('ENCRYPTED_ENV_MASTER_KEY_FILE=' . $this->directory . '/master.key');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exactly 32 bytes');

        (new MasterKeyResolver())->resolve();
    }

    public function testMissingBothConfigurationsIsExplicit(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Missing ENCRYPTED_ENV_MASTER_KEY_FILE or ENCRYPTED_ENV_MASTER_KEY.'
        );

        (new MasterKeyResolver())->resolve();
    }

    public function testServerEnvironmentConfigurationIsSupported(): void
    {
        $key = random_bytes(32);
        $_SERVER['ENCRYPTED_ENV_MASTER_KEY'] = base64_encode($key);

        self::assertSame($key, (new MasterKeyResolver())->resolve());
    }
}
