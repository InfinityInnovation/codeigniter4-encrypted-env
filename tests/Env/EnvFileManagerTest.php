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
            self::assertSame(0600, fileperms($path) & 0777);

            $backups = glob($directory . '/.encrypted-env-backup-*');
            self::assertCount(0, $backups);

            $manager->process($path, false);

            self::assertSame($original, file_get_contents($path));
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
