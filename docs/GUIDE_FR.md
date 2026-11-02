# Guide d'installation et d'utilisation
## CodeIgniter 4 Encrypted Environment

**Éditeur :** Infinity Innovations  
**Package :** `infinityinnovationsdev/codeigniter4-encrypted-env`  
**Version documentée :** v1.1.1  
**Licence :** MIT  
**Compatibilité validée :** CodeIgniter 4.7.6

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

**Compatibilité :** la version 4.7.6 de CodeIgniter a été testée avec succès. Les versions 4.2 et 4.3 nécessitent des tests supplémentaires.

## 3. Installer la bibliothèque

Ouvrir le terminal dans le répertoire racine de l'application CodeIgniter 4.

Exécuter :

```bash
composer require "infinityinnovationsdev/codeigniter4-encrypted-env:^1.1.1"
```

Cette commande installe la bibliothèque depuis Packagist.

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

Cette commande permet de configurer l'intégration du chargement des variables chiffrées.

Après son exécution, vérifier les deux points d'entrée.

### 4.1 Fichier `spark`

À la fin de la procédure de démarrage, l'intégration validée utilise :

```php
require $paths->systemDirectory . '/Boot.php';
require_once __DIR__ . '/vendor/autoload.php';

exit(\EncryptedEnv\Bootstrap\EncryptedBoot::bootSpark($paths));
```

### 4.2 Fichier `public/index.php`

L'intégration validée utilise :

```php
require $paths->systemDirectory . '/Boot.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

exit(\EncryptedEnv\Bootstrap\EncryptedBoot::bootWeb($paths));
```

**Attention :** ces appels remplacent les appels de démarrage standards correspondants. Ne pas conserver deux appels de démarrage.

L'intégration exacte doit être vérifiée pour la version de CodeIgniter utilisée.

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

### 6.2 Charger la clé

Exécuter :

```bash
export ENCRYPTED_ENV_MASTER_KEY="$(
  cat "$HOME/.config/encrypted-env/master.key"
)"
```

La variable `ENCRYPTED_ENV_MASTER_KEY` devient disponible dans le terminal courant.

**Attention :** cette commande ne configure pas automatiquement Apache, Nginx, PHP-FPM ou les autres sessions du terminal.

### 6.3 Conserver la clé

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

## 8. Chiffrer toutes les valeurs du `.env`

Depuis la racine du projet, exécuter :

```bash
php vendor/infinityinnovationsdev/codeigniter4-encrypted-env/bin/encrypted-env encrypt-file
```

La clé principale doit déjà être chargée dans l'environnement du terminal.

Résultat attendu :

```text
Environment file processed successfully.
```

La bibliothèque traite le fichier `.env` présent dans le répertoire courant.

**Ne pas répéter le chiffrement inutilement.** Vérifier d'abord le résultat obtenu.

## 9. Vérifier le démarrage de CodeIgniter

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

## 10. Déchiffrer le fichier `.env`

La bibliothèque propose une commande de déchiffrement :

```bash
php vendor/infinityinnovationsdev/codeigniter4-encrypted-env/bin/encrypted-env decrypt-file
```

Cette commande permet de remettre les valeurs en clair dans le fichier `.env`, avec la bonne clé principale.

**Attention :** elle expose temporairement les valeurs sensibles sur disque. Elle doit être utilisée uniquement dans un environnement autorisé, avec une sauvegarde appropriée.

## 11. Utilisation sur un serveur

En production, l'application doit recevoir la clé principale au moyen d'un mécanisme sécurisé.

Exemples de mécanismes possibles :

- Gestionnaire de secrets.
- Variables d'environnement du service PHP.
- Injection de secrets au démarrage d'un conteneur Docker.
- Configuration sécurisée du processus PHP-FPM.

Le processus PHP qui démarre CodeIgniter doit pouvoir lire la clé.

Une clé chargée dans le terminal d'un développeur n'est pas automatiquement accessible au serveur web.

Il faut tester séparément le démarrage CLI et le démarrage web dans l'environnement réel de déploiement.

## 12. Erreurs fréquentes

### Erreur : `Invalid master key`

**Cause :** la clé est absente, invalide ou ne correspond pas au format attendu.

**Solution :** vérifier que `ENCRYPTED_ENV_MASTER_KEY` contient une clé Base64 représentant exactement 32 octets.

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

**Cause possible :** le serveur web ne reçoit pas la variable `ENCRYPTED_ENV_MASTER_KEY`.

**Solution :** configurer la clé pour le processus PHP du serveur web, puis vérifier le démarrage.

## 13. Bonnes pratiques

1. Utiliser une clé différente pour chaque environnement.
2. Conserver les clés en dehors du projet et du dépôt Git.
3. Limiter les permissions sur les fichiers contenant des secrets.
4. Ne jamais publier les sauvegardes du `.env`.
5. Tester la récupération des valeurs avant un déploiement.
6. Documenter la récupération et la rotation des clés.
7. Ne jamais afficher les secrets déchiffrés dans les logs.
8. Éviter de placer des secrets en clair dans les commandes ou l'historique du terminal.
9. Utiliser un gestionnaire de secrets pour les environnements de production.

## 14. État des tests

Les tests fonctionnels suivants ont été réalisés avec CodeIgniter 4.7.6 :

| Fonctionnalité | Résultat |
|---|---|
| Installation Composer v1.1.1 | Validé |
| Intégration du bootstrap CLI | Validé |
| Intégration du bootstrap web | Validé |
| Chiffrement du fichier `.env` | Validé |
| Démarrage de CodeIgniter avec `.env` chiffré | Validé |
| Déchiffrement d'une variable de test | Validé |
| Compatibilité CodeIgniter 4.2 | Non testée |
| Compatibilité CodeIgniter 4.3 | Non testée |
| Validation complète en production | À réaliser |

## 15. Ressources

**GitHub :**  
https://github.com/InfinityInnovation/codeigniter4-encrypted-env

**Packagist :**  
https://packagist.org/packages/infinityinnovationsdev/codeigniter4-encrypted-env

**Mainteneur :** Infinity Innovations

Les développeurs peuvent contribuer en proposant des corrections, en signalant des anomalies et en ouvrant des pull requests.

---

**Infinity Innovations — Developer Documentation**

Version 1.0 du guide — Novembre 2026