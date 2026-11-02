<?php

declare(strict_types=1);

use EncryptedEnv\Installer\CodeIgniterInstaller;
use PHPUnit\Framework\TestCase;

final class CodeIgniterInstallerTest extends TestCase
{
    public function testInstallsRootIndexWhenPublicIndexDoesNotExist(): void
    {
        $root = sys_get_temp_dir() . '/encrypted-env-' . bin2hex(random_bytes(8));
        mkdir($root, 0700, true);
        $spark = $this->modernEntrypoint('bootSpark');
        $web = $this->modernEntrypoint('bootWeb');
        file_put_contents($root . '/spark', $spark);
        file_put_contents($root . '/index.php', $web);

        try {
            $installer = new CodeIgniterInstaller();

            self::assertSame(
                ['spark' => 'installed', 'index.php' => 'installed'],
                $installer->install($root)
            );
            self::assertFileExists($root . '/spark.encrypted-env.bak');
            self::assertFileExists($root . '/index.php.encrypted-env.bak');
            self::assertStringContainsString(
                '\\EncryptedEnv\\Bootstrap\\EncryptedBoot::bootWeb',
                (string) file_get_contents($root . '/index.php')
            );
            self::assertSame(
                ['spark' => 'already installed', 'index.php' => 'already installed'],
                $installer->install($root)
            );
            self::assertSame($spark, file_get_contents($root . '/spark.encrypted-env.bak'));
            self::assertSame($web, file_get_contents($root . '/index.php.encrypted-env.bak'));
        } finally {
            $this->removeProject($root);
        }
    }

    public function testInstallsRecognizedPublicAndRootWebEntrypoints(): void
    {
        $root = sys_get_temp_dir() . '/encrypted-env-' . bin2hex(random_bytes(8));
        mkdir($root . '/public', 0700, true);
        file_put_contents($root . '/spark', $this->modernEntrypoint('bootSpark'));
        file_put_contents($root . '/public/index.php', $this->modernEntrypoint('bootWeb'));
        file_put_contents($root . '/index.php', $this->legacyWebEntrypoint());

        try {
            $results = (new CodeIgniterInstaller())->install($root);

            self::assertSame(
                [
                    'spark' => 'installed',
                    'public/index.php' => 'installed',
                    'index.php' => 'installed',
                ],
                $results
            );
            self::assertFileExists($root . '/public/index.php.encrypted-env.bak');
            self::assertFileExists($root . '/index.php.encrypted-env.bak');
        } finally {
            $this->removeProject($root);
        }
    }

    public function testRejectsProjectWithoutSupportedWebEntrypoint(): void
    {
        $root = sys_get_temp_dir() . '/encrypted-env-' . bin2hex(random_bytes(8));
        mkdir($root . '/public', 0700, true);
        file_put_contents($root . '/spark', $this->modernEntrypoint('bootSpark'));
        file_put_contents($root . '/public/index.php', "<?php echo 'not a framework bootstrap';");

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('No supported CodeIgniter web bootstrap');

            (new CodeIgniterInstaller())->install($root);
        } finally {
            $this->removeProject($root);
        }
    }

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

    private function modernEntrypoint(string $method): string
    {
        return "<?php\nrequire \$paths->systemDirectory . '/Boot.php';\n"
            . "exit(Boot::{$method}(\$paths));\n";
    }

    private function legacyWebEntrypoint(): string
    {
        return "<?php\n(new CodeIgniter\\Config\\DotEnv(ROOTPATH))->load();\n";
    }

    private function removeProject(string $root): void
    {
        foreach ([
            '/spark.encrypted-env.bak',
            '/public/index.php.encrypted-env.bak',
            '/index.php.encrypted-env.bak',
            '/spark',
            '/public/index.php',
            '/public',
            '/index.php',
            '',
        ] as $path) {
            $target = $root . $path;

            if (is_dir($target)) {
                rmdir($target);
            } elseif (file_exists($target)) {
                unlink($target);
            }
        }
    }
}
