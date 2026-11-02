<?php

declare(strict_types=1);

namespace EncryptedEnv\Commands;

use CodeIgniter\CLI\CLI;
use EncryptedEnv\Diagnostics\SecurityAuditor;

final class SecurityCheckCommand extends EncryptedEnvCommand
{
    protected $name = 'encrypted-env:security:check';
    protected $description = 'Check encrypted environment storage and exposure risks';

    protected $options = [
        '--root' => 'CodeIgniter project root (defaults to the current project).',
        '--file' => 'Path to the .env file (defaults to the project .env).',
    ];

    public function run(array $params)
    {
        return $this->executeSafely(function () use ($params): int {
            $checks = (new SecurityAuditor())->inspect(
                $this->projectRoot(),
                $this->envPath($params)
            );
            $failed = false;

            foreach ($checks as $check) {
                CLI::write(
                    $check['check'] === 'Git'
                        ? sprintf('[%s] %s', $check['status'], $check['detail'])
                        : sprintf(
                            '[%s] %s: %s',
                            $check['status'],
                            $check['check'],
                            $check['detail']
                        ),
                    $check['status'] === 'OK' ? 'green' : 'yellow'
                );
                $failed = $failed || ! $check['passed'];
            }

            return $failed ? 1 : 0;
        }, $params);
    }
}
