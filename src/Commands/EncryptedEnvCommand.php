<?php

declare(strict_types=1);

namespace EncryptedEnv\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use EncryptedEnv\Env\MasterKeyResolver;
use RuntimeException;
use Throwable;

abstract class EncryptedEnvCommand extends BaseCommand
{
    protected $group = 'Encrypted Environment';

    /** @var list<string> */
    private array $commandParams = [];

    protected function executeSafely(callable $operation, array $params = []): int
    {
        $this->commandParams = array_values(array_filter($params, 'is_string'));

        try {
            return $operation();
        } catch (Throwable $exception) {
            CLI::error($exception->getMessage());

            return 1;
        }
    }

    protected function projectRoot(): string
    {
        $root = $this->option('root')
            ?? (defined('ROOTPATH') ? ROOTPATH : getcwd());

        if ($this->hasOption('root') && $this->option('root') === null) {
            throw new RuntimeException('The --root option requires a valid directory path.');
        }

        if (is_string($root) && ! $this->isAbsolutePath($root)) {
            $workingDirectory = getcwd();
            $root = is_string($workingDirectory)
                ? $workingDirectory . DIRECTORY_SEPARATOR . $root
                : $root;
        }

        if (! is_string($root) || $root === '') {
            throw new RuntimeException('Unable to determine the project directory.');
        }

        $resolved = realpath($root);

        if ($resolved === false) {
            throw new RuntimeException('Invalid project directory.');
        }

        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    protected function envPath(array $params): string
    {
        $path = $this->option('file');

        if ($this->hasOption('file') && $path === null) {
            throw new RuntimeException('The --file option requires a valid file path.');
        }

        if ($path === null) {
            $positional = array_values(array_filter(
                $params,
                static fn ($value): bool => is_string($value) && ! str_starts_with($value, '--')
            ));
            $path = $positional[0] ?? $this->projectRoot() . DIRECTORY_SEPARATOR . '.env';
        }

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('A valid environment file path is required.');
        }

        if (! $this->isAbsolutePath($path)) {
            $path = $this->projectRoot() . DIRECTORY_SEPARATOR . $path;
        }

        return $path;
    }

    protected function option(string $name): ?string
    {
        $value = CLI::getOption($name);

        if (is_string($value) && $value !== '') {
            return $value;
        }

        foreach (CLI::getOptions() as $option => $optionValue) {
            if (str_starts_with($option, $name . '=')) {
                $embeddedValue = substr($option, strlen($name) + 1);

                return $embeddedValue !== '' ? $embeddedValue : null;
            }

            if ($option === $name && is_string($optionValue) && $optionValue !== '') {
                return $optionValue;
            }
        }

        $option = '--' . $name;

        foreach ($this->commandParams as $index => $parameter) {
            if (str_starts_with($parameter, $option . '=')) {
                return substr($parameter, strlen($option) + 1);
            }

            if ($parameter === $option) {
                $next = $this->commandParams[$index + 1] ?? null;

                return is_string($next) && ! str_starts_with($next, '--')
                    ? $next
                    : null;
            }
        }

        return null;
    }

    protected function hasOption(string $name): bool
    {
        foreach (CLI::getOptions() as $option => $_value) {
            if ($option === $name || str_starts_with($option, $name . '=')) {
                return true;
            }
        }

        foreach ($this->commandParams as $parameter) {
            if ($parameter === '--' . $name || str_starts_with($parameter, '--' . $name . '=')) {
                return true;
            }
        }

        return false;
    }

    protected function flag(string $name): bool
    {
        $value = CLI::getOption($name);

        if ($value !== null && $value !== false) {
            return true;
        }

        foreach (CLI::getOptions() as $option => $optionValue) {
            if (
                $option === $name
                || ($option === $name . '=true')
                || ($option === $name && $optionValue === null)
            ) {
                return true;
            }
        }

        return in_array('--' . $name, $this->commandParams, true)
            || in_array('--' . $name . '=true', $this->commandParams, true);
    }

    protected function confirm(string $message, string $flag = 'yes'): bool
    {
        if ($this->flag($flag)) {
            return true;
        }

        if (! function_exists('stream_isatty') || ! stream_isatty(STDIN)) {
            throw new RuntimeException(
                'Confirmation is required in non-interactive mode; pass --' . $flag . '.'
            );
        }

        return strtolower(CLI::prompt($message . ' [y/N]', ['n', 'y'])) === 'y';
    }

    protected function masterKey(): string
    {
        return (new MasterKeyResolver())->resolve();
    }

    protected function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
            || str_starts_with($path, '\\\\');
    }

    protected function isWithinProject(string $path): bool
    {
        $root = $this->projectRoot();
        $candidate = realpath($path);

        if ($candidate === false) {
            $candidate = realpath(dirname($path));

            if ($candidate === false) {
                return false;
            }

            $candidate .= DIRECTORY_SEPARATOR . basename($path);
        }

        return $candidate === $root
            || str_starts_with($candidate, $root . DIRECTORY_SEPARATOR);
    }

    protected function validateExternalKeyPath(string $path): string
    {
        if (! $this->isAbsolutePath($path)) {
            throw new RuntimeException('The key file path must be absolute.');
        }

        $directory = realpath(dirname($path));

        if ($directory === false || ! is_dir($directory) || ! is_writable($directory)) {
            throw new RuntimeException('The key file parent directory must exist and be writable.');
        }

        $path = $directory . DIRECTORY_SEPARATOR . basename($path);

        if ($this->isWithinProject($path)) {
            throw new RuntimeException('The master key must be stored outside the project directory.');
        }

        return $path;
    }
}
