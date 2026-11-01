<?php

declare(strict_types=1);

use EncryptedEnv\Installer\CodeIgniterInstaller;
use PHPUnit\Framework\TestCase;

final class CodeIgniterInstallerTest extends TestCase
{
    public function testFailurePreservesOriginalFiles(): void
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

        // Simule un conflit lors de la deuxième sauvegarde.
        mkdir($root . '/public/index.php.encrypted-env.bak');

        try {
            (new CodeIgniterInstaller())->install($root);
            $this->fail('An exception was expected.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString(
                'Backup already exists',
                $e->getMessage()
            );
        } finally {
            $this->assertSame($spark, file_get_contents($root . '/spark'));
            $this->assertSame($web, file_get_contents($root . '/public/index.php'));

            @unlink($root . '/spark.encrypted-env.bak');
            @unlink($root . '/spark');
            @unlink($root . '/public/index.php');
            @rmdir($root . '/public/index.php.encrypted-env.bak');
            @rmdir($root . '/public');
            @rmdir($root);
        }
    }
}
