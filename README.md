# CodeIgniter 4 Encrypted Env

**Secure environment variable encryption for CodeIgniter 4 using AES-256-GCM.**

Developed and maintained by **Infinity Innovations**.

![PHP](https://img.shields.io/badge/PHP-%3E%3D8.1-777BB4?logo=php&logoColor=white)
![CodeIgniter](https://img.shields.io/badge/CodeIgniter-4-EF4223?logo=codeigniter&logoColor=white)
![License](https://img.shields.io/badge/License-MIT-green)
![Status](https://img.shields.io/badge/Status-Experimental-orange)

## Overview

CodeIgniter 4 Encrypted Env is an open-source PHP library that provides optional encryption for sensitive environment variable values in CodeIgniter 4 applications.

Instead of storing database passwords, API keys, and other secrets as plaintext in `.env`, developers can store authenticated encrypted values.

The library uses **AES-256-GCM**, provided by PHP OpenSSL.

> **Project status:** Experimental. The test matrix covers CodeIgniter 4.3.8 and the latest stable release allowed by `^4.3` (currently 4.7.6). The project has not undergone an independent security audit.

> **Legacy framework warning:** CodeIgniter 4.3.8 is retained only as a compatibility-test baseline and has known security advisories. Do not use it in production; use a currently maintained CodeIgniter release instead. The PHP 8.1 / CI 4.3.8 workflow job uses a command-scoped Composer security-blocking exception to test this legacy version; dependency auditing remains enabled and no repository-wide Composer security policy is changed.

## Features

- AES-256-GCM authenticated encryption
- Random initialization vectors for every encryption
- Encrypted environment values using the `enc:v1:` prefix
- Support for ordinary plaintext values
- Detection of modified encrypted data
- Rejection of incorrect encryption keys
- Integration class extending CodeIgniter 4 DotEnv
- Bootstrap support for both the historical and modern CodeIgniter 4 entrypoints
- Interactive command-line encryption utility
- PHPUnit automated tests
- Open-source MIT license

## Requirements

- PHP 8.1 or higher
- CodeIgniter 4.3 or higher
- Composer
- PHP OpenSSL extension

## Installation

After version 1.1.2 has been approved and published on Packagist:

```bash
composer require "infinityinnovationsdev/codeigniter4-encrypted-env:^1.1.2"
```

For development from the source repository:

```bash
git clone https://github.com/InfinityInnovation/codeigniter4-encrypted-env.git
cd codeigniter4-encrypted-env
composer install
```

## Generate and Store a Master Key

Generate a cryptographically secure 256-bit master key:

```bash
umask 077
mkdir -p "$HOME/.config/encrypted-env"
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;' \
  > "$HOME/.config/encrypted-env/master.key"
chmod 600 "$HOME/.config/encrypted-env/master.key"
```

The file contains the Base64 key and must stay outside the project and source control. The application accepts either of these process environment variables:

```text
ENCRYPTED_ENV_MASTER_KEY_FILE=/absolute/path/to/master.key
ENCRYPTED_ENV_MASTER_KEY=BASE64_KEY
```

When `ENCRYPTED_ENV_MASTER_KEY_FILE` is set, that file is authoritative and takes priority over `ENCRYPTED_ENV_MASTER_KEY`. A missing, unreadable, empty, malformed, or invalid file stops startup; there is no fallback. If the file variable is unset, the existing direct key variable remains supported. The optional `EncryptedDotEnv` constructor argument continues to work for callers that supply a key explicitly.

For local CLI use, configure the path for the PHP process:
```bash
export ENCRYPTED_ENV_MASTER_KEY_FILE="$HOME/.config/encrypted-env/master.key"
php spark env
```

The path variable must be available before CodeIgniter loads `.env`; do not put the key or its path only in the application's `.env` and expect the encrypted loader to discover it. A variable exported in a terminal is not automatically inherited by PHP-FPM, Apache, IIS, or a container.

### PHP-FPM (Nginx or Apache with FPM)

Set the variable in the FPM pool configuration, where the service can read the external key file:

```ini
; e.g. /etc/php/8.2/fpm/pool.d/www.conf
clear_env = yes
env[ENCRYPTED_ENV_MASTER_KEY_FILE] = /etc/my-app-secrets/master.key
```

Ensure the PHP-FPM service account can traverse the parent directories and read the file, then reload/restart PHP-FPM. An Apache `SetEnv` directive does not replace setting the environment for a separate FPM process.

### Apache with mod_php

Set the path in the relevant virtual host and reload Apache:

```apache
SetEnv ENCRYPTED_ENV_MASTER_KEY_FILE /etc/my-app-secrets/master.key
```

Limit file permissions so only the deployment/admin account and Apache's PHP worker identity can read the key.

### IIS with PHP-FastCGI

In IIS Manager, open **FastCGI Settings**, select the PHP executable, edit **Environment Variables**, and add:

```text
Name:  ENCRYPTED_ENV_MASTER_KEY_FILE
Value: C:\ProgramData\MyApp\Secrets\master.key
```

Grant read access to the application pool identity only (for example with `icacls`) and recycle the application pool. A PowerShell environment variable in an interactive session is not automatically available to IIS.

### Docker Compose

Mount the key as a read-only Compose secret from a host path outside the repository:

```yaml
services:
  app:
    environment:
      ENCRYPTED_ENV_MASTER_KEY_FILE: /run/secrets/master_key
    secrets:
      - master_key

secrets:
  master_key:
    file: ${ENCRYPTED_ENV_MASTER_KEY_HOST_PATH:?Set the host key-file path}
```

Set `ENCRYPTED_ENV_MASTER_KEY_HOST_PATH` in the deployment environment (not to the key contents). For local Compose, file-backed secrets are mounted read-only but are not an encrypted secret manager; protect the source file and host accordingly.

Never commit key material, paste it into command history, or log it. Prefer a secret manager or protected external file in production.

## Spark CLI

The package registers commands with CodeIgniter Spark on the historical 4.3.8 bootstrap and the modern bootstrap used by current CodeIgniter 4 releases. Run them from the application root. The key must already be available to the PHP process through `ENCRYPTED_ENV_MASTER_KEY_FILE` or the legacy `ENCRYPTED_ENV_MASTER_KEY`.

| Command | Purpose |
|---|---|
| `php spark encrypted-env:install` | Install encrypted DotEnv handling in `spark` and `public/index.php`; safe to rerun. |
| `php spark encrypted-env:status` | Summarize versions, bootstrap, `.env`, encrypted-value count and key status. |
| `php spark encrypted-env:doctor` | Check PHP/OpenSSL, Composer, CodeIgniter/bootstrap compatibility, key validity and ciphertext integrity. |
| `php spark encrypted-env:key:generate --path=/outside/app/master.key` | Generate a random 32-byte key file with restrictive permissions; refuses overwrite unless `--force` is explicit. |
| `php spark encrypted-env:key:check` | Validate configured key source, accessibility, Base64 and decoded length without revealing it. |
| `php spark encrypted-env:key:rotate --new-key-file=/outside/app/next.key --yes` | Re-encrypt values, validate the result, preserve a protected backup and create a separate new key. |
| `php spark encrypted-env:encrypt` | Encrypt eligible plaintext values in `.env`, preserving comments and existing ciphertext. |
| `php spark encrypted-env:decrypt --yes` | Confirm explicitly before writing plaintext values; uses mode `0600` on POSIX and inherits NTFS ACLs on Windows. |
| `php spark encrypted-env:security:check` | Check tracked plaintext-secret patterns, key/.env placement and permissions, and logs. |
| `php spark encrypted-env:test` | Run an AES-256-GCM round-trip and validate encrypted `.env` values. |
| `php spark encrypted-env:restore BACKUP --yes` | Restore a package-created protected backup; preserves the currently installed file as a new backup. |

Use `--file=/path/to/.env` for encryption, decryption, testing, security checks, and rotation. Diagnostics and installation accept `--root=/path/to/application`. For example:

```bash
ENCRYPTED_ENV_MASTER_KEY_FILE="$HOME/.config/encrypted-env/master.key" \
  php spark encrypted-env:key:check

php spark encrypted-env:encrypt
php spark encrypted-env:status
php spark encrypted-env:doctor
php spark encrypted-env:security:check
php spark encrypted-env:test
```

By default, `CI_ENVIRONMENT`, `PATH`, `HOME`, `USER`, `SHELL`, `PWD`, and `TMPDIR` remain unencrypted. This conservative policy can be extended in future releases if applications require more exclusions. Other eligible assignments are encrypted. Every file-changing operation validates the target, writes atomically, and creates a persistent protected backup before replacement. POSIX files use mode `0600`; Windows files inherit the containing directory's NTFS ACL, which must be restricted to the application and deployment identities. Decryption requires `--yes` in non-interactive use.

### Safe key generation and rotation

Generate a key outside the project. The command displays only the destination path, never key contents:

```bash
php spark encrypted-env:key:generate --path="$HOME/.config/encrypted-env/next.key"
```

Rotation leaves the active key untouched and does not change the process configuration automatically:

```bash
php spark encrypted-env:key:rotate \
  --new-key-file="$HOME/.config/encrypted-env/next.key" \
  --yes
```

After rotation, distribute and configure the new path for **both CLI and web PHP processes**, then verify `encrypted-env:key:check`, `encrypted-env:test`, and application CLI/HTTP startup. Keep the old key and the encrypted backup until restores and old deployments have been verified; retire the old key only after every old ciphertext/back-up has migrated or expired. To roll back, restore the encrypted backup while the old key is still configured, then restore the old key path in CLI and web service configuration.

## Example .env File

```dotenv
CI_ENVIRONMENT = development

app.baseURL = 'http://localhost:8080/'

database.default.hostname = localhost
database.default.database = application_db
database.default.username = application_user
database.default.password = enc:v1:BASE64_ENCRYPTED_PAYLOAD
```

Replace the placeholder with the actual encrypted value generated by the utility.

The master key must be provided separately through the PHP process environment.

## CodeIgniter 4 Integration

The library provides the following class:

```php
EncryptedEnv\Env\EncryptedDotEnv
```

It extends CodeIgniter's standard DotEnv implementation.

Example initialization:

```php
use EncryptedEnv\Env\EncryptedDotEnv;

$dotenv = new EncryptedDotEnv(ROOTPATH);
$dotenv->load();
```

Run the package installer from the CodeIgniter project root:

```bash
php spark encrypted-env:install
```

It updates `spark` and `public/index.php`, creating `.encrypted-env.bak` backups. The installer recognizes both bootstrap layouts:

- CodeIgniter 4.3.8's `system/bootstrap.php` flow, replacing the standard DotEnv load with `EncryptedDotEnv`.
- The modern `CodeIgniter\Boot` flow, using `EncryptedBoot`.

The encrypted loader runs before application initialization, so values with the `enc:v1:` prefix are decrypted before the application consumes them. Re-running the installer is safe and reports already-installed entrypoints without overwriting their backups.

For the modern bootstrap, the installer loads the package's `EncryptedBoot.php` class file directly rather than preloading Composer's autoloader. This lets CodeIgniter load Composer in its normal boot sequence.

## Encryption Format

Encrypted values use the following format:

```text
enc:v1:<base64-encoded-payload>
```

The binary payload contains:

| Component | Size |
|---|---|
| Initialization vector (IV) | 12 bytes |
| Authentication tag | 16 bytes |
| Ciphertext | Variable |

The current format uses AES-256-GCM with a 256-bit encryption key.

## Security Considerations

### Master Key Management

The master key must never be stored alongside encrypted values in the same `.env` file.

Keep it in a protected environment configuration or a dedicated secret manager.

### Authentication and Integrity

AES-GCM provides authenticated encryption.

If ciphertext is modified or the incorrect master key is used, decryption fails.

### Key Loss

Encrypted secrets cannot be recovered without the original master key.

Back up and protect encryption keys according to your organization's security policy.

### Key Rotation
`encrypted-env:key:rotate` re-encrypts the current `.env` under a separately generated external key, checks the rotated ciphertext, and preserves a protected backup. It intentionally does not replace the active key path in the environment. Configure both CLI and web processes to use the new key, verify the deployment, and retain the old key until old backups and deployments are no longer needed.

### Limitations

Environment variable encryption does not replace access controls, secure deployment practices, operating-system protections, or proper secret management.

This project has not yet undergone an independent security audit.

## Running Tests

Install development dependencies:

```bash
composer install
```

Run the test suite:

```bash
vendor/bin/phpunit
```

The test suite covers encryption, decryption, incorrect keys, ciphertext and authentication-tag tampering, invalid encrypted payloads, external key-file resolution, DotEnv loading, file operations, and CLI/web entrypoint integration. The CI workflow is configured for PHP 8.1 with CodeIgniter 4.3.8 and PHP 8.2–8.5 with the latest stable CodeIgniter version accepted by `^4.3`. The compatibility workflow has not been run as part of this local preparation.

## Roadmap

- [x] AES-256-GCM encryption and decryption
- [x] Authenticated ciphertext validation
- [x] Basic CodeIgniter DotEnv integration class
- [x] Interactive encryption CLI
- [x] PHPUnit security tests
- [x] CodeIgniter 4.3.8 and latest-compatible bootstrap integration tests
- [x] CLI and web bootstrap compatibility tests
- [x] External master-key generation and rotation workflow
- [x] Spark CLI status, doctor, security, encrypt, decrypt, and test commands
- [x] GitHub Actions compatibility matrix configured
- [ ] Security review
- [ ] Packagist publication
- [ ] Stable release

## Contributing

Contributions are welcome from developers worldwide.

You can contribute through:

- Bug reports
- Security improvements
- CodeIgniter compatibility testing
- Documentation
- Automated tests
- Pull requests

Please do not disclose real credentials, encryption keys, or production secrets in public issues or pull requests.

## License

This project is distributed under the **MIT License**.

See the `LICENSE` file for details.

## Maintainer

**Infinity Innovations**

Open-source software development and security-focused engineering.

---

**CodeIgniter 4 Encrypted Env — Protecting configuration secrets through authenticated encryption.**# codeigniter4-encrypted-env
