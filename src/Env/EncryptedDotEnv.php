<?php

declare(strict_types=1);

namespace EncryptedEnv\Env;

use CodeIgniter\Config\DotEnv;
use EncryptedEnv\Crypto\AesGcmCipher;

final class EncryptedDotEnv extends DotEnv
{
    private AesGcmCipher $cipher;

    public function __construct(string $path, ?string $masterKey = null)
    {
        parent::__construct($path);

        $this->cipher = new AesGcmCipher(
            (new MasterKeyResolver())->resolve($masterKey)
        );
    }

    protected function setVariable(string $name, string $value = ''): void
    {
        if (str_starts_with($value, 'enc:v1:')) {
            $value = $this->cipher->decrypt($value);
        }

        parent::setVariable($name, $value);
    }
}
