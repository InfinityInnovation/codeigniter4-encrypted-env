<?php

declare(strict_types=1);

use EncryptedEnv\Crypto\AesGcmCipher;
use EncryptedEnv\Env\EnvFileManager;
use EncryptedEnv\Env\EnvFileTransformer;
use PHPUnit\Framework\TestCase;

final class EnvFileManagerTest extends TestCase
{
    public function testEncryptDecryptAndBackup(): void
    {
        $directory = sys_get_temp_dir() . '/encrypted-env-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));

        $path = $directory . '/.env';
        $original = "APP_NAME=Demo\nDB_PASSWORD=secret123\n";
        file_put_contents($path, $original);
        chmod($path, 0600);

        $manager = new EnvFileManager(
            new EnvFileTransformer(
                new AesGcmCipher(random_bytes(32))
            )
        );

        try {
            $manager->process($path, true);

            $encrypted = file_get_contents($path);

            self::assertStringContainsString('enc:v1:', $encrypted);
            self::assertStringNotContainsString('secret123', $encrypted);
            if (DIRECTORY_SEPARATOR !== '\\') {
                self::assertSame(0600, fileperms($path) & 0777);
            }

            $backups = glob($path . '.encrypted-env-encrypt-*.bak');
            self::assertCount(1, $backups);
            if (DIRECTORY_SEPARATOR !== '\\') {
                self::assertSame(0600, fileperms($backups[0]) & 0777);
            }

            $manager->process($path, false);

            self::assertSame($original, file_get_contents($path));
            if (DIRECTORY_SEPARATOR !== '\\') {
                self::assertSame(0600, fileperms($path) & 0777);
            }
            self::assertCount(1, glob($path . '.encrypted-env-decrypt-*.bak') ?: []);
        } finally {
            foreach (glob($directory . '/.*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }

            rmdir($directory);
        }
    }
}
