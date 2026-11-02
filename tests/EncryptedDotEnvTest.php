<?php

declare(strict_types=1);

use EncryptedEnv\Crypto\AesGcmCipher;
use EncryptedEnv\Env\EncryptedDotEnv;
use PHPUnit\Framework\Attributes\DataProvider;
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
        @unlink($this->directory . '/app/Config/EncryptedEnv.local.php');
        @rmdir($this->directory . '/app/Config');
        @rmdir($this->directory . '/app');
        @rmdir($this->directory);

        foreach ([
            'TEST_ENCRYPTED_VALUE',
            'TEST_ENCRYPTED_SECOND',
            'TEST_ENCRYPTED_NESTED',
            'TEST_ENCRYPTED_WITH_ENV_KEY',
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

    public function testLocalPhpConfigurationDecryptsWithoutWebServerEnvironmentVariables(): void
    {
        $cipher = new AesGcmCipher($this->key);
        $this->writeEnv(
            'TEST_ENCRYPTED_WITH_ENV_KEY=' . $cipher->encrypt('local-config-value') . PHP_EOL
        );
        $keyPath = $this->directory . '/master.key';
        file_put_contents($keyPath, base64_encode($this->key) . PHP_EOL);
        $configurationDirectory = $this->directory . '/app/Config';
        mkdir($configurationDirectory, 0700, true);
        file_put_contents(
            $configurationDirectory . '/EncryptedEnv.local.php',
            "<?php\n"
                . "if (! defined('ENCRYPTED_ENV_LOCAL_CONFIG_LOADER')"
                . " || realpath(\$_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {"
                . " http_response_code(404); exit; }\n"
                . 'return ' . var_export(['master_key_file' => $keyPath], true) . ';'
        );

        (new EncryptedDotEnv($this->directory))->load();

        self::assertSame('local-config-value', getenv('TEST_ENCRYPTED_WITH_ENV_KEY'));
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

    public function testPlainEnvCanLoadBeforeAnyMasterKeyHasBeenGenerated(): void
    {
        $this->writeEnv("TEST_PLAIN_VALUE=available-before-key-generation\n");

        (new EncryptedDotEnv($this->directory))->load();

        self::assertSame(
            'available-before-key-generation',
            getenv('TEST_PLAIN_VALUE')
        );
    }

    public function testEncryptedValueCannotLoadWithoutAMasterKey(): void
    {
        $cipher = new AesGcmCipher($this->key);
        $this->writeEnv(
            'TEST_ENCRYPTED_WITH_ENV_KEY=' . $cipher->encrypt('must-not-load') . PHP_EOL
        );

        try {
            (new EncryptedDotEnv($this->directory))->load();
            self::fail('An encrypted value was loaded without a master key.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'Missing ENCRYPTED_ENV_MASTER_KEY_FILE or ENCRYPTED_ENV_MASTER_KEY.',
                $exception->getMessage()
            );
        }

        self::assertFalse(getenv('TEST_ENCRYPTED_WITH_ENV_KEY'));
        self::assertArrayNotHasKey('TEST_ENCRYPTED_WITH_ENV_KEY', $_ENV);
        self::assertArrayNotHasKey('TEST_ENCRYPTED_WITH_ENV_KEY', $_SERVER);
    }

    #[DataProvider('masterKeyEnvironmentSources')]
    public function testEncryptedValueLoadsWithValidKeyFromEachEnvironmentSource(
        string $source
    ): void {
        $cipher = new AesGcmCipher($this->key);
        $this->writeEnv(
            'TEST_ENCRYPTED_WITH_ENV_KEY=' . $cipher->encrypt('decrypted-value') . PHP_EOL
        );
        $this->configureMasterKeySource($source, base64_encode($this->key));

        (new EncryptedDotEnv($this->directory))->load();

        self::assertSame('decrypted-value', getenv('TEST_ENCRYPTED_WITH_ENV_KEY'));
        self::assertSame('decrypted-value', $_ENV['TEST_ENCRYPTED_WITH_ENV_KEY'] ?? null);
        self::assertSame('decrypted-value', $_SERVER['TEST_ENCRYPTED_WITH_ENV_KEY'] ?? null);
    }

    #[DataProvider('masterKeyEnvironmentSources')]
    public function testEncryptedValueRejectsInvalidKeyFromEachEnvironmentSource(
        string $source
    ): void {
        $cipher = new AesGcmCipher($this->key);
        $this->writeEnv(
            'TEST_ENCRYPTED_WITH_ENV_KEY=' . $cipher->encrypt('must-not-load') . PHP_EOL
        );
        $this->configureMasterKeySource($source, 'invalid-base64%%%');

        try {
            (new EncryptedDotEnv($this->directory))->load();
            self::fail('An encrypted value was loaded with an invalid master key.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'Invalid master key',
                $exception->getMessage()
            );
        }

        self::assertFalse(getenv('TEST_ENCRYPTED_WITH_ENV_KEY'));
        self::assertArrayNotHasKey('TEST_ENCRYPTED_WITH_ENV_KEY', $_ENV);
        self::assertArrayNotHasKey('TEST_ENCRYPTED_WITH_ENV_KEY', $_SERVER);
    }

    public function testEncryptedValueRejectsAKeyWithTheWrongDecodedLength(): void
    {
        $cipher = new AesGcmCipher($this->key);
        $this->writeEnv(
            'TEST_ENCRYPTED_WITH_ENV_KEY=' . $cipher->encrypt('must-not-load') . PHP_EOL
        );
        $this->configureMasterKeySource('getenv', base64_encode(random_bytes(31)));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exactly 32 bytes');

        (new EncryptedDotEnv($this->directory))->load();
    }

    #[DataProvider('invalidServerKeySources')]
    public function testPlainEnvRejectsAnInvalidConfiguredKeyFromSuperglobal(
        string $source
    ): void {
        $this->writeEnv("TEST_PLAIN_VALUE=must-not-load\n");
        $this->configureMasterKeySource($source, 'invalid-base64%%%');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid master key');

        new EncryptedDotEnv($this->directory);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidServerKeySources(): array
    {
        return [
            '$_ENV only' => ['_ENV'],
            '$_SERVER only' => ['_SERVER'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function masterKeyEnvironmentSources(): array
    {
        return [
            'getenv()' => ['getenv'],
            '$_ENV' => ['_ENV'],
            '$_SERVER' => ['_SERVER'],
        ];
    }

    private function configureMasterKeySource(string $source, string $value): void
    {
        switch ($source) {
            case 'getenv':
                putenv('ENCRYPTED_ENV_MASTER_KEY=' . $value);
                break;

            case '_ENV':
                $_ENV['ENCRYPTED_ENV_MASTER_KEY'] = $value;
                break;

            case '_SERVER':
                $_SERVER['ENCRYPTED_ENV_MASTER_KEY'] = $value;
                break;

            default:
                self::fail('Unsupported master-key environment source.');
        }
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
