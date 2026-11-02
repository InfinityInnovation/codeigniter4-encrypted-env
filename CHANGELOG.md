# Changelog

## [1.1.2] - Unreleased

### Added

- Spark commands for package installation, status, diagnostics, key generation/check/rotation, encryption/decryption, security checks, self-tests, and protected backup restoration.
- An isolated HTTP integration test that checks six encrypted synthetic values through `getenv()`, `env()`, `$_ENV`, and `$_SERVER` without returning the values.
- Git security-audit tests for missing repositories, missing Git, failed scans, tracked secrets, untracked files, dependencies, and binary files.
- A CI compatibility matrix for the CodeIgniter 4.3.8 baseline and the latest stable version accepted by the package constraint, across the supported PHP versions.

### Changed

- Security diagnostics now distinguish a missing Git repository, unavailable Git executable, failed scan, clean scan, and potential tracked secrets. Git results never include secret values or filenames.
- The Git scan is limited to tracked text source/configuration files and skips dependency and binary content.
- Key rotation and environment-file operations keep atomic replacements and protected backups; invalid keys or ciphertext leave the original environment file intact.
- Documented that Windows file security depends on restrictive inherited NTFS ACLs.

### Security

- Preserved AES-256-GCM, per-encryption random nonces, authentication-tag verification, and the `enc:v1:` ciphertext format.
- Added regression coverage for authentication-tag tampering, malformed ciphertext, and round-trips containing empty, Unicode, special-character, and `=` values.
