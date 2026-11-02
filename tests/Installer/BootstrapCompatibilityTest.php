<?php

declare(strict_types=1);

use EncryptedEnv\Crypto\AesGcmCipher;
use EncryptedEnv\Installer\CodeIgniterInstaller;
use PHPUnit\Framework\TestCase;

final class BootstrapCompatibilityTest extends TestCase
{
    public function testLegacyCliAndWebBootstrapsDecryptBeforeApplicationUse(): void
    {
        $this->assertBootstrapsDecryptBeforeUse('legacy');
    }

    public function testModernCliAndWebBootstrapsDecryptBeforeApplicationUse(): void
    {
        $this->assertBootstrapsDecryptBeforeUse('modern');
    }

    public function testInstalledFrameworkCliAndWebEntrypointsLoadDotEnvBeforeBoot(): void
    {
        $framework = dirname(__DIR__, 2) . '/vendor/codeigniter4/framework';
        $root = sys_get_temp_dir() . '/encrypted-env-' . bin2hex(random_bytes(8));
        $key = random_bytes(32);
        $keyPath = $root . '-master.key';
        $probeValues = [
            'HTTP_PROBE_ALPHA' => 'synthetic-alpha-' . bin2hex(random_bytes(5)),
            'HTTP_PROBE_EQUALS' => 'left=middle=right',
            'HTTP_PROBE_UNICODE' => 'fictif-éà-秘密',
            'HTTP_PROBE_SPECIAL' => '!#$%&()[]{};:? two words',
            'HTTP_PROBE_EMPTY' => '',
            'HTTP_PROBE_SPACE' => 'synthetic value with spaces',
        ];

        $this->copyDirectory($framework . '/app', $root . '/app');
        $this->copyDirectory($framework . '/writable', $root . '/writable');
        mkdir($root . '/public', 0700, true);
        mkdir(
            $root . '/vendor/infinityinnovationsdev/codeigniter4-encrypted-env',
            0700,
            true
        );

        $packageSource = $root
            . '/vendor/infinityinnovationsdev/codeigniter4-encrypted-env/src';

        if (!symlink(dirname(__DIR__, 2) . '/src', $packageSource)) {
            throw new RuntimeException('Cannot link package source directory.');
        }

        if (!symlink($framework . '/system', $root . '/system')) {
            throw new RuntimeException('Cannot link the framework system directory.');
        }

        $autoloadPath = $root . '/vendor/autoload.php';

        $autoloadSource = str_replace(
            '__COMPOSER_AUTOLOAD__',
            var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true),
            <<<'PHP'
<?php
$loader = require __COMPOSER_AUTOLOAD__;
$root = dirname(__DIR__);
$loader->setPsr4('App\\', $root . '/app');
$loader->setPsr4('Config\\', $root . '/app/Config');
$loader->addClassMap([
    'Config\\Kint' => $root . '/app/Config/Kint.php',
    'App\\Controllers\\EncryptedEnvProbe' => $root . '/app/Controllers/EncryptedEnvProbe.php',
]);
return $loader;
PHP
        );

        if (file_put_contents($autoloadPath, $autoloadSource) === false) {
            throw new RuntimeException('Cannot create isolated Composer autoload.');
        }

        copy($framework . '/spark', $root . '/spark');
        copy($framework . '/public/index.php', $root . '/public/index.php');
        $cipher = new AesGcmCipher($key);
        $envContent = 'CI_ENVIRONMENT=' . $cipher->encrypt('development') . PHP_EOL;

        foreach ($probeValues as $name => $value) {
            $envContent .= $name . '=' . $cipher->encrypt($value) . PHP_EOL;
        }

        file_put_contents($root . '/.env', $envContent);
        chmod($root . '/.env', 0600);
        file_put_contents($keyPath, base64_encode($key) . PHP_EOL);
        chmod($keyPath, 0600);

        file_put_contents(
            $root . '/app/Controllers/EncryptedEnvProbe.php',
            str_replace(
                '__EXPECTED_VALUES__',
                var_export($probeValues, true),
                <<<'PHP'
<?php
namespace App\Controllers;

class EncryptedEnvProbe extends BaseController
{
    public function index()
    {
        $expectedValues = __EXPECTED_VALUES__;
        $checks = [];

        foreach ($expectedValues as $name => $expected) {
            $checks[$name] = [
                'getenv' => getenv($name) === $expected,
                'env' => function_exists('env') && \env($name) === $expected,
                '_ENV' => ($_ENV[$name] ?? null) === $expected,
                '_SERVER' => ($_SERVER[$name] ?? null) === $expected,
            ];
        }

        return $this->response->setJSON($checks);
    }
}
PHP
            )
        );
        file_put_contents(
            $root . '/app/Config/Routes.php',
            PHP_EOL . '$routes->get(\'/__encrypted-env-probe\', \'EncryptedEnvProbe::index\');'
                . PHP_EOL,
            FILE_APPEND
        );

        $previousKey = getenv('ENCRYPTED_ENV_MASTER_KEY');
        $previousKeyFile = getenv('ENCRYPTED_ENV_MASTER_KEY_FILE');
        $previousEnvironment = getenv('CI_ENVIRONMENT');
        $previousProbeVariables = [];

        foreach (array_keys($probeValues) as $name) {
            $previousProbeVariables[$name] = [
                'process' => getenv($name),
                'server' => $_SERVER[$name] ?? null,
                'env' => $_ENV[$name] ?? null,
            ];
            putenv($name);
            unset($_SERVER[$name], $_ENV[$name]);
        }

        putenv('ENCRYPTED_ENV_MASTER_KEY_FILE=' . $keyPath);
        putenv('ENCRYPTED_ENV_MASTER_KEY');
        putenv('CI_ENVIRONMENT');

        try {
            $results = (new CodeIgniterInstaller())->install($root);

            self::assertSame('installed', $results['spark']);
            self::assertSame('installed', $results['public/index.php']);

            [$exitCode, $output, $error] = $this->execute(
                $root . '/spark',
                $root
            );
            self::assertSame(0, $exitCode, $error . $output);
            foreach ($probeValues as $value) {
                if ($value !== '') {
                    self::assertStringNotContainsString($value, $output . $error);
                }
            }

            [$response, $error, $status, $serverLog] = $this->executeWeb(
                $root,
                '/__encrypted-env-probe'
            );
            self::assertSame(200, $status, $error . $serverLog);
            self::assertNotFalse($response, $error . $serverLog);
            self::assertIsArray($checks = json_decode((string) $response, true));

            foreach ($probeValues as $name => $value) {
                self::assertSame(
                    [
                        'getenv' => true,
                        'env' => true,
                        '_ENV' => true,
                        '_SERVER' => true,
                    ],
                    $checks[$name] ?? null,
                    'HTTP bootstrap did not expose a decrypted value through all environment APIs.'
                );
                if ($value !== '') {
                    self::assertStringNotContainsString($value, (string) $response);
                    self::assertStringNotContainsString($value, $serverLog);
                    self::assertStringNotContainsString($value, $error);
                }
            }
        } finally {
            if ($previousKey === false) {
                putenv('ENCRYPTED_ENV_MASTER_KEY');
            } else {
                putenv('ENCRYPTED_ENV_MASTER_KEY=' . $previousKey);
            }

            if ($previousKeyFile === false) {
                putenv('ENCRYPTED_ENV_MASTER_KEY_FILE');
            } else {
                putenv('ENCRYPTED_ENV_MASTER_KEY_FILE=' . $previousKeyFile);
            }

            if ($previousEnvironment === false) {
                putenv('CI_ENVIRONMENT');
            } else {
                putenv('CI_ENVIRONMENT=' . $previousEnvironment);
            }

            foreach ($previousProbeVariables as $name => $values) {
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

            $this->removeDirectory($root);
            unlink($keyPath);
        }
    }

    private function assertBootstrapsDecryptBeforeUse(string $style): void
    {
        $root = sys_get_temp_dir() . '/encrypted-env-' . bin2hex(random_bytes(8));
        $key = random_bytes(32);
        $expected = 'decrypted-' . bin2hex(random_bytes(4));
        $secondExpected = 'second-' . bin2hex(random_bytes(4));

        $this->createProject($root, $style, $key, $expected, $secondExpected);
        $keyPath = $root . '-master.key';
        file_put_contents($keyPath, base64_encode($key) . PHP_EOL);
        chmod($keyPath, 0600);

        $previousKey = getenv('ENCRYPTED_ENV_MASTER_KEY');
        $previousKeyFile = getenv('ENCRYPTED_ENV_MASTER_KEY_FILE');
        $probeNames = [
            'BOOTSTRAP_TEST_SECRET',
            'BOOTSTRAP_TEST_SECOND',
            'BOOTSTRAP_TEST_DERIVED',
        ];
        $previousProbeVariables = [];

        foreach ($probeNames as $name) {
            $previousProbeVariables[$name] = [
                'process' => getenv($name),
                'server' => $_SERVER[$name] ?? null,
                'env' => $_ENV[$name] ?? null,
            ];
            putenv($name);
            unset($_SERVER[$name], $_ENV[$name]);
        }

        putenv('ENCRYPTED_ENV_MASTER_KEY_FILE=' . $keyPath);
        putenv('ENCRYPTED_ENV_MASTER_KEY');

        try {
            $installer = new CodeIgniterInstaller();
            $firstInstall = $installer->install($root);
            $secondInstall = $installer->install($root);

            self::assertSame('installed', $firstInstall['spark']);
            self::assertSame('installed', $firstInstall['public/index.php']);
            self::assertSame('already installed', $secondInstall['spark']);
            self::assertSame('already installed', $secondInstall['public/index.php']);
            self::assertFileExists($root . '/spark.encrypted-env.bak');
            self::assertFileExists($root . '/public/index.php.encrypted-env.bak');

            [$exitCode, $output, $error] = $this->execute(
                $root . '/spark',
                $root
            );
            self::assertSame(0, $exitCode, $error . $output);
            self::assertSame(
                '1|1|1',
                trim($output)
            );

            [$response, $error, $status, $serverLog] = $this->executeWeb($root);
            self::assertSame(200, $status, $error . $serverLog);
            self::assertSame('1|1|1', trim((string) $response), $error . $serverLog);
            self::assertStringNotContainsString($expected, (string) $response);
            self::assertStringNotContainsString($secondExpected, (string) $response);
            self::assertStringNotContainsString($expected, $serverLog);
            self::assertStringNotContainsString($secondExpected, $serverLog);
        } finally {
            if ($previousKey === false) {
                putenv('ENCRYPTED_ENV_MASTER_KEY');
            } else {
                putenv('ENCRYPTED_ENV_MASTER_KEY=' . $previousKey);
            }

            if ($previousKeyFile === false) {
                putenv('ENCRYPTED_ENV_MASTER_KEY_FILE');
            } else {
                putenv('ENCRYPTED_ENV_MASTER_KEY_FILE=' . $previousKeyFile);
            }

            foreach ($previousProbeVariables as $name => $values) {
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

            $this->removeDirectory($root);
            unlink($keyPath);
        }
    }

    private function createProject(
        string $root,
        string $style,
        string $key,
        string $expected,
        string $secondExpected
    ): void {
        mkdir($root . '/app/Config', 0700, true);
        mkdir($root . '/public', 0700, true);
        mkdir($root . '/system/Config', 0700, true);
        mkdir(
            $root . '/vendor/infinityinnovationsdev/codeigniter4-encrypted-env',
            0700,
            true
        );

        $packageSource = $root
            . '/vendor/infinityinnovationsdev/codeigniter4-encrypted-env/src';

        if (!symlink(dirname(__DIR__, 2) . '/src', $packageSource)) {
            throw new RuntimeException('Cannot link package source directory.');
        }

        $autoloadPath = $root . '/vendor/autoload.php';

        if (!symlink(dirname(__DIR__, 2) . '/vendor/autoload.php', $autoloadPath)) {
            throw new RuntimeException('Cannot link Composer autoload.');
        }

        file_put_contents(
            $root . '/.env',
            'BOOTSTRAP_TEST_SECRET='
                . (new AesGcmCipher($key))->encrypt($expected)
                . PHP_EOL
                . 'BOOTSTRAP_TEST_SECOND='
                . (new AesGcmCipher($key))->encrypt($secondExpected)
                . PHP_EOL
                . 'BOOTSTRAP_TEST_DERIVED=${BOOTSTRAP_TEST_SECRET}'
                . PHP_EOL
        );
        file_put_contents(
            $root . '/app/Config/BootstrapProbe.php',
            '<?php return ' . var_export([
                'BOOTSTRAP_TEST_SECRET' => $expected,
                'BOOTSTRAP_TEST_SECOND' => $secondExpected,
                'BOOTSTRAP_TEST_DERIVED' => $expected,
            ], true) . ';'
        );
        file_put_contents(
            $root . '/app/Config/Paths.php',
            <<<'PHP'
<?php
namespace Config;
final class Paths
{
    public string $systemDirectory;
    public string $appDirectory;
    public ?string $envDirectory;

    public function __construct(string $root)
    {
        $this->systemDirectory = $root . '/system';
        $this->appDirectory = $root . '/app';
        $this->envDirectory = $root;
    }
}
PHP
        );
        file_put_contents(
            $root . '/system/Config/DotEnv.php',
            '<?php require_once '
                . var_export(
                    dirname(__DIR__, 2)
                        . '/vendor/codeigniter4/framework/system/Config/DotEnv.php',
                    true
                )
                . ';'
        );

        if ($style === 'legacy') {
            file_put_contents(
                $root . '/system/bootstrap.php',
                <<<'PHP'
<?php
define('ROOTPATH', dirname($paths->appDirectory) . DIRECTORY_SEPARATOR);
define('SYSTEMPATH', $paths->systemDirectory . DIRECTORY_SEPARATOR);
PHP
            );

            $spark = <<<'PHP'
<?php
require __DIR__ . '/app/Config/Paths.php';
$paths = new Config\Paths(__DIR__);
require $paths->systemDirectory . '/bootstrap.php';
require_once SYSTEMPATH . 'Config/DotEnv.php';
(new CodeIgniter\Config\DotEnv(ROOTPATH))->load();
class_exists(\EncryptedEnv\Bootstrap\EncryptedBoot::class);
$expected = require __DIR__ . '/app/Config/BootstrapProbe.php';
$checks = [];
foreach ($expected as $name => $value) {
    $checks[] = getenv($name) === $value
        && ($_ENV[$name] ?? null) === $value
        && ($_SERVER[$name] ?? null) === $value;
}
echo implode('|', array_map(static fn (bool $check): string => $check ? '1' : '0', $checks));
PHP;
            $web = <<<'PHP'
<?php
require dirname(__DIR__) . '/app/Config/Paths.php';
$paths = new Config\Paths(dirname(__DIR__));
require $paths->systemDirectory . '/bootstrap.php';
require_once SYSTEMPATH . 'Config/DotEnv.php';
(new CodeIgniter\Config\DotEnv(ROOTPATH))->load();
class_exists(\EncryptedEnv\Bootstrap\EncryptedBoot::class);
$expected = require dirname(__DIR__) . '/app/Config/BootstrapProbe.php';
$checks = [];
foreach ($expected as $name => $value) {
    $checks[] = getenv($name) === $value
        && ($_ENV[$name] ?? null) === $value
        && ($_SERVER[$name] ?? null) === $value;
}
echo implode('|', array_map(static fn (bool $check): string => $check ? '1' : '0', $checks));
PHP;
            file_put_contents($root . '/spark', $spark);
            file_put_contents($root . '/public/index.php', $web);

            return;
        }

        file_put_contents(
            $root . '/system/Boot.php',
            <<<'PHP'
<?php
namespace CodeIgniter;
use Config\Paths;
class Boot
{
    public static function bootSpark(Paths $paths): int
    {
        static::loadDotEnv($paths);
        static::outputProbe($paths);
        return 0;
    }

    public static function bootWeb(Paths $paths): int
    {
        static::loadDotEnv($paths);
        static::outputProbe($paths);
        return 0;
    }

    private static function outputProbe(Paths $paths): void
    {
        $expected = require dirname($paths->appDirectory) . '/app/Config/BootstrapProbe.php';
        $checks = [];

        foreach ($expected as $name => $value) {
            $checks[] = getenv($name) === $value
                && ($_ENV[$name] ?? null) === $value
                && ($_SERVER[$name] ?? null) === $value;
        }

        echo implode('|', array_map(static fn (bool $check): string => $check ? '1' : '0', $checks));
    }

    protected static function loadDotEnv(Paths $paths): void
    {
        require_once $paths->systemDirectory . '/Config/DotEnv.php';
        (new \CodeIgniter\Config\DotEnv($paths->envDirectory))->load();
    }
}
PHP
        );

        $spark = <<<'PHP'
<?php
require __DIR__ . '/app/Config/Paths.php';
$paths = new Config\Paths(__DIR__);
require $paths->systemDirectory . '/Boot.php';
exit(Boot::bootSpark($paths));
PHP;
        $web = <<<'PHP'
<?php
require dirname(__DIR__) . '/app/Config/Paths.php';
$paths = new Config\Paths(dirname(__DIR__));
require $paths->systemDirectory . '/Boot.php';
exit(Boot::bootWeb($paths));
PHP;

        file_put_contents($root . '/spark', $spark);
        file_put_contents($root . '/public/index.php', $web);
    }

    /**
     * @return array{int, string, string}
     */
    private function execute(string $entrypoint, string $workingDirectory): array
    {
        $process = proc_open(
            [
                PHP_BINARY,
                '-n',
                '-d',
                'display_errors=0',
                '-d',
                'display_startup_errors=0',
                $entrypoint,
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $workingDirectory
        );

        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output, $error];
    }

    /**
     * @return array{string|false, string, int|null, string}
     */
    private function executeWeb(string $workingDirectory, string $requestPath = '/'): array
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($socket, $errorMessage);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);

        self::assertIsString($address);
        $process = proc_open(
            [
                PHP_BINARY,
                '-n',
                '-d',
                'display_errors=0',
                '-d',
                'display_startup_errors=0',
                '-S',
                $address,
                'public/index.php',
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $workingDirectory
        );

        self::assertIsResource($process);
        fclose($pipes[0]);

        $response = false;
        $responseStatus = null;
        $context = stream_context_create([
            'http' => ['timeout' => 0.25, 'ignore_errors' => true],
        ]);
        $deadline = microtime(true) + 5;

        try {
            while (microtime(true) < $deadline) {
                $response = @file_get_contents(
                    'http://' . $address . $requestPath,
                    false,
                    $context
                );

                if ($response !== false) {
                    $statusLine = $http_response_header[0] ?? '';

                    if (preg_match('/\s(\d{3})(?:\s|$)/', $statusLine, $matches) === 1) {
                        $responseStatus = (int) $matches[1];
                    }

                    break;
                }

                if (!proc_get_status($process)['running']) {
                    break;
                }

                usleep(50_000);
            }
        } finally {
            proc_terminate($process);
            $serverLog = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }

        return [
            $response,
            $response === false ? 'The web entrypoint did not return a response.' : '',
            $responseStatus,
            $serverLog,
        ];
    }

    private function removeDirectory(string $directory): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $directory,
                FilesystemIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isLink()) {
                unlink($item->getPathname());
            } elseif ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($directory);
    }

    private function copyDirectory(string $source, string $destination): void
    {
        if (!mkdir($destination, 0700, true)) {
            throw new RuntimeException("Cannot create directory: {$destination}");
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $source,
                FilesystemIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $target = $destination . substr(
                $item->getPathname(),
                strlen($source)
            );

            if ($item->isDir()) {
                if (!mkdir($target, 0700, true)) {
                    throw new RuntimeException("Cannot create directory: {$target}");
                }
            } elseif (!copy($item->getPathname(), $target)) {
                throw new RuntimeException("Cannot copy file: {$target}");
            }
        }
    }
}
