<?php

declare(strict_types=1);

use CodeIgniter\CLI\Commands;
use EncryptedEnv\Commands\DecryptCommand;
use EncryptedEnv\Commands\DoctorCommand;
use EncryptedEnv\Commands\EncryptCommand;
use EncryptedEnv\Commands\InstallCommand;
use EncryptedEnv\Commands\KeyCheckCommand;
use EncryptedEnv\Commands\KeyGenerateCommand;
use EncryptedEnv\Commands\KeyRotateCommand;
use EncryptedEnv\Commands\RestoreCommand;
use EncryptedEnv\Commands\SecurityCheckCommand;
use EncryptedEnv\Commands\StatusCommand;
use EncryptedEnv\Commands\TestCommand;
use EncryptedEnv\Crypto\AesGcmCipher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Common.php';

defined('ENVIRONMENT') || define('ENVIRONMENT', 'testing');

final class EncryptedEnvCommandsTest extends TestCase
{
    private string $directory;
    private string $keyDirectory;
    private array $previousEnvironment = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/encrypted-env-cli-' . bin2hex(random_bytes(6));
        $this->keyDirectory = sys_get_temp_dir() . '/encrypted-env-key-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
        mkdir($this->keyDirectory, 0700, true);

        foreach (['ENCRYPTED_ENV_MASTER_KEY', 'ENCRYPTED_ENV_MASTER_KEY_FILE'] as $name) {
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

        $this->removeDirectory($this->directory);
        $this->removeDirectory($this->keyDirectory);
    }

    public function testEveryRequestedSparkCommandHasACommandClass(): void
    {
        $commands = [
            InstallCommand::class => 'encrypted-env:install',
            StatusCommand::class => 'encrypted-env:status',
            DoctorCommand::class => 'encrypted-env:doctor',
            KeyGenerateCommand::class => 'encrypted-env:key:generate',
            KeyCheckCommand::class => 'encrypted-env:key:check',
            KeyRotateCommand::class => 'encrypted-env:key:rotate',
            EncryptCommand::class => 'encrypted-env:encrypt',
            DecryptCommand::class => 'encrypted-env:decrypt',
            SecurityCheckCommand::class => 'encrypted-env:security:check',
            TestCommand::class => 'encrypted-env:test',
            RestoreCommand::class => 'encrypted-env:restore',
        ];

        foreach ($commands as $class => $name) {
            self::assertSame($name, (new ReflectionClass($class))->getDefaultProperties()['name']);
        }
    }

    public function testKeyGenerateAndCheckDoNotRevealKeyMaterial(): void
    {
        $path = $this->keyDirectory . '/generated.key';
        self::assertSame(0, $this->command(KeyGenerateCommand::class)->run([$path]));
        self::assertFileExists($path);
        $this->assertPrivatePermissions($path);

        putenv('ENCRYPTED_ENV_MASTER_KEY_FILE=' . $path);
        self::assertSame(0, $this->command(KeyCheckCommand::class)->run([]));

        $stored = trim((string) file_get_contents($path));
        self::assertSame(32, strlen((string) base64_decode($stored, true)));
    }

    public function testKeyGenerateRefusesOverwriteWithoutExplicitConfirmation(): void
    {
        $path = $this->keyDirectory . '/existing.key';
        file_put_contents($path, 'existing-data');

        self::assertSame(1, $this->command(KeyGenerateCommand::class)->run([$path]));
        self::assertSame('existing-data', file_get_contents($path));
    }

    public function testKeyGenerateOnlyOverwritesWithExplicitConfirmation(): void
    {
        $path = $this->keyDirectory . '/existing.key';
        file_put_contents($path, 'existing-data');

        self::assertSame(
            0,
            $this->command(KeyGenerateCommand::class)->run(['--path', $path, '--force'])
        );
        self::assertNotSame('existing-data', file_get_contents($path));
        self::assertSame(32, strlen((string) base64_decode(trim((string) file_get_contents($path)), true)));
        $this->assertPrivatePermissions($path);
    }

    public function testKeyCheckFailsWhenConfigurationIsAbsentOrInvalid(): void
    {
        self::assertSame(1, $this->command(KeyCheckCommand::class)->run([]));

        $path = $this->keyDirectory . '/invalid.key';
        file_put_contents($path, 'not-a-key');
        putenv('ENCRYPTED_ENV_MASTER_KEY_FILE=' . $path);

        self::assertSame(1, $this->command(KeyCheckCommand::class)->run([]));
    }

    public function testEncryptAndConfirmedDecryptUseProtectedPersistentBackups(): void
    {
        $key = random_bytes(32);
        $keyPath = $this->writeKey($key);
        putenv('ENCRYPTED_ENV_MASTER_KEY_FILE=' . $keyPath);

        $envPath = $this->directory . '/.env';
        $original = "CI_ENVIRONMENT=production\nPATH=/usr/bin\nDB_PASSWORD=private-value\n";
        file_put_contents($envPath, $original);
        chmod($envPath, 0644);

        self::assertSame(
            0,
            $this->command(EncryptCommand::class)->run(['--file', $envPath])
        );
        $encrypted = (string) file_get_contents($envPath);
        self::assertStringContainsString('CI_ENVIRONMENT=production', $encrypted);
        self::assertStringContainsString('PATH=/usr/bin', $encrypted);
        self::assertStringNotContainsString('private-value', $encrypted);
        self::assertCount(1, glob($envPath . '.encrypted-env-encrypt-*.bak') ?: []);
        $this->assertPrivatePermissions(
            (glob($envPath . '.encrypted-env-encrypt-*.bak') ?: [])[0]
        );

        self::assertSame(
            1,
            $this->command(DecryptCommand::class)->run(['--file', $envPath])
        );
        self::assertSame($encrypted, file_get_contents($envPath));

        self::assertSame(
            0,
            $this->command(DecryptCommand::class)->run(['--file', $envPath, '--yes'])
        );
        self::assertSame($original, file_get_contents($envPath));
        $this->assertPrivatePermissions($envPath);
        self::assertCount(1, glob($envPath . '.encrypted-env-decrypt-*.bak') ?: []);
    }

    public function testDecryptRefusesIncorrectKeyWithoutChangingFile(): void
    {
        $keyPath = $this->writeKey(random_bytes(32));
        putenv('ENCRYPTED_ENV_MASTER_KEY_FILE=' . $keyPath);
        $envPath = $this->directory . '/.env';
        $cipher = new AesGcmCipher(random_bytes(32));
        $contents = 'DB_PASSWORD=' . $cipher->encrypt('secret') . PHP_EOL;
        file_put_contents($envPath, $contents);

        self::assertSame(
            1,
            $this->command(DecryptCommand::class)->run(['--file', $envPath, '--yes'])
        );
        self::assertSame($contents, file_get_contents($envPath));
        self::assertSame([], glob($envPath . '.encrypted-env-decrypt-*.bak') ?: []);
    }

    public function testDecryptRefusesCorruptedCiphertextWithoutChangingFile(): void
    {
        $keyPath = $this->writeKey(random_bytes(32));
        putenv('ENCRYPTED_ENV_MASTER_KEY_FILE=' . $keyPath);
        $envPath = $this->directory . '/.env';
        $contents = "DB_PASSWORD=enc:v1:invalid-payload\n";
        file_put_contents($envPath, $contents);

        self::assertSame(
            1,
            $this->command(DecryptCommand::class)->run(['--file', $envPath, '--yes'])
        );
        self::assertSame($contents, file_get_contents($envPath));
        self::assertSame([], glob($envPath . '.encrypted-env-decrypt-*.bak') ?: []);
    }

    public function testFileCommandsRejectMissingAndUnreadableFilesWithoutCreatingBackups(): void
    {
        $keyPath = $this->writeKey(random_bytes(32));
        putenv('ENCRYPTED_ENV_MASTER_KEY_FILE=' . $keyPath);
        $missing = $this->directory . '/missing.env';

        self::assertSame(
            1,
            $this->command(EncryptCommand::class)->run(['--file', $missing])
        );
        self::assertFileDoesNotExist($missing);

        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX permission bits are not portable to Windows.');
        }

        $unreadable = $this->directory . '/unreadable.env';
        file_put_contents($unreadable, "DB_PASSWORD=secret\n");
        chmod($unreadable, 0000);

        try {
            self::assertSame(
                1,
                $this->command(EncryptCommand::class)->run(['--file', $unreadable])
            );
            self::assertSame([], glob($unreadable . '.encrypted-env-*.bak') ?: []);
        } finally {
            chmod($unreadable, 0600);
        }
    }

    public function testRestoreUsesProtectedBackupAndPreservesCurrentFile(): void
    {
        $key = random_bytes(32);
        $keyPath = $this->writeKey($key);
        putenv('ENCRYPTED_ENV_MASTER_KEY_FILE=' . $keyPath);
        $envPath = $this->directory . '/.env';
        $original = "DB_PASSWORD=old-value\n";
        file_put_contents($envPath, $original);
        self::assertSame(
            0,
            $this->command(EncryptCommand::class)->run(['--file', $envPath])
        );
        $backup = (glob($envPath . '.encrypted-env-encrypt-*.bak') ?: [])[0];
        file_put_contents($envPath, "DB_PASSWORD=changed-value\n");

        self::assertSame(
            0,
            $this->command(RestoreCommand::class)->run([
                $backup,
                '--file',
                $envPath,
                '--yes',
            ])
        );
        self::assertSame($original, file_get_contents($envPath));
        self::assertCount(1, glob($envPath . '.encrypted-env-restore-*.bak') ?: []);
        self::assertSame(
            0,
            $this->command(RestoreCommand::class)->run([
                $backup,
                '--file',
                $envPath,
                '--yes',
            ])
        );
        self::assertCount(1, glob($envPath . '.encrypted-env-restore-*.bak') ?: []);
    }

    public function testKeyRotationKeepsOldKeyAndProducesVerifiedNewKeyAndBackup(): void
    {
        $oldKey = random_bytes(32);
        $oldKeyPath = $this->writeKey($oldKey);
        putenv('ENCRYPTED_ENV_MASTER_KEY_FILE=' . $oldKeyPath);

        $envPath = $this->directory . '/.env';
        $plain = "CI_ENVIRONMENT=production\nDB_PASSWORD=rotate-me\n";
        file_put_contents($envPath, (new EncryptedEnv\Env\EnvFileTransformer(
            new AesGcmCipher($oldKey)
        ))->transform($plain, true));

        $newKeyPath = $this->keyDirectory . '/rotated.key';
        self::assertSame(
            0,
            $this->command(KeyRotateCommand::class)->run([
                '--new-key-file',
                $newKeyPath,
                '--file',
                $envPath,
                '--yes',
            ])
        );
        self::assertFileExists($oldKeyPath);
        self::assertFileExists($newKeyPath);
        self::assertCount(1, glob($envPath . '.encrypted-env-rotate-*.bak') ?: []);

        $newKey = base64_decode(trim((string) file_get_contents($newKeyPath)), true);
        self::assertIsString($newKey);
        putenv('ENCRYPTED_ENV_MASTER_KEY_FILE=' . $newKeyPath);
        $decrypted = (new EncryptedEnv\Env\EnvFileTransformer(
            new AesGcmCipher($newKey)
        ))->transform((string) file_get_contents($envPath), false);
        self::assertSame($plain, $decrypted);
    }

    public function testInterruptedRotationLeavesOriginalFileAndRemovesUncommittedNewKey(): void
    {
        $correctKey = random_bytes(32);
        $wrongKeyPath = $this->writeKey(random_bytes(32));
        putenv('ENCRYPTED_ENV_MASTER_KEY_FILE=' . $wrongKeyPath);

        $envPath = $this->directory . '/.env';
        file_put_contents(
            $envPath,
            'DB_PASSWORD=' . (new AesGcmCipher($correctKey))->encrypt('keep-me') . PHP_EOL
        );
        $original = file_get_contents($envPath);
        $newKeyPath = $this->keyDirectory . '/failed-rotation.key';

        self::assertSame(
            1,
            $this->command(KeyRotateCommand::class)->run([
                '--new-key-file',
                $newKeyPath,
                '--file',
                $envPath,
                '--yes',
            ])
        );
        self::assertSame($original, file_get_contents($envPath));
        self::assertFileDoesNotExist($newKeyPath);
        self::assertSame([], glob($envPath . '.encrypted-env-rotate-*.bak') ?: []);
    }

    public function testStatusDoctorAndSelfTestReportHealthyConfiguration(): void
    {
        $key = random_bytes(32);
        $root = $this->createProjectFixture($key);
        $keyPath = $this->writeKey($key);
        putenv('ENCRYPTED_ENV_MASTER_KEY_FILE=' . $keyPath);

        self::assertSame(
            0,
            $this->command(StatusCommand::class)->run(['--root', $root])
        );
        self::assertSame(
            0,
            $this->command(DoctorCommand::class)->run(['--root', $root])
        );
        self::assertSame(
            0,
            $this->command(TestCommand::class)->run(['--file', $root . '/.env'])
        );
    }

    public function testSecurityCheckDoesNotReportValuesAndDetectsTrackedPlaintextSecret(): void
    {
        $key = random_bytes(32);
        $root = $this->createProjectFixture($key);
        $keyPath = $this->writeKey($key);
        putenv('ENCRYPTED_ENV_MASTER_KEY_FILE=' . $keyPath);

        file_put_contents($root . '/.env', "DB_PASSWORD=plain-secret-value\n");
        exec('git -C ' . escapeshellarg($root) . ' init --quiet');
        exec('git -C ' . escapeshellarg($root) . ' add .env');

        self::assertSame(
            1,
            $this->command(SecurityCheckCommand::class)->run([
                '--root',
                $root,
                '--file',
                $root . '/.env',
            ])
        );

        file_put_contents(
            $root . '/.env',
            'DB_PASSWORD=' . (new AesGcmCipher($key))->encrypt('plain-secret-value') . PHP_EOL
        );
        exec('git -C ' . escapeshellarg($root) . ' add .env');
        self::assertSame(
            0,
            $this->command(SecurityCheckCommand::class)->run([
                '--root',
                $root,
                '--file',
                $root . '/.env',
            ])
        );
    }

    public function testInstallCommandRetainsIdempotentBootstrapInstallation(): void
    {
        $root = $this->directory . '/project';
        mkdir($root . '/public', 0700, true);
        file_put_contents(
            $root . '/spark',
            "<?php\nrequire \$paths->systemDirectory . '/Boot.php';\nexit(Boot::bootSpark(\$paths));\n"
        );
        file_put_contents(
            $root . '/public/index.php',
            "<?php\nrequire \$paths->systemDirectory . '/Boot.php';\nexit(Boot::bootWeb(\$paths));\n"
        );

        self::assertSame(
            0,
            $this->command(InstallCommand::class)->run(['--root', $root])
        );
        self::assertFileExists($root . '/spark.encrypted-env.bak');
        self::assertFileExists($root . '/public/index.php.encrypted-env.bak');
        self::assertSame(
            0,
            $this->command(InstallCommand::class)->run(['--root', $root])
        );
    }

    public function testInstallRejectsMissingProjectAndRestoreRejectsUntrustedBackup(): void
    {
        self::assertSame(
            1,
            $this->command(InstallCommand::class)->run([
                '--root',
                $this->directory . '/missing-project',
            ])
        );

        $envPath = $this->directory . '/.env';
        file_put_contents($envPath, 'APP_NAME=fixture' . PHP_EOL);
        $untrusted = $this->directory . '/untrusted.bak';
        file_put_contents($untrusted, 'APP_NAME=other' . PHP_EOL);
        chmod($untrusted, 0600);

        self::assertSame(
            1,
            $this->command(RestoreCommand::class)->run([
                $untrusted,
                '--file',
                $envPath,
                '--yes',
            ])
        );
        self::assertSame('APP_NAME=fixture' . PHP_EOL, file_get_contents($envPath));
    }

    public function testStatusDoctorAndSelfTestFailWhenKeyIsUnavailable(): void
    {
        $root = $this->createProjectFixture(random_bytes(32));

        self::assertSame(
            1,
            $this->command(StatusCommand::class)->run(['--root', $root])
        );
        self::assertSame(
            1,
            $this->command(DoctorCommand::class)->run(['--root', $root])
        );
        self::assertSame(
            1,
            $this->command(TestCommand::class)->run(['--file', $root . '/.env'])
        );
    }

    private function writeKey(string $key): string
    {
        $path = $this->keyDirectory . '/master-' . bin2hex(random_bytes(4)) . '.key';
        file_put_contents($path, base64_encode($key) . PHP_EOL);
        chmod($path, 0600);

        return $path;
    }

    private function assertPrivatePermissions(string $path): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::assertTrue(is_readable($path));
            return;
        }

        self::assertSame(0600, fileperms($path) & 0777);
    }

    private function createProjectFixture(string $key): string
    {
        $root = $this->directory . '/diagnostic-project';
        mkdir($root . '/public', 0700, true);
        mkdir($root . '/writable/logs', 0700, true);
        mkdir($root . '/vendor', 0700, true);
        file_put_contents($root . '/vendor/autoload.php', '<?php');
        file_put_contents(
            $root . '/composer.json',
            '{"require":{"codeigniter4/framework":"^4.3"}}'
        );
        file_put_contents(
            $root . '/spark',
            '<?php exit(\\EncryptedEnv\\Bootstrap\\EncryptedBoot::bootSpark($paths));'
        );
        file_put_contents(
            $root . '/public/index.php',
            '<?php exit(\\EncryptedEnv\\Bootstrap\\EncryptedBoot::bootWeb($paths));'
        );
        file_put_contents(
            $root . '/.env',
            'DB_PASSWORD=' . (new AesGcmCipher($key))->encrypt('fixture-secret') . PHP_EOL
        );
        chmod($root . '/.env', 0600);

        return $root;
    }

    private function command(string $class): object
    {
        $logger = $this->createMock(LoggerInterface::class);
        $commands = $this->getMockBuilder(Commands::class)
            ->disableOriginalConstructor()
            ->getMock();

        return new $class($logger, $commands);
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir() && ! $item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
