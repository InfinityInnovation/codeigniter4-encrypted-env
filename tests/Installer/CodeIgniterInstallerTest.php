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
        $spark = str_replace(
            "exit(Boot::bootSpark(\$paths));",
            "define('APP_BOOTSTRAP_CUSTOMIZATION', true);" . PHP_EOL
                . "exit(Boot::bootSpark(\$paths));",
            $this->modernEntrypoint('bootSpark')
        );
        file_put_contents($root . '/spark', $spark);
        file_put_contents($root . '/public/index.php', $this->modernEntrypoint('bootWeb'));
        file_put_contents($root . '/index.php', $this->legacyWebEntrypoint());
        $envContents = "CI_ENVIRONMENT=testing\nDB_PASSWORD=synthetic-test-value\n";
        $keyPath = $root . '-external-master.key';
        $keyContents = "synthetic-external-key-file\n";
        file_put_contents($root . '/.env', $envContents);
        file_put_contents($keyPath, $keyContents);

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
            $installedSpark = (string) file_get_contents($root . '/spark');
            self::assertStringContainsString('APP_BOOTSTRAP_CUSTOMIZATION', $installedSpark);
            self::assertSame(
                [
                    'spark' => 'already installed',
                    'public/index.php' => 'already installed',
                    'index.php' => 'already installed',
                ],
                (new CodeIgniterInstaller())->install($root)
            );
            self::assertSame($installedSpark, file_get_contents($root . '/spark'));
            self::assertSame(
                1,
                substr_count($installedSpark, '\\EncryptedEnv\\Bootstrap\\EncryptedBoot::bootSpark')
            );
            self::assertSame($envContents, file_get_contents($root . '/.env'));
            self::assertSame($keyContents, file_get_contents($keyPath));
        } finally {
            $this->removeProject($root);
            unlink($keyPath);
        }
    }

    public function testMissingRequiredRoutingConfigurationStopsBeforeAnyEntrypointChanges(): void
    {
        $root = sys_get_temp_dir() . '/encrypted-env-' . bin2hex(random_bytes(8));
        mkdir($root . '/app/Config', 0700, true);
        mkdir($root . '/public', 0700, true);
        mkdir($root . '/vendor/codeigniter4/framework/system/Config', 0700, true);
        mkdir($root . '/vendor/codeigniter4/framework/app/Config', 0700, true);
        $spark = $this->modernEntrypoint('bootSpark');
        $web = $this->modernEntrypoint('bootWeb');
        $template = "<?php\nnamespace Config;\nclass Routing {}\n";
        file_put_contents($root . '/spark', $spark);
        file_put_contents($root . '/public/index.php', $web);
        file_put_contents(
            $root . '/vendor/codeigniter4/framework/system/Config/Services.php',
            "<?php\nuse Config\\Routing;\nconfig(Routing::class);\n"
        );
        file_put_contents(
            $root . '/vendor/codeigniter4/framework/app/Config/Routing.php',
            $template
        );

        try {
            try {
                (new CodeIgniterInstaller())->install($root);
                self::fail('An exception was expected.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('requires app/Config/Routing.php', $exception->getMessage());
                self::assertStringContainsString('No project files were changed', $exception->getMessage());
            }

            self::assertSame($spark, file_get_contents($root . '/spark'));
            self::assertSame($web, file_get_contents($root . '/public/index.php'));
            self::assertFileDoesNotExist($root . '/spark.encrypted-env.bak');
            self::assertFileDoesNotExist($root . '/public/index.php.encrypted-env.bak');
            self::assertSame(
                $template,
                file_get_contents($root . '/vendor/codeigniter4/framework/app/Config/Routing.php')
            );

            $applicationRouting = "<?php\nnamespace Config;\nclass Routing { public string \$custom = 'kept'; }\n";
            file_put_contents($root . '/app/Config/Routing.php', $applicationRouting);

            self::assertSame(
                ['spark' => 'installed', 'public/index.php' => 'installed'],
                (new CodeIgniterInstaller())->install($root)
            );
            self::assertSame(
                $applicationRouting,
                file_get_contents($root . '/app/Config/Routing.php')
            );
        } finally {
            $this->removeProject($root);
        }
    }

    public function testStandaloneInstallerWorksWithoutBootingSpark(): void
    {
        $root = sys_get_temp_dir() . '/encrypted-env-' . bin2hex(random_bytes(8));
        mkdir($root . '/public', 0700, true);
        file_put_contents($root . '/spark', $this->modernEntrypoint('bootSpark'));
        file_put_contents($root . '/public/index.php', $this->modernEntrypoint('bootWeb'));
        $binary = dirname(__DIR__, 2) . '/bin/encrypted-env';

        try {
            foreach ([['installed', 'installed'], ['already installed', 'already installed']] as $expected) {
                $process = proc_open(
                    [PHP_BINARY, $binary, 'install', '--root=' . $root],
                    [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ],
                    $pipes
                );
                self::assertIsResource($process);
                fclose($pipes[0]);
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);

                self::assertSame(0, proc_close($process), $error . $output);
                self::assertStringContainsString('spark: ' . $expected[0], $output);
                self::assertStringContainsString('public/index.php: ' . $expected[1], $output);
            }
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
        if (! is_dir($root)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($root);
    }
}
