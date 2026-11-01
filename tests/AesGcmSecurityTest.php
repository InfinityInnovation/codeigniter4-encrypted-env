<?php

declare(strict_types=1);

use EncryptedEnv\Crypto\AesGcmCipher;
use PHPUnit\Framework\TestCase;

final class AesGcmSecurityTest extends TestCase
{
    public function testTamperedCiphertextIsRejected(): void
    {
        $cipher = new AesGcmCipher(random_bytes(32));
        $encrypted = $cipher->encrypt('secret-test');

        $payload = base64_decode(substr($encrypted, 7), true);
        $payload[28] = chr(ord($payload[28]) ^ 1);

        $this->expectException(RuntimeException::class);

        $cipher->decrypt('enc:v1:' . base64_encode($payload));
    }

    public function testWrongKeyIsRejected(): void
    {
        $first = new AesGcmCipher(random_bytes(32));
        $second = new AesGcmCipher(random_bytes(32));

        $encrypted = $first->encrypt('secret-test');

        $this->expectException(RuntimeException::class);

        $second->decrypt($encrypted);
    }

    public function testInvalidPayloadIsRejected(): void
    {
        $cipher = new AesGcmCipher(random_bytes(32));

        $this->expectException(RuntimeException::class);

        $cipher->decrypt('enc:v1:invalid');
    }
}
