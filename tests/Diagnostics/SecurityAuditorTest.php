<?php

declare(strict_types=1);

use EncryptedEnv\Diagnostics\SecurityAuditor;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Common.php';

defined('ENVIRONMENT') || define('ENVIRONMENT', 'testing');

final class SecurityAuditorTest extends TestCase
{
    private string $directory;
    private SecurityAuditor $auditor;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/encrypted-env-git-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
        $this->auditor = new SecurityAuditor();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testReportsWhenGitRepositoryIsNotDetected(): void
    {
        $this->requireGit();
        $result = $this->gitCheck($this->directory);

        self::assertSame('SKIP', $result['status']);
        self::assertSame('Git repository not detected', $result['detail']);
        self::assertFalse($result['passed']);
    }

    public function testReportsWhenGitExecutableIsUnavailable(): void
    {
        $previousPath = getenv('PATH');
        $emptyPath = $this->directory . '/empty-path';
        mkdir($emptyPath);
        putenv('PATH=' . $emptyPath);

        try {
            $result = $this->gitCheck($this->directory);
        } finally {
            if ($previousPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $previousPath);
            }
        }

        self::assertSame('WARN', $result['status']);
        self::assertSame('Git executable unavailable', $result['detail']);
        self::assertFalse($result['passed']);
    }

    public function testReportsWhenGitCannotListTrackedFiles(): void
    {
        $this->requireGit();
        $this->initializeRepository();
        mkdir($this->directory . '/.git/index');

        $result = $this->gitCheck($this->directory);

        self::assertSame('WARN', $result['status']);
        self::assertSame(
            'Git scan failed: unable to list tracked files.',
            $result['detail']
        );
        self::assertFalse($result['passed']);
    }

    public function testScansTrackedTextFilesOnlyAndIgnoresUntrackedDependenciesAndBinaryFiles(): void
    {
        $this->requireGit();
        $this->initializeRepository();
        mkdir($this->directory . '/vendor', 0700, true);

        file_put_contents($this->directory . '/.env', "APP_PASSWORD=replace-me\n");
        file_put_contents(
            $this->directory . '/untracked.php',
            "<?php\n\$password = 'placeholder';\n"
        );
        file_put_contents(
            $this->directory . '/vendor/config.php',
            "<?php\n\$password = 'placeholder';\n"
        );
        file_put_contents(
            $this->directory . '/binary.php',
            "<?php\0BINARY_FIXTURE=binary-fixture-content"
        );
        $this->git(['add', '.env', 'vendor/config.php', 'binary.php']);

        $result = $this->gitCheck($this->directory);

        self::assertSame('OK', $result['status']);
        self::assertSame(
            'No potential plaintext secrets detected in tracked files',
            $result['detail']
        );
        self::assertTrue($result['passed']);
    }

    public function testReportsPotentialSecretWithoutRevealingItsValueOrPath(): void
    {
        $this->requireGit();
        $this->initializeRepository();
        $secret = 'synthetic-only-secret-value-123456';
        file_put_contents($this->directory . '/.env', 'DB_PASSWORD=' . $secret . PHP_EOL);
        $this->git(['add', '.env']);

        $result = $this->gitCheck($this->directory);

        self::assertSame('WARN', $result['status']);
        self::assertSame(
            'Potential plaintext secrets detected in tracked files',
            $result['detail']
        );
        self::assertStringNotContainsString($secret, $result['detail']);
        self::assertStringNotContainsString('.env', $result['detail']);
    }

    /**
     * @return array{check: string, passed: bool, detail: string, status: string}
     */
    private function gitCheck(string $root): array
    {
        foreach ($this->auditor->inspect($root, $root . '/.env') as $check) {
            if ($check['check'] === 'Git') {
                return $check;
            }
        }

        self::fail('Git scan result was not included.');
    }

    private function initializeRepository(): void
    {
        $this->git(['init', '--quiet']);
    }

    private function requireGit(): void
    {
        if (! function_exists('proc_open')) {
            self::markTestSkipped('proc_open is unavailable.');
        }

        $process = @proc_open(
            ['git', '--version'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        if (! is_resource($process)) {
            self::markTestSkipped('Git is unavailable.');
        }

        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0) {
            self::markTestSkipped('Git is unavailable.');
        }
    }

    /**
     * @param list<string> $arguments
     */
    private function git(array $arguments): void
    {
        $process = proc_open(
            array_merge(['git', '-C', $this->directory], $arguments),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), (string) $error . (string) $output);
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

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
}
