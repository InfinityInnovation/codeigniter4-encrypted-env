<?php

declare(strict_types=1);

namespace EncryptedEnv\Env;

use CodeIgniter\Config\DotEnv;
use EncryptedEnv\Crypto\AesGcmCipher;

final class EncryptedDotEnv extends DotEnv
{
    private string $projectRoot;
    private ?AesGcmCipher $cipher = null;

    public function __construct(
        string $path,
        ?string $masterKey = null
    ) {
        parent::__construct($path);
        $this->projectRoot = rtrim($path, '/\\');

        $resolver = new MasterKeyResolver($this->projectRoot);

        if ($resolver->hasConfiguredKey($masterKey)) {
            $this->cipher = new AesGcmCipher(
                $resolver->resolve($masterKey)
            );
        }
    }

    public function load(): bool
    {
        $loaded = parent::load();

        if (! defined('ENVIRONMENT')) {
            $environment = function_exists('env')
                ? env('CI_ENVIRONMENT', 'production')
                : ($_ENV['CI_ENVIRONMENT']
                    ?? $_SERVER['CI_ENVIRONMENT']
                    ?? getenv('CI_ENVIRONMENT'));

            if (! is_string($environment) || $environment === '') {
                $environment = 'production';
            }

            define('ENVIRONMENT', $environment);
        }

        return $loaded;
    }

    protected function setVariable(string $name, string $value = ''): void
    {
        if (str_starts_with($value, 'enc:v1:')) {
            $this->cipher ??= new AesGcmCipher(
                (new MasterKeyResolver($this->projectRoot))->resolve()
            );

            $value = $this->cipher->decrypt($value);
        }

        parent::setVariable($name, $value);
    }
}
