<?php

declare(strict_types=1);

namespace EncryptedEnv\Env;

use RuntimeException;

final class MasterKeyResolver
{
    private const FILE_ENVIRONMENT_VARIABLE = 'ENCRYPTED_ENV_MASTER_KEY_FILE';
    private const KEY_ENVIRONMENT_VARIABLE = 'ENCRYPTED_ENV_MASTER_KEY';
    private const LOCAL_CONFIGURATION_FILE = 'app/Config/EncryptedEnv.local.php';
    private const LOCAL_CONFIGURATION_GUARD = 'ENCRYPTED_ENV_LOCAL_CONFIG_LOADER';
    private const MAX_KEY_FILE_SIZE = 1024;

    public function __construct(private readonly ?string $projectRoot = null)
    {
    }

    public function hasConfiguredKey(?string $masterKey = null): bool
    {
        return $masterKey !== null
            || $this->getEnvironmentValue(self::FILE_ENVIRONMENT_VARIABLE) !== null
            || $this->getEnvironmentValue(self::KEY_ENVIRONMENT_VARIABLE) !== null
            || $this->localKeyFile() !== null;
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

        if ($encodedKey === null) {
            $localKeyFile = $this->localKeyFile();

            if ($localKeyFile !== null) {
                return $this->readKeyFile($localKeyFile);
            }

            throw new RuntimeException(
                'Missing ENCRYPTED_ENV_MASTER_KEY_FILE or ENCRYPTED_ENV_MASTER_KEY.'
            );
        }

        return $this->decode($encodedKey);
    }

    /**
     * @return array{source: string, path: ?string}
     */
    public function configurationSource(): array
    {
        $keyFile = $this->getEnvironmentValue(self::FILE_ENVIRONMENT_VARIABLE);

        if ($keyFile !== null) {
            return ['source' => 'file', 'path' => $keyFile];
        }

        if ($this->getEnvironmentValue(self::KEY_ENVIRONMENT_VARIABLE) !== null) {
            return ['source' => 'environment', 'path' => null];
        }

        $localKeyFile = $this->localKeyFile();

        return $localKeyFile === null
            ? ['source' => 'none', 'path' => null]
            : ['source' => 'local', 'path' => $localKeyFile];
    }

    private function localKeyFile(): ?string
    {
        if ($this->projectRoot === null) {
            return null;
        }

        $configurationPath = rtrim($this->projectRoot, '/\\')
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, self::LOCAL_CONFIGURATION_FILE);

        if (! file_exists($configurationPath)) {
            return null;
        }

        $permissions = @fileperms($configurationPath);

        if (
            ! is_file($configurationPath)
            || ! is_readable($configurationPath)
            || ($permissions !== false && ($permissions & 0444) === 0)
        ) {
            throw new RuntimeException('Local master key configuration is not readable.');
        }

        if (! defined(self::LOCAL_CONFIGURATION_GUARD)) {
            define(self::LOCAL_CONFIGURATION_GUARD, true);
        }

        $configuration = require $configurationPath;

        if (
            ! is_array($configuration)
            || ! isset($configuration['master_key_file'])
            || ! is_string($configuration['master_key_file'])
            || ! $this->isAbsolutePath($configuration['master_key_file'])
        ) {
            throw new RuntimeException(
                'Local master key configuration must return an absolute master_key_file path.'
            );
        }

        return $configuration['master_key_file'];
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
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
