<?php

declare(strict_types=1);

namespace EncryptedEnv\Env;

use CodeIgniter\Config\DotEnv;
use EncryptedEnv\Crypto\AesGcmCipher;
use RuntimeException;

final class EncryptedDotEnv extends DotEnv
{
    private AesGcmCipher $cipher;

    public function __construct(string $path, ?string $masterKey = null)
    {
        parent::__construct($path);

        $encodedKey = $masterKey ?? getenv('ENCRYPTED_ENV_MASTER_KEY');

        if (!is_string($encodedKey) || $encodedKey === '') {
            throw new RuntimeException('Missing ENCRYPTED_ENV_MASTER_KEY.');
        }

        $key = base64_decode($encodedKey, true);

        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('Invalid master key. Expected 32 bytes encoded in Base64.');
        }

        $this->cipher = new AesGcmCipher($key);
    }

    protected function setVariable(string $name, string $value = ''): void
    {
        if (str_starts_with($value, 'enc:v1:')) {
            $value = $this->cipher->decrypt($value);
        }

        parent::setVariable($name, $value);
    }
}
