# Guide d'installation et d'utilisation
## CodeIgniter 4 Encrypted Environment

**Éditeur :** Infinity Innovations  
**Package :** `infinityinnovationsdev/codeigniter4-encrypted-env`  
**Version documentée :** v1.1.2 (préparation, non publiée)
**Licence :** MIT  
**Compatibilité ciblée par la matrice CI :** CodeIgniter 4.3.8 et la dernière version stable admise par `^4.3` (actuellement 4.7.6)

---

## 1. Présentation

CodeIgniter 4 Encrypted Environment est une bibliothèque PHP open source permettant de chiffrer les valeurs du fichier `.env` d'une application CodeIgniter 4.

Elle utilise l'algorithme **AES-256-GCM** et déchiffre automatiquement les valeurs au démarrage de l'application.

### Objectifs

- Protéger les identifiants de connexion aux bases de données.
- Protéger les clés API et autres informations sensibles.
- Chiffrer les valeurs des variables du fichier `.env`.
- Conserver un seul fichier `.env`.
- Conserver la clé de chiffrement en dehors du projet.
- Permettre à CodeIgniter de charger les variables déchiffrées.

**Important :** cette bibliothèque protège les valeurs au repos. Elle ne remplace pas les contrôles d'accès, les gestionnaires de secrets ou les bonnes pratiques de sécurité.

## 2. Prérequis

Avant de commencer, le développeur doit disposer de :

- Un projet CodeIgniter 4.
- PHP et Composer installés.
- L'extension PHP OpenSSL disponible.
- Un accès au terminal.
- Les droits nécessaires pour modifier les fichiers du projet.

**Compatibilité :** l'intégration prend en charge le bootstrap historique de CodeIgniter 4.3.8 et teste la dernière version stable admise par la contrainte Composer `^4.3` (actuellement 4.7.6). Les versions intermédiaires ne sont pas toutes testées individuellement.

## 3. Installer la bibliothèque

Ouvrir le terminal dans le répertoire racine de l'application CodeIgniter 4.

Exécuter :

```bash
composer require "infinityinnovationsdev/codeigniter4-encrypted-env:^1.1.2"
```

Cette commande installe la bibliothèque depuis Packagist après approbation et publication de la v1.1.2. Cette version est encore en préparation.

Vérifier son installation :

```bash
composer show infinityinnovationsdev/codeigniter4-encrypted-env
```

La version installée doit être au moins `v1.1.1`.

## 4. Configurer CodeIgniter 4

La bibliothèque fournit une commande d'installation :

```bash
php spark encrypted-env:install
```

Cette commande adapte `spark` et `public/index.php` au bootstrap détecté. Elle crée une sauvegarde `.encrypted-env.bak` pour chaque fichier modifié, restaure les fichiers d'origine en cas d'échec et peut être relancée sans écraser les sauvegardes.

### CodeIgniter 4.3.8 : bootstrap historique

Dans cette version, `system/bootstrap.php` initialise le framework, puis `spark` et `public/index.php` chargent directement DotEnv. L'installateur remplace cet appel standard par le chargeur chiffré, avant l'initialisation de l'application :

```php
require $paths->systemDirectory . '/bootstrap.php';
require_once SYSTEMPATH . 'Config/DotEnv.php';
require_once __DIR__ . '/vendor/autoload.php';
(new \EncryptedEnv\Env\EncryptedDotEnv(ROOTPATH))->load();
```

Pour `public/index.php`, le chemin de l'autoloader est `dirname(__DIR__) . '/vendor/autoload.php'`.

### CodeIgniter 4.7.6 : bootstrap moderne

```php
require $paths->systemDirectory . '/Boot.php';
require_once dirname(__DIR__) . '/vendor/infinityinnovationsdev/codeigniter4-encrypted-env/src/Bootstrap/EncryptedBoot.php';
exit(\EncryptedEnv\Bootstrap\EncryptedBoot::bootWeb($paths));
```

Dans `spark`, le chemin du fichier est `__DIR__ . '/vendor/infinityinnovationsdev/codeigniter4-encrypted-env/src/Bootstrap/EncryptedBoot.php'` et l'appel final est `EncryptedBoot::bootSpark($paths)`. Le chargeur Composer reste initialisé par CodeIgniter dans son ordre habituel.

Le chargement intervient avant l'initialisation de l'application : toutes les valeurs `.env` préfixées par `enc:v1:` sont déchiffrées avant leur utilisation. La configuration de la clé doit être fournie à l'environnement du processus PHP avant le chargement de `.env`.

## 5. Préparer le fichier `.env`

Le projet conserve son fichier `.env` habituel.

Exemple avant chiffrement :

```dotenv
CI_ENVIRONMENT = development

database.default.hostname = localhost
database.default.database = my_database
database.default.username = root
database.default.password = example_password

MY_API_KEY = example_api_key
```

Ces valeurs sont fictives.

La bibliothèque chiffre les valeurs des variables, et non les noms des variables.

Après chiffrement, les valeurs utilisent le format :

```dotenv
database.default.hostname = enc:v1:...
database.default.database = enc:v1:...
database.default.username = enc:v1:...
database.default.password = enc:v1:...

MY_API_KEY = enc:v1:...
```

Le contenu exact des valeurs chiffrées change selon les données et les paramètres cryptographiques.

## 6. Générer la clé de chiffrement

La bibliothèque nécessite une clé principale de 32 octets encodée en Base64.

**Cette clé ne doit jamais être enregistrée dans le fichier `.env`.**

### 6.1 Sur macOS ou Linux

Créer un répertoire sécurisé :

```bash
umask 077
mkdir -p "$HOME/.config/encrypted-env"
```

Générer une clé :

```bash
php -r 'echo base64_encode(random_bytes(32));' \
  > "$HOME/.config/encrypted-env/master.key"
```

Protéger le fichier :

```bash
chmod 600 "$HOME/.config/encrypted-env/master.key"
```

### 6.2 Configurer le chemin du fichier

La méthode recommandée transmet uniquement le chemin, pas la clé :

```bash
export ENCRYPTED_ENV_MASTER_KEY_FILE="$HOME/.config/encrypted-env/master.key"
php spark env
```

`ENCRYPTED_ENV_MASTER_KEY_FILE` a priorité sur `ENCRYPTED_ENV_MASTER_KEY`. Si le chemin est défini, tout fichier absent, illisible ou invalide arrête le démarrage sans fallback. Si le chemin n'est pas défini, l'ancienne variable `ENCRYPTED_ENV_MASTER_KEY` reste prise en charge.

Le fichier doit contenir la clé Base64 sur une ligne. Seuls les espaces et retours à la ligne extérieurs sont retirés. Une clé invalide ou différente de 32 octets après décodage provoque une erreur explicite. La clé n'est jamais incluse dans l'exception.

**Important :** cette variable de chemin doit être disponible dès le démarrage de PHP. Ne placez pas la clé ni uniquement son chemin dans le `.env` de CodeIgniter : le chargeur a besoin de la clé avant de lire ce fichier.

Pour une commande ponctuelle, la variable peut être configurée au lancement du processus :

```bash
ENCRYPTED_ENV_MASTER_KEY_FILE="$HOME/.config/encrypted-env/master.key" php spark env
```

PowerShell :

```powershell
$env:ENCRYPTED_ENV_MASTER_KEY_FILE = "$HOME\.config\encrypted-env\master.key"
php spark env
```

Une variable exportée dans le terminal n'est pas automatiquement transmise à PHP-FPM, Apache, IIS ou Docker.

### 6.3 Configurer les services web

#### Linux : Nginx avec PHP-FPM

Dans le pool FPM (exemple `/etc/php/8.2/fpm/pool.d/www.conf`) :

```ini
clear_env = yes
env[ENCRYPTED_ENV_MASTER_KEY_FILE] = /etc/mon-application-secrets/master.key
```

Vérifier que l'identité du pool PHP-FPM peut parcourir les répertoires parents et lire le fichier, puis recharger PHP-FPM. La même configuration FPM s'applique à Apache lorsque PHP tourne dans un service FastCGI séparé.

#### Apache avec mod_php

Dans le VirtualHost du site :

```apache
SetEnv ENCRYPTED_ENV_MASTER_KEY_FILE /etc/mon-application-secrets/master.key
```

Recharger Apache et réserver la lecture du fichier à l'administrateur de déploiement et au compte qui exécute PHP. `SetEnv` dans Apache ne configure pas automatiquement un processus PHP-FPM distinct.

#### Windows Server : IIS et PHP-FastCGI

Dans IIS Manager, ouvrir **FastCGI Settings**, sélectionner l'exécutable PHP puis modifier **Environment Variables** :

```text
Name:  ENCRYPTED_ENV_MASTER_KEY_FILE
Value: C:\ProgramData\MonApplication\Secrets\master.key
```

Accorder la lecture au compte du pool applicatif seulement, puis recycler le pool. Exemple à adapter à l'identité du service :

```powershell
icacls C:\ProgramData\MonApplication\Secrets\master.key /inheritance:r
icacls C:\ProgramData\MonApplication\Secrets\master.key /grant:r "IIS AppPool\MonApplication:(R)"
```

Une variable définie dans une console PowerShell interactive n'est pas automatiquement accessible à IIS.

#### Docker Compose

Monter la clé en tant que secret en lecture seule depuis un fichier hôte extérieur au dépôt :

```yaml
services:
  app:
    environment:
      ENCRYPTED_ENV_MASTER_KEY_FILE: /run/secrets/master_key
    secrets:
      - master_key

secrets:
  master_key:
    file: ${ENCRYPTED_ENV_MASTER_KEY_HOST_PATH:?Définir le chemin hôte de la clé}
```

Définir `ENCRYPTED_ENV_MASTER_KEY_HOST_PATH` dans l'environnement de déploiement. Cette variable contient un chemin uniquement, jamais la clé. Avec Docker Compose local, le secret fichier n'est pas chiffré sur l'hôte : protéger aussi le fichier source et l'hôte.

#### macOS, permissions et déploiement

Sur macOS et Linux, le mode `0600` du fichier et `0700` du dossier limitent l'accès au compte propriétaire. En production, choisir le propriétaire et les permissions pour que seul le processus PHP autorisé puisse lire le fichier. Sur Windows, les fichiers créés héritent des ACL NTFS du dossier : restreindre celles-ci avant d'exécuter les commandes et autoriser uniquement l'identité PHP et l'administrateur de déploiement. `doctor` signale que ces ACL doivent être vérifiées manuellement et `security:check` ne les considère pas comme validées. Ne pas placer le fichier dans `public/`, le dépôt, une image Docker ou un volume accessible aux autres services.

### 6.4 Conserver la clé

Sauvegarder la clé dans un gestionnaire de secrets sécurisé.

Ne jamais :

- Publier la clé sur GitHub.
- L'envoyer dans Slack ou par e-mail non sécurisé.
- La placer dans `.env`.
- Générer une nouvelle clé pour déchiffrer des données chiffrées avec une ancienne clé.

La perte de la clé peut rendre les données chiffrées définitivement irrécupérables.

## 7. Sauvegarder le `.env`

Avant le chiffrement, exécuter :

```bash
cp .env .env.backup
chmod 600 .env.backup
```

Vérifier que cette sauvegarde contient les valeurs originales.

Elle doit rester hors du dépôt Git et être conservée uniquement pendant la durée nécessaire à la validation et à la récupération.

## 8. Interface CLI Spark

Toutes les commandes suivantes s'exécutent depuis la racine du projet CodeIgniter. La clé doit être disponible au processus PHP via `ENCRYPTED_ENV_MASTER_KEY_FILE` ou, pour compatibilité, `ENCRYPTED_ENV_MASTER_KEY`.

| Commande | Fonction |
|---|---|
| `php spark encrypted-env:install` | Installe le chargement DotEnv chiffré dans `spark` et `public/index.php`; relançable sans écraser les sauvegardes. |
| `php spark encrypted-env:status` | Résume les versions, le bootstrap, `.env`, le nombre de variables chiffrées et l'état de la clé. |
| `php spark encrypted-env:doctor` | Vérifie PHP/OpenSSL, Composer, CodeIgniter/bootstrap, la clé et l'intégrité des valeurs chiffrées. |
| `php spark encrypted-env:key:generate --path=/chemin/externe/master.key` | Génère une clé aléatoire de 32 octets dans un fichier protégé; refuse l'écrasement sans `--force`. |
| `php spark encrypted-env:key:check` | Vérifie la source de clé, l'accès au fichier, le Base64 et les 32 octets sans révéler la clé. |
| `php spark encrypted-env:key:rotate --new-key-file=/chemin/externe/nouvelle.key --yes` | Rechiffre, valide le résultat, conserve une sauvegarde protégée et écrit une nouvelle clé distincte. |
| `php spark encrypted-env:encrypt` | Chiffre les valeurs éligibles de `.env`, conserve commentaires et valeurs déjà chiffrées. |
| `php spark encrypted-env:decrypt --yes` | Demande confirmation avant d'écrire des valeurs en clair; sauvegarde protégée et mode `0600`. |
| `php spark encrypted-env:security:check` | Recherche des motifs de secrets versionnés, vérifie emplacement/permissions et journaux. |
| `php spark encrypted-env:test` | Vérifie le chiffrement AES-256-GCM et la validité des valeurs chiffrées de `.env`. |
| `php spark encrypted-env:restore BACKUP --yes` | Restaure une sauvegarde créée par le package et sauvegarde d'abord le fichier courant. |

`--file=/chemin/vers/.env` permet de sélectionner un fichier pour chiffrement, déchiffrement, test, contrôle de sécurité ou rotation. Les diagnostics et l'installation acceptent `--root=/chemin/du/projet`.

Exemple :

```bash
ENCRYPTED_ENV_MASTER_KEY_FILE="$HOME/.config/encrypted-env/master.key" \
  php spark encrypted-env:key:check

php spark encrypted-env:encrypt
php spark encrypted-env:status
php spark encrypted-env:doctor
php spark encrypted-env:security:check
php spark encrypted-env:test
```

Par défaut, `CI_ENVIRONMENT`, `PATH`, `HOME`, `USER`, `SHELL`, `PWD` et `TMPDIR` restent en clair. Les autres affectations éligibles sont chiffrées. Toute modification valide le fichier, écrit atomiquement et conserve une sauvegarde persistante protégée avant remplacement. Sur POSIX, les sauvegardes et `.env` déchiffré sont en mode `0600`. Sur Windows, ils héritent des ACL NTFS du dossier parent, qui doivent être restrictives.

### Générer une nouvelle clé

```bash
php spark encrypted-env:key:generate \
  --path="$HOME/.config/encrypted-env/nouvelle.key"
```

La commande affiche uniquement le chemin, jamais la clé. Si le fichier existe, elle refuse de l'écraser sauf confirmation explicite avec `--force`.

### Effectuer une rotation sûre

```bash
php spark encrypted-env:key:rotate \
  --new-key-file="$HOME/.config/encrypted-env/nouvelle.key" \
  --yes
```

La rotation conserve l'ancienne clé et n'actualise pas automatiquement la configuration du processus. Après l'opération, configurer le nouveau chemin pour **les processus PHP CLI et Web**, puis exécuter `key:check`, `test` et vérifier le démarrage CLI/HTTP. Conserver l'ancienne clé et la sauvegarde chiffrée jusqu'à la validation des restaurations et la migration/expiration des anciens déploiements. En cas de retour arrière, restaurer la sauvegarde chiffrée pendant que l'ancienne clé est disponible, puis rétablir l'ancien chemin dans les configurations CLI et Web.

### Restaurer une sauvegarde protégée

```bash
php spark encrypted-env:restore \
  .env.encrypted-env-rotate-20261102-120000-a1b2c3d4.bak \
  --yes
```

La commande n'accepte que les sauvegardes du package situées à côté du fichier cible. Elle préserve le fichier remplacé dans une nouvelle sauvegarde protégée.

## 9. Chiffrer toutes les valeurs du `.env`

Depuis la racine du projet, exécuter :

```bash
php spark encrypted-env:encrypt
```

La clé principale doit déjà être disponible dans l'environnement du processus CLI.

Résultat attendu :

```text
Environment file processed successfully.
```

La bibliothèque traite le fichier `.env` présent dans le répertoire courant.

**Ne pas répéter le chiffrement inutilement.** Vérifier d'abord le résultat obtenu.

## 10. Vérifier le démarrage de CodeIgniter

### 9.1 Test CLI

Exécuter :

```bash
php spark env
```

Résultat possible :

```text
Your environment is currently set as production.
```

L'environnement affiché dépend de la configuration de l'application.

Le test est réussi si CodeIgniter démarre sans erreur de déchiffrement.

### 9.2 Test web

Démarrer le serveur local :

```bash
php spark serve --host 127.0.0.1 --port 8080
```

Ouvrir :

http://127.0.0.1:8080

La page de l'application doit s'afficher normalement.

### 9.3 Vérifier une valeur déchiffrée

Pour un test fonctionnel, ajouter une variable fictive avant le chiffrement :

```dotenv
ENCRYPTED_ENV_TEST = hello_test
```

Après chiffrement, vérifier dans un environnement de test que le chargeur restitue la valeur originale.

Ne jamais afficher les véritables mots de passe, tokens ou clés API dans les journaux.

## 11. Déchiffrer le fichier `.env`

La bibliothèque propose une commande de déchiffrement :

```bash
php spark encrypted-env:decrypt --yes
```

Cette commande permet de remettre les valeurs en clair dans le fichier `.env`, avec la bonne clé principale.

**Attention :** elle expose temporairement les valeurs sensibles sur disque. Elle doit être utilisée uniquement dans un environnement autorisé, avec une sauvegarde appropriée.

## 12. Utilisation sur un serveur et rotation

En production, définir `ENCRYPTED_ENV_MASTER_KEY_FILE` dans l'environnement du processus PHP selon le serveur utilisé ci-dessus. La bibliothèque ne choisit aucun chemin par défaut et ne tente pas de lire un chemin configuré dans `.env`.

Ne pas remplacer directement la clé d'un fichier si les valeurs `enc:v1:` existantes ont été chiffrées avec l'ancienne clé. Procédure de rotation sans perte d'accès :

1. Sauvegarder `.env` et protéger la copie, ainsi que l'ancienne clé.
2. Garder l'ancienne clé disponible et déchiffrer les valeurs avec l'ancienne clé dans un environnement contrôlé.
3. Installer la nouvelle clé externe et mettre à jour le chemin configuré pour le processus CLI et le serveur web.
4. Chiffrer de nouveau `.env` avec la nouvelle clé et vérifier les démarrages CLI et HTTP.
5. Conserver l'ancienne clé tant que des sauvegardes, déploiements ou fichiers `.env` chiffrés avec celle-ci existent.
6. Retirer l'ancienne clé seulement après validation des restaurations et expiration/migration des anciennes sauvegardes.

La perte de l'ancienne clé avant la migration rend les valeurs chiffrées avec celle-ci irrécupérables. Ne jamais afficher les valeurs déchiffrées ni la clé dans les journaux ou sorties CLI.

## 13. Erreurs fréquentes

### Erreur : `Invalid master key`

**Cause :** la clé est absente, invalide ou ne correspond pas au format attendu.

**Solution :** vérifier que le fichier configuré par `ENCRYPTED_ENV_MASTER_KEY_FILE` est accessible et contient une clé Base64 représentant exactement 32 octets. S'il n'est pas défini, vérifier `ENCRYPTED_ENV_MASTER_KEY`.

### Erreur : `Decryption failed`

**Cause :** une valeur a été chiffrée avec une autre clé, ou son contenu a été modifié.

**Solution :** retrouver la clé utilisée lors du chiffrement et vérifier l'intégrité du fichier.

Ne pas générer une nouvelle clé en espérant déchiffrer les anciennes valeurs.

### Erreur : `autoload.php not found`

**Cause possible :** utilisation d'une ancienne version de la bibliothèque.

**Solution :** mettre à jour vers la version corrigée :

```bash
composer require "infinityinnovationsdev/codeigniter4-encrypted-env:^1.1.1"
```

### Erreur : l'application fonctionne dans le terminal mais pas dans le navigateur

**Cause possible :** le processus serveur ne reçoit pas `ENCRYPTED_ENV_MASTER_KEY_FILE` ou ne peut pas lire le fichier. Le chemin défini mais invalide ne déclenche volontairement aucun fallback vers `ENCRYPTED_ENV_MASTER_KEY`.

**Solution :** configurer la clé pour le processus PHP du serveur web, puis vérifier le démarrage.

## 14. Bonnes pratiques

1. Utiliser une clé différente pour chaque environnement.
2. Conserver les clés en dehors du projet et du dépôt Git.
3. Limiter les permissions sur les fichiers contenant des secrets.
4. Ne jamais publier les sauvegardes du `.env`.
5. Tester la récupération des valeurs avant un déploiement.
6. Documenter la récupération et la rotation des clés.
7. Ne jamais afficher les secrets déchiffrés dans les logs.
8. Éviter de placer des secrets en clair dans les commandes ou l'historique du terminal.
9. Utiliser un gestionnaire de secrets pour les environnements de production.

## 15. État des tests

Les tests fonctionnels suivants ont été réalisés avec CodeIgniter 4.7.6 :

| Fonctionnalité | Résultat |
|---|---|
| Résolution de clé depuis un fichier externe | Validé par tests PHPUnit |
| CLI : installation, statut, diagnostic, clés, rotation, chiffrement et restauration | Validé par tests PHPUnit |
| Sauvegardes protégées et restauration | Validé par tests PHPUnit |
| Intégration CI 4.3.8 CLI / HTTP | Suite complète exécutée sur le framework 4.3.8 avec PHP 8.5 |
| Intégration CI 4.7.6 CLI / HTTP | Vérifiée sur projet isolé |
| Intégration du bootstrap CLI | Validé |
| Intégration du bootstrap web | Validé |
| Chiffrement du fichier `.env` | Validé |
| Démarrage de CodeIgniter avec `.env` chiffré | Validé |
| Déchiffrement de plusieurs variables et accès HTTP de contrôle | Validé |
| Compatibilité CodeIgniter 4.2 | Non ciblée |
| Compatibilité CodeIgniter 4.3.8 | 57 tests et 246 assertions sur PHP 8.5 ; matrice CI PHP 8.1 configurée, non exécutée ici |
| Compatibilité CodeIgniter 4.7.6 | 57 tests et 246 assertions sur PHP 8.5 |
| Matrice GitHub Actions PHP 8.1–8.5 | Configurée ; exécution CI distante non confirmée |
| Validation complète en production | À réaliser |

## 16. Ressources

**GitHub :**  
https://github.com/InfinityInnovation/codeigniter4-encrypted-env

**Packagist :**  
https://packagist.org/packages/infinityinnovationsdev/codeigniter4-encrypted-env

**Mainteneur :** Infinity Innovations

Les développeurs peuvent contribuer en proposant des corrections, en signalant des anomalies et en ouvrant des pull requests.

---

**Infinity Innovations — Developer Documentation**

Version 1.0 du guide — Novembre 2026