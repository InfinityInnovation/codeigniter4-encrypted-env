<?php

declare(strict_types=1);

namespace EncryptedEnv\Crypto;

use RuntimeException;

final class AesGcmCipher
{
    private const PREFIX = 'enc:v1:';

    public function __construct(private readonly string $key)
    {
        if (strlen($key) !== 32) {
            throw new RuntimeException('AES-256 requires a 32-byte key.');
        }
    }

    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $value): string
    {
        if (!str_starts_with($value, self::PREFIX)) {
            return $value;
        }

        $data = base64_decode(substr($value, strlen(self::PREFIX)), true);

        if ($data === false || strlen($data) < 28) {
            throw new RuntimeException('Invalid encrypted value.');
        }

        $plaintext = openssl_decrypt(
            substr($data, 28),
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            substr($data, 0, 12),
            substr($data, 12, 16)
        );

        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed.');
        }

        return $plaintext;
    }
}
