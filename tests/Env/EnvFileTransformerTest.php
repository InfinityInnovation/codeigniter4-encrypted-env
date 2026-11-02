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

    public function testRoundTripPreservesEmptyUnicodeSpecialAndEqualsValuesAndComments(): void
    {
        $original = "# Synthetic fixture\n"
            . "CI_ENVIRONMENT=testing\n"
            . "EMPTY_VALUE=\n"
            . "EQUALS_VALUE=left=middle=right\n"
            . "UNICODE_VALUE=\"fictif-éà-秘密\"\n"
            . "SPECIAL_VALUE=\"synthetic value # retained = here\" # trailing comment\n";

        $encrypted = $this->transformer->transform($original, true);

        self::assertStringContainsString("# Synthetic fixture\n", $encrypted);
        self::assertStringContainsString("CI_ENVIRONMENT=testing\n", $encrypted);
        self::assertStringContainsString("EMPTY_VALUE=\n", $encrypted);
        self::assertStringContainsString(" # trailing comment\n", $encrypted);
        self::assertStringNotContainsString('fictif-éà-秘密', $encrypted);
        self::assertStringNotContainsString('synthetic value', $encrypted);
        self::assertSame($original, $this->transformer->transform($encrypted, false));
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
