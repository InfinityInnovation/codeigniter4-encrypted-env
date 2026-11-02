<?php

declare(strict_types=1);

use EncryptedEnv\Crypto\AesGcmCipher;
use PHPUnit\Framework\TestCase;

final class AesGcmCipherTest extends TestCase
{
    private function cipher(): AesGcmCipher
    {
        return new AesGcmCipher(random_bytes(32));
    }

    public function testEncryptionAndDecryption(): void
    {
        $cipher = $this->cipher();
        $original = 'database-password-example';

        $encrypted = $cipher->encrypt($original);

        self::assertStringStartsWith('enc:v1:', $encrypted);
        self::assertNotSame($original, $encrypted);
        self::assertSame($original, $cipher->decrypt($encrypted));

        $payload = base64_decode(substr($encrypted, 7), true);
        self::assertIsString($payload);
        self::assertSame(12, strlen(substr($payload, 0, 12)));
        self::assertSame(16, strlen(substr($payload, 12, 16)));
    }

    public function testRandomIvProducesDifferentResults(): void
    {
        $cipher = $this->cipher();

        $first = $cipher->encrypt('same-value');
        $second = $cipher->encrypt('same-value');

        self::assertNotSame($first, $second);

        $firstPayload = base64_decode(substr($first, 7), true);
        $secondPayload = base64_decode(substr($second, 7), true);
        self::assertIsString($firstPayload);
        self::assertIsString($secondPayload);
        self::assertNotSame(substr($firstPayload, 0, 12), substr($secondPayload, 0, 12));
    }

    public function testPlaintextRemainsUnchanged(): void
    {
        $cipher = $this->cipher();

        self::assertSame('normal-value', $cipher->decrypt('normal-value'));
    }

    public function testInvalidKeyIsRejected(): void
    {
        $this->expectException(RuntimeException::class);

        new AesGcmCipher('invalid-key');
    }
}
