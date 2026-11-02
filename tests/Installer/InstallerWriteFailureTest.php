<?php

declare(strict_types=1);

use EncryptedEnv\Installer\CodeIgniterInstaller;
use PHPUnit\Framework\TestCase;

final class InstallerWriteFailureTest extends TestCase
{
    public function testRollbackAfterWriteFailure(): void
    {
        $root = sys_get_temp_dir() . '/encrypted-env-' . bin2hex(random_bytes(8));
        mkdir($root . '/public', 0700, true);

        $spark = <<<'CODE'
<?php
require $paths->systemDirectory . '/Boot.php';
exit(Boot::bootSpark($paths));
CODE;

        $web = <<<'CODE'
<?php
require $paths->systemDirectory . '/Boot.php';
exit(Boot::bootWeb($paths));
CODE;

        file_put_contents($root . '/spark', $spark);
        file_put_contents($root . '/public/index.php', $web);

        $installer = new class extends CodeIgniterInstaller {
            private int $writes = 0;

            protected function writeFile(string $path, string $content): int|false
            {
                $this->writes++;

                if ($this->writes === 2) {
                    return false;
                }

                return parent::writeFile($path, $content);
            }
        };

        try {
            $installer->install($root);
            $this->fail('An exception was expected.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Cannot write', $e->getMessage());
        } finally {
            $this->assertSame($spark, file_get_contents($root . '/spark'));
            $this->assertSame($web, file_get_contents($root . '/public/index.php'));

            @unlink($root . '/spark.encrypted-env.bak');
            @unlink($root . '/public/index.php.encrypted-env.bak');
            @unlink($root . '/spark');
            @unlink($root . '/public/index.php');
            @rmdir($root . '/public');
            @rmdir($root);
        }
    }
}
