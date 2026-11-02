<?php

declare(strict_types=1);

use EncryptedEnv\Crypto\AesGcmCipher;
use EncryptedEnv\Env\EnvFileTransformer;
use PHPUnit\Framework\TestCase;

final class EnvFileTransformerTest extends TestCase
{
    private EnvFileTransformer $transformer;

    protected function setUp(): void
    {
        $cipher = new AesGcmCipher(random_bytes(32));
        $this->transformer = new EnvFileTransformer($cipher);
    }

    public function testEncryptAndDecryptCompleteEnvFile(): void
    {
        $original = "APP_NAME=Demo\nDB_PASSWORD=secret123\n";

        $encrypted = $this->transformer->transform($original, true);

        self::assertStringNotContainsString('secret123', $encrypted);
        self::assertStringContainsString('enc:v1:', $encrypted);

        $decrypted = $this->transformer->transform($encrypted, false);

        self::assertSame($original, $decrypted);
    }

    public function testCommentsArePreserved(): void
    {
        $original = "# Configuration\nAPP_NAME=Demo\n";

        $encrypted = $this->transformer->transform($original, true);

        self::assertStringContainsString("# Configuration\n", $encrypted);
    }

    public function testEncryptionIsIdempotent(): void
    {
        $original = "APP_NAME=Demo\n";

        $encrypted = $this->transformer->transform($original, true);

        self::assertSame(
            $encrypted,
            $this->transformer->transform($encrypted, true)
        );
    }
}
