<?php

declare(strict_types=1);

namespace EncryptedEnv\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use EncryptedEnv\Installer\CodeIgniterInstaller;
use Throwable;

final class InstallCommand extends BaseCommand
{
    protected $group = 'Encrypted Environment';
    protected $name = 'encrypted-env:install';
    protected $description = 'Configure encrypted environment support for CodeIgniter 4';

    public function run(array $params)
    {
        $projectRoot = defined('ROOTPATH')
            ? ROOTPATH
            : getcwd();

        try {
            $results = (new CodeIgniterInstaller())->install($projectRoot);

            foreach ($results as $file => $status) {
                CLI::write("{$file}: {$status}", 'green');
            }

            CLI::write('Installation completed.', 'green');
        } catch (Throwable $exception) {
            CLI::error($exception->getMessage());
        }
    }
}
