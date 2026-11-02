<?php

declare(strict_types=1);

namespace EncryptedEnv\Diagnostics;

use EncryptedEnv\Env\MasterKeyFile;
use EncryptedEnv\Env\MasterKeyResolver;
use Throwable;

final class SecurityAuditor
{
    /**
     * @return list<array{check: string, passed: bool, detail: string, status: string}>
     */
    public function inspect(string $root, string $envPath): array
    {
        $checks = [];
        $envContent = is_file($envPath) && is_readable($envPath)
            ? file_get_contents($envPath)
            : false;

        $checks[] = $this->result(
            'Application .env',
            is_string($envContent),
            is_string($envContent) ? 'Present and readable.' : 'Missing or unreadable.'
        );

        $hasInlineKey = is_string($envContent)
            && preg_match(
                '/^[ \t]*ENCRYPTED_ENV_MASTER_KEY(?:_FILE)?[ \t]*=/m',
                $envContent
            ) === 1;
        $checks[] = $this->result(
            'Master key in .env',
            ! $hasInlineKey,
            $hasInlineKey
                ? 'Key configuration is present in the application .env.'
                : 'No master-key setting found in the application .env.'
        );

        $key = (new MasterKeyFile())->check(new MasterKeyResolver());
        $keyPath = $key['source'] === 'file' ? $key['path'] : null;
        $external = is_string($keyPath)
            && $keyPath !== ''
            && is_file($keyPath)
            && realpath($keyPath) !== false
            && ! $this->isWithinRoot(realpath($keyPath), $root);
        $checks[] = $this->result(
            'External key configuration',
            $key['valid'] && ($key['source'] === 'environment' || $external),
            $key['source'] === 'file'
                ? ($external ? 'Configured file is outside the project.' : 'Key file is missing or inside the project.')
                : ($key['source'] === 'environment'
                    ? 'Direct process environment key is configured.'
                    : 'No master key is configured.')
        );

        if (is_string($keyPath) && is_file($keyPath) && DIRECTORY_SEPARATOR !== '\\') {
            $permissions = fileperms($keyPath);
            $private = $permissions !== false && ($permissions & 0077) === 0;
            $checks[] = $this->result(
                'Key file permissions',
                $private,
                $private ? 'No group/other access bits are set.' : 'Key file is accessible by group or others.'
            );
        } else {
            $checks[] = $this->result(
                'Key file permissions',
                $key['source'] !== 'file',
                'File permission check is unavailable.'
            );
        }

        if (is_string($envContent)) {
            $permissions = fileperms($envPath);
            $private = DIRECTORY_SEPARATOR !== '\\'
                && $permissions !== false
                && ($permissions & 0077) === 0;
            $checks[] = $this->result(
                '.env permissions',
                $private,
                DIRECTORY_SEPARATOR === '\\'
                    ? 'NTFS ACL permissions must be verified manually.'
                    : ($private
                        ? 'No group/other access bits are set.'
                        : '.env is accessible by group or others.')
            );
        }

        $checks[] = $this->trackedSecretFiles($root);

        $logLeaks = $this->logsWithSecretAssignments($root);
        $checks[] = $this->result(
            'Secret-like values in logs',
            $logLeaks === [],
            $logLeaks === []
                ? 'No master-key assignments were found in application logs.'
                : 'Potential master-key assignments found in logs: ' . implode(', ', $logLeaks)
        );

        return $checks;
    }

    /**
     * @return array{check: string, passed: bool, detail: string, status: string}
     */
    private function trackedSecretFiles(string $root): array
    {
        $rootPath = realpath($root);

        if ($rootPath === false || ! is_dir($rootPath)) {
            return $this->gitResult('WARN', 'Git scan failed: project directory is unavailable.');
        }

        if (! function_exists('proc_open')) {
            return $this->gitResult('WARN', 'Git scan failed: process execution is unavailable.');
        }

        $version = $this->runGit(['--version']);

        if (! $version['started'] || $version['exit_code'] === 127) {
            return $this->gitResult('WARN', 'Git executable unavailable');
        }

        if ($version['exit_code'] !== 0) {
            return $this->gitResult('WARN', 'Git scan failed: unable to run Git.');
        }

        $repository = $this->runGit(['-C', $rootPath, 'rev-parse', '--is-inside-work-tree']);

        if ($repository['exit_code'] !== 0) {
            if (str_contains(strtolower($repository['stderr']), 'not a git repository')) {
                return $this->gitResult('SKIP', 'Git repository not detected');
            }

            return $this->gitResult('WARN', 'Git scan failed: unable to inspect repository.');
        }

        if (trim($repository['stdout']) !== 'true') {
            return $this->gitResult('WARN', 'Git scan failed: unable to inspect repository.');
        }

        $listing = $this->runGit(['-C', $rootPath, 'ls-files', '--cached', '-z']);

        if ($listing['exit_code'] !== 0) {
            return $this->gitResult('WARN', 'Git scan failed: unable to list tracked files.');
        }

        foreach (explode("\0", $listing['stdout']) as $relativePath) {
            if (! $this->isScannablePath($relativePath)) {
                continue;
            }

            $path = $rootPath . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

            if (! is_file($path) || is_link($path)) {
                continue;
            }

            $contents = $this->readTextFile($path);

            if ($contents === false) {
                return $this->gitResult('WARN', 'Git scan failed: unable to read a tracked file.');
            }

            if ($contents === null) {
                continue;
            }

            $environmentSecret = preg_match(
                '/^[ \t]*(?:ENCRYPTED_ENV_MASTER_KEY|[A-Za-z0-9_.]*(?:PASSWORD|SECRET|TOKEN|API_KEY)[A-Za-z0-9_.]*)[ \t]*=[ \t]*(?!enc:v1:|["\']?(?:replace|example|your_|changeme|xxx|<|$))[^#\r\n]+/im',
                $contents
            ) === 1;
            $sourceSecret = preg_match(
                '/(?:ENCRYPTED_ENV_MASTER_KEY(?:_FILE)?|(?:password|secret|token|api[_-]?key))\s*[\'"]?\s*(?:=>|=|:)\s*[\'"]?(?!enc:v1:|\$\{|<|replace|example|your_|changeme|xxx)([A-Za-z0-9+\/_=.-]{24,})/i',
                $contents
            ) === 1;

            if ($environmentSecret || $sourceSecret) {
                return $this->gitResult(
                    'WARN',
                    'Potential plaintext secrets detected in tracked files'
                );
            }
        }

        return $this->gitResult(
            'OK',
            'No potential plaintext secrets detected in tracked files'
        );
    }

    private function readTextFile(string $path): string|null|false
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $contents = '';

        try {
            while (! feof($handle)) {
                $chunk = fread($handle, 8192);

                if ($chunk === false) {
                    return false;
                }

                if (str_contains($chunk, "\0")) {
                    return null;
                }

                $contents .= $chunk;
            }
        } finally {
            fclose($handle);
        }

        return $contents;
    }

    /**
     * @param list<string> $arguments
     * @return array{started: bool, exit_code: int|false, stdout: string, stderr: string}
     */
    private function runGit(array $arguments): array
    {
        $process = @proc_open(
            array_merge(['git'], $arguments),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        if (! is_resource($process)) {
            return ['started' => false, 'exit_code' => false, 'stdout' => '', 'stderr' => ''];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'started' => true,
            'exit_code' => $exitCode,
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    private function isScannablePath(string $relativePath): bool
    {
        if (
            $relativePath === ''
            || str_ends_with(strtolower($relativePath), '.example')
            || preg_match(
                '~(?:^|/)(?:vendor|node_modules|\.git)(?:/|$)~i',
                $relativePath
            ) === 1
        ) {
            return false;
        }

        return preg_match(
            '~(?:^|/)\.env(?:\.[^/]+)?$|\.(?:php|js|json|ya?ml|xml|sh|conf|ini|txt)$~i',
            $relativePath
        ) === 1;
    }

    /**
     * @return array{check: string, passed: bool, detail: string, status: string}
     */
    private function gitResult(string $status, string $detail): array
    {
        return [
            'check' => 'Git',
            'passed' => $status === 'OK',
            'detail' => $detail,
            'status' => $status,
        ];
    }

    /**
     * @return list<string>
     */
    private function logsWithSecretAssignments(string $root): array
    {
        $directory = $root . DIRECTORY_SEPARATOR . 'writable'
            . DIRECTORY_SEPARATOR . 'logs';

        if (! is_dir($directory)) {
            return [];
        }

        $findings = [];
        $encodedKey = null;

        try {
            $encodedKey = base64_encode((new MasterKeyResolver())->resolve());
        } catch (Throwable) {
            // Key configuration is reported by the separate key checks.
        }

        foreach (glob($directory . DIRECTORY_SEPARATOR . '*.log') ?: [] as $path) {
            $contents = is_file($path) && is_readable($path)
                ? file_get_contents($path)
                : false;

            if (
                is_string($contents)
                && (
                    (is_string($encodedKey) && str_contains($contents, $encodedKey))
                    || preg_match(
                        '/ENCRYPTED_ENV_MASTER_KEY(?:_FILE)?\s*=\s*(?!["\']?(?:\[redacted\]|<redacted>|%|$))\S+/i',
                        $contents
                    ) === 1
                )
            ) {
                $findings[] = basename($path);
            }
        }

        return $findings;
    }

    private function isWithinRoot(string $path, string $root): bool
    {
        $resolvedRoot = realpath($root);

        if ($resolvedRoot === false) {
            return true;
        }

        $resolvedRoot = rtrim($resolvedRoot, DIRECTORY_SEPARATOR);

        return $path === $resolvedRoot
            || str_starts_with($path, $resolvedRoot . DIRECTORY_SEPARATOR);
    }

    /**
     * @return array{check: string, passed: bool, detail: string, status: string}
     */
    private function result(string $check, bool $passed, string $detail): array
    {
        return [
            'check' => $check,
            'passed' => $passed,
            'detail' => $detail,
            'status' => $passed ? 'OK' : 'WARN',
        ];
    }
}
