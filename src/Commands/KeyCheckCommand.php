<?php

declare(strict_types=1);

namespace EncryptedEnv\Commands;

use CodeIgniter\CLI\CLI;
use EncryptedEnv\Env\MasterKeyFile;
use EncryptedEnv\Env\MasterKeyResolver;

final class KeyCheckCommand extends EncryptedEnvCommand
{
    protected $name = 'encrypted-env:key:check';
    protected $description = 'Validate configured master key without revealing it';

    public function run(array $params)
    {
        return $this->executeSafely(function (): int {
            $check = (new MasterKeyFile())->check(new MasterKeyResolver());
            CLI::write('Resolution source: ' . $check['source']);

            if ($check['source'] === 'file') {
                CLI::write('Key file: ' . ($check['path'] ?? '(empty path)'));
                CLI::write(
                    'File accessibility: '
                    . (is_string($check['path']) && is_readable($check['path']) ? 'readable' : 'unavailable')
                );
            }

            if (! $check['valid']) {
                CLI::error($check['message']);

                return 1;
            }

            CLI::write('Master key is valid (Base64, 32 decoded bytes).', 'green');
            CLI::write('Key contents were not displayed.');

            return 0;
        }, $params);
    }
}
