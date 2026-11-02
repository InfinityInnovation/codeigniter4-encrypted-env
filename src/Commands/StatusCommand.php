<?php

declare(strict_types=1);

namespace EncryptedEnv\Commands;

use CodeIgniter\CLI\CLI;
use EncryptedEnv\Diagnostics\ProjectInspector;

final class StatusCommand extends EncryptedEnvCommand
{
    protected $name = 'encrypted-env:status';
    protected $description = 'Show encrypted environment configuration status';

    protected $options = [
        '--root' => 'CodeIgniter project root (defaults to the current project).',
    ];

    public function run(array $params)
    {
        return $this->executeSafely(function (): int {
            $status = (new ProjectInspector())->inspect($this->projectRoot());
            $rows = [
                ['Library version', $status['library_version']],
                ['CodeIgniter version', $status['codeigniter_version']],
                ['Bootstrap', $status['bootstrap']],
                ['.env file', $status['env_exists'] ? 'present' : 'missing'],
                ['Encrypted variables', (string) $status['encrypted_variables']],
                ['Key resolution', $status['key_source']],
                ['Key configuration', $status['key_valid'] ? 'valid' : 'invalid'],
                ['Composer configuration', $status['composer_valid'] ? 'valid' : 'invalid'],
            ];

            foreach ($rows as [$label, $value]) {
                CLI::write(sprintf('%-24s %s', $label . ':', $value));
            }

            if (! $status['key_valid']) {
                CLI::error($status['key_message']);

                return 1;
            }

            return 0;
        }, $params);
    }
}
