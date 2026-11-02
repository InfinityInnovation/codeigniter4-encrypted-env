<?php

declare(strict_types=1);

namespace EncryptedEnv\Env;

use RuntimeException;

final class MasterKeyResolver
{
    private const FILE_ENVIRONMENT_VARIABLE = 'ENCRYPTED_ENV_MASTER_KEY_FILE';
    private const KEY_ENVIRONMENT_VARIABLE = 'ENCRYPTED_ENV_MASTER_KEY';
    private const MAX_KEY_FILE_SIZE = 1024;

    public function hasConfiguredKey(?string $masterKey = null): bool
    {
        return $masterKey !== null
            || $this->getEnvironmentValue(self::FILE_ENVIRONMENT_VARIABLE) !== null
            || $this->getEnvironmentValue(self::KEY_ENVIRONMENT_VARIABLE) !== null;
    }

    public function resolve(?string $masterKey = null): string
    {
        $keyFile = $this->getEnvironmentValue(self::FILE_ENVIRONMENT_VARIABLE);

        if ($keyFile !== null) {
            if ($keyFile === '') {
                throw new RuntimeException(
                    self::FILE_ENVIRONMENT_VARIABLE . ' is configured but empty.'
                );
            }

            return $this->readKeyFile($keyFile);
        }

        if ($masterKey !== null) {
            return $this->decode($masterKey);
        }

        $encodedKey = $this->getEnvironmentValue(self::KEY_ENVIRONMENT_VARIABLE);

        if ($encodedKey === null || $encodedKey === '') {
            throw new RuntimeException(
                'Missing ENCRYPTED_ENV_MASTER_KEY_FILE or ENCRYPTED_ENV_MASTER_KEY.'
            );
        }

        return $this->decode($encodedKey);
    }

    private function readKeyFile(string $path): string
    {
        if (!is_file($path)) {
            throw new RuntimeException(
                self::FILE_ENVIRONMENT_VARIABLE . ' does not point to a readable file.'
            );
        }

        $permissions = @fileperms($path);

        if ($permissions !== false && ($permissions & 0444) === 0) {
            throw new RuntimeException(
                self::FILE_ENVIRONMENT_VARIABLE . ' does not point to a readable file.'
            );
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException(
                self::FILE_ENVIRONMENT_VARIABLE . ' does not point to a readable file.'
            );
        }

        try {
            $encodedKey = stream_get_contents($handle, self::MAX_KEY_FILE_SIZE + 1);
        } finally {
            fclose($handle);
        }

        if ($encodedKey === false || strlen($encodedKey) > self::MAX_KEY_FILE_SIZE) {
            throw new RuntimeException(
                self::FILE_ENVIRONMENT_VARIABLE . ' could not be read as a valid key file.'
            );
        }

        return $this->decode(trim($encodedKey, " \t\n\r"));
    }

    private function decode(string $encodedKey): string
    {
        $key = base64_decode($encodedKey, true);

        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException(
                'Invalid master key. Expected Base64 encoding of exactly 32 bytes.'
            );
        }

        return $key;
    }

    private function getEnvironmentValue(string $name): ?string
    {
        $value = getenv($name);

        if ($value !== false) {
            return $value;
        }

        foreach ([$_SERVER, $_ENV] as $environment) {
            if (isset($environment[$name]) && is_string($environment[$name])) {
                return $environment[$name];
            }
        }

        return null;
    }
}
