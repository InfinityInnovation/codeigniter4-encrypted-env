<?php

declare(strict_types=1);

namespace EncryptedEnv\Commands;

use CodeIgniter\CLI\CLI;
use EncryptedEnv\Crypto\AesGcmCipher;
use EncryptedEnv\Diagnostics\ProjectInspector;
use EncryptedEnv\Env\MasterKeyFile;
use EncryptedEnv\Env\MasterKeyResolver;
use EncryptedEnv\Env\EnvFileTransformer;

final class DoctorCommand extends EncryptedEnvCommand
{
    protected $name = 'encrypted-env:doctor';
    protected $description = 'Run compatibility and environment diagnostics';

    protected $options = [
        '--root' => 'CodeIgniter project root (defaults to the current project).',
    ];

    public function run(array $params)
    {
        return $this->executeSafely(function (): int {
            $root = $this->projectRoot();
            $inspector = new ProjectInspector();
            $status = $inspector->inspect($root);
            $keyStatus = (new MasterKeyFile())->check(new MasterKeyResolver($root));
            $spark = is_file($root . DIRECTORY_SEPARATOR . 'spark')
                ? file_get_contents($root . DIRECTORY_SEPARATOR . 'spark')
                : false;
            $web = is_file(
                $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php'
            )
                ? file_get_contents(
                    $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php'
                )
                : false;
            $checks = [
                ['PHP version', version_compare(PHP_VERSION, '8.1.0', '>=')],
                ['OpenSSL extension', extension_loaded('openssl')],
                ['Composer configuration', $status['composer_valid']],
                [
                    'CodeIgniter compatibility',
                    $status['codeigniter_version'] !== 'unavailable'
                    && $inspector->supportsCodeIgniterVersion(
                        (string) $status['codeigniter_version']
                    ),
                ],
                ['CLI bootstrap', $this->usesEncryptedBootstrap($spark, 'bootSpark')],
                ['HTTP bootstrap', $this->usesEncryptedBootstrap($web, 'bootWeb')],
                ['Master key configuration', $status['key_valid']],
                ['.env file', $status['env_exists']],
            ];

            if (
                $keyStatus['source'] === 'file'
                && is_string($keyStatus['path'])
                && is_file($keyStatus['path'])
            ) {
                if (DIRECTORY_SEPARATOR === '\\') {
                    $checks[] = ['Master key ACL (manual verification required)', null];
                } else {
                    $permissions = fileperms($keyStatus['path']);
                    $checks[] = [
                        'Master key permissions',
                        $permissions !== false && ($permissions & 0077) === 0,
                    ];
                }
            }

            if ($status['key_valid'] && $status['env_exists']) {
                $envPath = $root . DIRECTORY_SEPARATOR . '.env';
                $contents = file_get_contents($envPath);
                $permissions = fileperms($envPath);
                $checks[] = [
                    DIRECTORY_SEPARATOR === '\\'
                        ? '.env ACL (manual verification required)'
                        : '.env permissions',
                    DIRECTORY_SEPARATOR === '\\'
                        ? null
                        : ($permissions !== false && ($permissions & 0077) === 0),
                ];

                if ($contents === false) {
                    $checks[] = ['Encrypted values', false];
                } else {
                    try {
                        $plain = (new EnvFileTransformer(
                            new AesGcmCipher($this->masterKey())
                        ))->transform($contents, false);
                        $checks[] = ['Encrypted values', is_string($plain)];
                    } catch (\Throwable) {
                        $checks[] = ['Encrypted values', false];
                    }
                }
            } else {
                $checks[] = ['Encrypted values', false];
            }

            $failed = false;

            foreach ($checks as [$label, $passed]) {
                CLI::write(
                    sprintf(
                        '[%s] %s',
                        $passed === null ? 'WARN' : ($passed ? 'OK' : 'FAIL'),
                        $label
                    ),
                    $passed === null ? 'yellow' : ($passed ? 'green' : 'red')
                );
                $failed = $failed || $passed === false;
            }

            if (! $status['key_valid']) {
                CLI::error($status['key_message']);
            }

            if ($status['encrypted_variables'] === 0) {
                CLI::write('No encrypted .env values found.', 'yellow');
            }

            return $failed ? 1 : 0;
        }, $params);
    }

    private function usesEncryptedBootstrap(string|false $contents, string $method): bool
    {
        return is_string($contents)
            && (
                str_contains($contents, 'EncryptedBoot::' . $method)
                || str_contains($contents, 'EncryptedDotEnv')
            );
    }
}
