# Integration / Intégration

## English

The package supports CodeIgniter 4.3.8's legacy bootstrap and tests the latest stable CodeIgniter version allowed by the Composer constraint `^4.3` (currently 4.7.6). It decrypts `.env` values beginning with `enc:v1:` before CodeIgniter uses them. The AES-256-GCM format is unchanged.

> **Legacy framework warning:** CodeIgniter 4.3.8 is covered only as a legacy compatibility baseline and has known security advisories. Do not use it in production; use a currently maintained CodeIgniter release. The PHP 8.1 / CI 4.3.8 CI job uses a command-scoped Composer security-blocking exception only to resolve this historical dependency. It does not disable dependency auditing or change the repository-wide Composer policy.

### Configure the external key

Generate a 32-byte key and encode it as Base64:

```bash
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
```

Store the result in a protected file outside the repository and application directory. For example, on macOS/Linux:

```bash
install -d -m 700 "$HOME/.config/encrypted-env"
umask 077
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;' > "$HOME/.config/encrypted-env/master.key"
chmod 600 "$HOME/.config/encrypted-env/master.key"
```

For production, provision the key through a secrets manager or deployment system; do not generate or commit a production key in the application repository. Configure the PHP process with the file path:

```text
ENCRYPTED_ENV_MASTER_KEY_FILE=/absolute/path/to/master.key
```

This setting must reach PHP before CodeIgniter loads `.env`. Do not put the key or only this path in the application's `.env`. If the file path is configured but its file is missing, unreadable, empty, invalid Base64, or does not decode to exactly 32 bytes, startup fails explicitly; the package does not fall back to `ENCRYPTED_ENV_MASTER_KEY`. If no file path is configured, the legacy direct `ENCRYPTED_ENV_MASTER_KEY` setting remains supported.

### CLI

Make the path available in the environment of the same account that runs Spark:

```bash
ENCRYPTED_ENV_MASTER_KEY_FILE=/etc/my-app-secrets/master.key php spark env
```

For a persistent shell configuration, export the path in that user's protected service/shell environment. A variable exported in an interactive terminal is not automatically inherited by PHP-FPM, Apache, IIS, or a container.

### Linux Nginx + PHP-FPM

In the PHP-FPM pool configuration (for example, `/etc/php/8.2/fpm/pool.d/www.conf`):

```ini
clear_env = yes
env[ENCRYPTED_ENV_MASTER_KEY_FILE] = /etc/my-app-secrets/master.key
```

Grant the pool identity read access to the key and traversal access to its parent directories, then reload PHP-FPM. Apache deployments using a separate FastCGI/PHP-FPM service must configure the variable in that service, not just in Apache.

### Apache with mod_php

Set the path in the site's VirtualHost:

```apache
SetEnv ENCRYPTED_ENV_MASTER_KEY_FILE /etc/my-app-secrets/master.key
```

Reload Apache and grant read access only to the required service identity and deployment administrator. `SetEnv` does not configure a separate PHP-FPM process.

### Windows Server with IIS and PHP-FastCGI

In IIS Manager, open **FastCGI Settings**, select the PHP executable, and add an environment variable:

```text
Name:  ENCRYPTED_ENV_MASTER_KEY_FILE
Value: C:\ProgramData\MyApp\Secrets\master.key
```

Use NTFS ACLs to grant read access only to the application pool identity and recycle the pool. For example, adapt the identity and paths:

```powershell
icacls C:\ProgramData\MyApp\Secrets\master.key /inheritance:r
icacls C:\ProgramData\MyApp\Secrets\master.key /grant:r "IIS AppPool\MyApp:(R)"
```

An environment variable set in an interactive PowerShell session is not automatically available to IIS.

### Docker Compose

Mount the external host file as a read-only secret:

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

Set `ENCRYPTED_ENV_MASTER_KEY_HOST_PATH` in the deployment environment. It contains a path, not the key. Local Docker Compose file-backed secrets are not encrypted on the host; protect the source file and host accordingly.

### Permissions and rotation

On macOS/Linux, a `0700` parent directory and `0600` key file are a good starting point; production ownership must allow only the authorized PHP process to read the key. On Windows, generated files inherit the parent directory's NTFS ACL: restrict it before running package commands and grant access only to the PHP application identity and deployment administrator. `doctor` warns that ACLs need manual verification, and `security:check` does not report them as verified. Never store the file under the web root, in Git, in a broadly accessible volume, or in a container image.

To rotate keys without losing access, keep the old key available while decrypting the existing encrypted values, configure and deploy the new key, re-encrypt `.env` with the new key, and verify both CLI and HTTP startup. Keep the old key until all older deployments and encrypted backups have been migrated or expired and their restore paths have been tested. Losing an old key makes values encrypted with it unrecoverable.

### Spark CLI

The package registers these commands with CodeIgniter 4.3.8 and the modern CodeIgniter 4 bootstrap:

```bash
php spark encrypted-env:install
php spark encrypted-env:status
php spark encrypted-env:doctor
php spark encrypted-env:key:generate --path="$HOME/.config/encrypted-env/master.key"
php spark encrypted-env:key:check
php spark encrypted-env:encrypt
php spark encrypted-env:decrypt --yes
php spark encrypted-env:key:rotate --new-key-file="$HOME/.config/encrypted-env/next.key" --yes
php spark encrypted-env:security:check
php spark encrypted-env:test
php spark encrypted-env:restore .env.encrypted-env-rotate-YYYYMMDD-HHMMSS-xxxxxxxx.bak --yes
```

Commands that accept file paths support `--file=/absolute/path/to/.env`; diagnostics and installation support `--root=/absolute/path/to/project`. `key:generate` refuses an existing path, and `--force` is required to confirm overwriting. Decryption and rotation require `--yes` in non-interactive use. The key is never printed. By default, `CI_ENVIRONMENT`, `PATH`, `HOME`, `USER`, `SHELL`, `PWD`, and `TMPDIR` remain plaintext; other eligible values are encrypted. Every changed `.env` is atomically replaced only after a persistent protected backup has been written. On POSIX, backups and decrypted output use mode `0600`; on Windows, they inherit the parent directory's NTFS ACL, which must be restricted.

To rotate keys without losing access, run `key:rotate` with the old key configured and a **different, unused external path** for the new key. The command keeps the old key, creates the new key file, decrypts/re-encrypts values and validates the new ciphertext before replacing `.env`. It does not modify the active PHP environment. Then configure `ENCRYPTED_ENV_MASTER_KEY_FILE` for both CLI and web processes to the new path and verify `key:check`, `test`, CLI startup, and HTTP startup. Retain the old key and the protected rotation backup until all restore paths and old deployments have been verified or expired. For rollback, restore the package backup with the old key available and restore the old key path in both process configurations.

## Français

Le package prend en charge le bootstrap historique de CodeIgniter 4.3.8 et teste la dernière version stable admise par la contrainte Composer `^4.3` (actuellement 4.7.6). Il déchiffre les valeurs `.env` commençant par `enc:v1:` avant leur utilisation par CodeIgniter. Le format AES-256-GCM reste inchangé.

> **Avertissement sur la version historique :** CodeIgniter 4.3.8 est conservé uniquement comme base de compatibilité ancienne et comporte des avis de sécurité connus. Ne pas l'utiliser en production ; privilégier une version CodeIgniter maintenue. Le job CI PHP 8.1 / CI 4.3.8 applique une exception Composer de blocage de sécurité limitée à cette commande pour résoudre cette dépendance historique. L'audit des dépendances reste actif et la politique Composer globale du dépôt n'est pas modifiée.

### Configurer la clé externe

Générer une clé de 32 octets et l'encoder en Base64 :

```bash
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
```

Stocker le résultat dans un fichier protégé, hors du dépôt et du répertoire de l'application. Exemple macOS/Linux :

```bash
install -d -m 700 "$HOME/.config/encrypted-env"
umask 077
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;' > "$HOME/.config/encrypted-env/master.key"
chmod 600 "$HOME/.config/encrypted-env/master.key"
```

En production, fournir la clé par un gestionnaire de secrets ou le système de déploiement ; ne pas créer ni versionner une clé de production dans le dépôt. Configurer le processus PHP avec le chemin du fichier :

```text
ENCRYPTED_ENV_MASTER_KEY_FILE=/chemin/absolu/vers/master.key
```

Cette configuration doit être disponible pour PHP avant le chargement du `.env` de CodeIgniter. Ne placez ni la clé ni uniquement ce chemin dans le `.env` de l'application. Si le chemin du fichier est configuré mais que le fichier est absent, illisible, vide, en Base64 invalide ou ne contient pas exactement 32 octets après décodage, le démarrage échoue explicitement, sans fallback vers `ENCRYPTED_ENV_MASTER_KEY`. Si aucun chemin n'est configuré, la variable directe historique `ENCRYPTED_ENV_MASTER_KEY` reste prise en charge.

### CLI

Fournir le chemin à l'environnement du compte qui exécute Spark :

```bash
ENCRYPTED_ENV_MASTER_KEY_FILE=/etc/mon-application-secrets/master.key php spark env
```

Pour une configuration persistante, définir le chemin dans l'environnement protégé de l'utilisateur ou du service. Une variable exportée dans un terminal interactif n'est pas automatiquement transmise à PHP-FPM, Apache, IIS ou un conteneur.

### Linux : Nginx et PHP-FPM

Dans la configuration du pool PHP-FPM (par exemple `/etc/php/8.2/fpm/pool.d/www.conf`) :

```ini
clear_env = yes
env[ENCRYPTED_ENV_MASTER_KEY_FILE] = /etc/mon-application-secrets/master.key
```

Accorder au compte du pool le droit de lecture du fichier et le droit de traversée des répertoires parents, puis recharger PHP-FPM. Pour Apache utilisant un service FastCGI/PHP-FPM séparé, configurer la variable dans ce service, et pas uniquement dans Apache.

### Apache avec mod_php

Définir le chemin dans le VirtualHost du site :

```apache
SetEnv ENCRYPTED_ENV_MASTER_KEY_FILE /etc/mon-application-secrets/master.key
```

Recharger Apache et réserver la lecture à l'identité de service nécessaire et à l'administrateur de déploiement. `SetEnv` ne configure pas un processus PHP-FPM séparé.

### Windows Server avec IIS et PHP-FastCGI

Dans IIS Manager, ouvrir **FastCGI Settings**, sélectionner l'exécutable PHP puis ajouter une variable d'environnement :

```text
Name:  ENCRYPTED_ENV_MASTER_KEY_FILE
Value: C:\ProgramData\MonApplication\Secrets\master.key
```

Utiliser les ACL NTFS pour limiter la lecture à l'identité du pool applicatif, puis recycler le pool. Exemple à adapter :

```powershell
icacls C:\ProgramData\MonApplication\Secrets\master.key /inheritance:r
icacls C:\ProgramData\MonApplication\Secrets\master.key /grant:r "IIS AppPool\MonApplication:(R)"
```

Une variable définie dans une session PowerShell interactive n'est pas automatiquement disponible dans IIS.

### Docker Compose

Monter le fichier hôte externe comme secret en lecture seule :

```yaml
services:
  app:
    environment:
      ENCRYPTED_ENV_MASTER_KEY_FILE: /run/secrets/master_key
    secrets:
      - master_key

secrets:
  master_key:
    file: ${ENCRYPTED_ENV_MASTER_KEY_HOST_PATH:?Définir le chemin hôte du fichier de clé}
```

Définir `ENCRYPTED_ENV_MASTER_KEY_HOST_PATH` dans l'environnement de déploiement. Cette variable contient un chemin, pas la clé. Avec Docker Compose local, les secrets fichier ne sont pas chiffrés sur l'hôte : protéger également le fichier source et l'hôte.

### Permissions et rotation

Sur macOS/Linux, un dossier parent en `0700` et un fichier en `0600` constituent un bon point de départ ; en production, choisir un propriétaire permettant uniquement au processus PHP autorisé de lire la clé. Sur Windows, les fichiers générés héritent des ACL NTFS du dossier parent : les restreindre avant d'exécuter les commandes et autoriser uniquement l'identité PHP et l'administrateur de déploiement. `doctor` avertit que les ACL doivent être vérifiées manuellement et `security:check` ne les considère pas comme validées. Ne jamais placer le fichier dans la racine web, Git, un volume largement accessible ou une image conteneur.

Pour faire une rotation sans perdre l'accès, conserver l'ancienne clé pendant le déchiffrement des valeurs existantes, configurer et déployer la nouvelle clé, rechiffrer le `.env` avec celle-ci, puis vérifier les démarrages CLI et HTTP. Conserver l'ancienne clé jusqu'à la migration ou l'expiration de tous les anciens déploiements et sauvegardes chiffrées, et tester leur restauration. La perte d'une ancienne clé rend irrécupérables les valeurs chiffrées avec elle.

### CLI Spark

Le package enregistre ces commandes avec CodeIgniter 4.3.8 et le bootstrap moderne de CodeIgniter 4 :

```bash
php spark encrypted-env:install
php spark encrypted-env:status
php spark encrypted-env:doctor
php spark encrypted-env:key:generate --path="$HOME/.config/encrypted-env/master.key"
php spark encrypted-env:key:check
php spark encrypted-env:encrypt
php spark encrypted-env:decrypt --yes
php spark encrypted-env:key:rotate --new-key-file="$HOME/.config/encrypted-env/nouvelle.key" --yes
php spark encrypted-env:security:check
php spark encrypted-env:test
php spark encrypted-env:restore .env.encrypted-env-rotate-YYYYMMDD-HHMMSS-xxxxxxxx.bak --yes
```

Les commandes qui acceptent un fichier prennent `--file=/chemin/absolu/vers/.env`; les diagnostics et l'installation acceptent `--root=/chemin/absolu/du/projet`. `key:generate` refuse un chemin existant ; `--force` est nécessaire pour confirmer son remplacement. Le déchiffrement et la rotation exigent `--yes` en mode non interactif. La clé n'est jamais affichée. Par défaut, `CI_ENVIRONMENT`, `PATH`, `HOME`, `USER`, `SHELL`, `PWD` et `TMPDIR` restent en clair ; les autres valeurs éligibles sont chiffrées. Chaque `.env` modifié est remplacé atomiquement après création d'une sauvegarde persistante protégée. Sur POSIX, les sauvegardes et le fichier déchiffré sont en mode `0600` ; sur Windows, ils héritent des ACL NTFS du dossier parent, qui doivent être restrictives.

Pour effectuer une rotation sans perte d'accès, exécuter `key:rotate` avec l'ancienne clé configurée et un chemin externe **différent et inutilisé** pour la nouvelle clé. La commande conserve l'ancienne clé, crée le nouveau fichier, déchiffre/rechiffre les valeurs et valide le nouveau chiffrement avant de remplacer `.env`. Elle ne modifie pas l'environnement actif de PHP. Configurer ensuite `ENCRYPTED_ENV_MASTER_KEY_FILE` pour les processus CLI et Web avec le nouveau chemin, puis vérifier `key:check`, `test`, le démarrage CLI et le démarrage HTTP. Conserver l'ancienne clé et la sauvegarde protégée jusqu'à validation des restaurations et migration/expiration des anciens déploiements. Pour annuler, restaurer la sauvegarde du package avec l'ancienne clé disponible et rétablir l'ancien chemin dans les deux configurations.
