<?php

declare(strict_types=1);

namespace EncryptedEnv\Env;

use CodeIgniter\Config\DotEnv;
use EncryptedEnv\Crypto\AesGcmCipher;

final class EncryptedDotEnv extends DotEnv
{
    private ?AesGcmCipher $cipher = null;

    public function __construct(
        string $path,
        ?string $masterKey = null
    ) {
        parent::__construct($path);

        $resolver = new MasterKeyResolver();

        if ($resolver->hasConfiguredKey($masterKey)) {
            $this->cipher = new AesGcmCipher(
                $resolver->resolve($masterKey)
            );
        }
    }

    protected function setVariable(string $name, string $value = ''): void
    {
        if (str_starts_with($value, 'enc:v1:')) {
            $this->cipher ??= new AesGcmCipher(
                (new MasterKeyResolver())->resolve()
            );

            $value = $this->cipher->decrypt($value);
        }

        parent::setVariable($name, $value);
    }
}
