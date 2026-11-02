<?php

declare(strict_types=1);

namespace EncryptedEnv\Commands;

use CodeIgniter\CLI\CLI;
use EncryptedEnv\Installer\CodeIgniterInstaller;

final class InstallCommand extends EncryptedEnvCommand
{
    protected $group = 'Encrypted Environment';
    protected $name = 'encrypted-env:install';
    protected $description = 'Configure encrypted environment support for CodeIgniter 4';

    protected $options = [
        '--root' => 'CodeIgniter project root (defaults to the current project).',
    ];

    public function run(array $params)
    {
        return $this->executeSafely(function (): int {
            $results = (new CodeIgniterInstaller())->install($this->projectRoot());

            foreach ($results as $file => $status) {
                CLI::write("{$file}: {$status}", 'green');
            }

            CLI::write('Installation completed.', 'green');

            return 0;
        }, $params);
    }
}
