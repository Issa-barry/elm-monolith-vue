# Installer l'environnement local

> **Statut** : en place · **Dernière vérification** : 15/11/2026, installation complète rejouée sur
> un clone neuf de la branche `dev` (Windows 11, Laragon, PHP 8.5.1, MySQL 8.4.3, Node 22.12,
> Composer 2.8.4), dans un dossier et une base dédiés. Résultats détaillés en fin de document.

Objectif : partir d'un poste vide et obtenir le back-office qui tourne, avec des données de
développement.

## 1. Prérequis

| Outil | Version | Remarque |
|---|---|---|
| PHP | **8.4** conseillé (celle de la CI et des serveurs) ; minimum 8.2 (`composer.json`) | 8.5 fonctionne (testé), avec des avertissements « Deprecated » de Composer sans conséquence |
| Composer | 2.x | |
| Node.js | **22** (celle de la CI) | npm 10 |
| MySQL | 8.x | MariaDB non testé |
| Git | récent | |

Sous Windows, **Laragon** fournit PHP, Composer, MySQL et Node. Vérifier dans le menu Laragon que
PHP et Node sont dans les versions ci-dessus.

### Extensions PHP

Exigées par les dépendances (`composer.lock`) ou par l'application : `ctype`, `curl`, `dom`,
`fileinfo`, `gd`, `iconv`, `mbstring`, `openssl`, `pdo_mysql`, `simplexml`, `tokenizer`, `xml`,
`xmlreader`, `xmlwriter`, `zip`, `zlib`.

Pour les tests : `pdo_sqlite` et `sqlite3`. Pour la couverture de code : `pcov` (facultatif).

Vérifier : `php -m`. `composer install` s'arrête en nommant l'extension manquante.

## 2. Récupérer le code

```bash
git clone https://github.com/Issa-barry/elm-monolith-vue.git elm-monolithe
cd elm-monolithe
git checkout dev
```

`dev` est la branche de travail ([workflow-git.md](../developpement/workflow-git.md)).

## 3. Installer et configurer

### Ce que fait `composer setup`

`composer setup` enchaîne : `composer install`, copie de `.env.example` en `.env` (s'il n'existe
pas), `php artisan key:generate`, `php artisan migrate --force`, `npm install`, `npm run build`.

> ⚠️ `.env.example` est configuré pour **SQLite** (`DB_CONNECTION=sqlite`). Lancé tel quel,
> `composer setup` crée `database/database.sqlite` et y joue les migrations, **sans données** : ce
> n'est pas l'environnement de développement habituel (MySQL avec données). Utiliser la procédure
> ci-dessous.

### Procédure MySQL (recommandée)

1. **Dépendances PHP et fichier `.env`** :

   ```bash
   composer install
   cp .env.example .env       # Windows (cmd) : copy .env.example .env
   ```

   `composer install` ne crée pas `.env` (seul `composer setup` le fait).

2. **Configurer `.env`** :

   ```dotenv
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=elm_monolithe
   DB_USERNAME=root
   DB_PASSWORD=
   APP_URL=http://127.0.0.1:8000
   ```

   - `DB_HOST` à `DB_PASSWORD` sont commentés dans `.env.example` : les décommenter.
   - `APP_URL` doit être l'adresse réelle d'accès : elle est figée dans le front à la compilation.
   - Le reste peut garder les valeurs de `.env.example` (courriels écrits dans les journaux, Sentry,
     SMS et Web Push désactivés). Détail de chaque variable : [configuration.md](configuration.md).

3. **Clé de l'application** :

   ```bash
   php artisan key:generate
   ```

4. **Créer la base** (vide), avec HeidiSQL (Laragon) ou en ligne de commande :

   ```sql
   CREATE DATABASE elm_monolithe CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

5. **Tables et données de développement** :

   ```bash
   php artisan config:clear
   php artisan migrate --seed
   ```

   Le `DatabaseSeeder` crée l'organisation de développement « elm », ses agences, produits,
   véhicules, livreurs, clients et comptes. À la fin, un tableau affiche les comptes créés et leur
   mot de passe de développement. Ces comptes et mots de passe sont **publics** (dépôt public) :
   ne jamais les réutiliser ailleurs.

6. **Dépendances front et compilation** :

   ```bash
   npm install
   npm run build
   ```

7. **Fichiers publics** :

   ```bash
   php artisan storage:link
   ```

## 4. Démarrer

**Simple** — deux terminaux :

```bash
php artisan serve          # http://127.0.0.1:8000
npm run dev                # recompile le front à chaque modification
```

Sans `npm run dev`, le back-office utilise les fichiers compilés par `npm run build` (à relancer
après une modification du front).

**`composer dev`** lance ensemble le serveur, le traitement de la file (`queue:listen`), les
journaux (`pail`) et Vite, et arrête tout dès que l'un s'arrête. **Non validé sous Windows** :
`pail` exige l'extension PHP `pcntl`, qui n'existe pas sous Windows, et le test n'a rien démarré en
50 secondes. Sous Windows, utiliser les deux terminaux ci-dessus, plus
`php artisan queue:listen` si `QUEUE_CONNECTION=database` (valeur de `.env.example`) : sans lui,
les notifications et les SMS en file ne partent pas.

Le port **8080** est réservé aux tests E2E ([tests.md](../developpement/tests.md#playwright-e2e)) :
ne pas y lancer le serveur de développement.

## 5. Accéder aux interfaces

| Interface | Adresse |
|---|---|
| Back-office | `http://127.0.0.1:8000` → `/login` (numéro de téléphone et mot de passe), puis `/backoffice/dashboard` |
| Assistant d'installation | `/install` : inutile en local, l'organisation est créée par les seeders. Avec `.env.example` (`APP_DEPLOYMENT_MODE=saas`, `APP_INSTALL_TOKEN` vide), il répond **500** : comportement voulu ([configuration.md](configuration.md#organisations-et-déploiement)) |
| Documentation de l'API | `/docs/api` et `/docs/vitrine` (si `API_DOCS_ENABLED=true`, valeur de `.env.example`) |
| Contrôle de santé | `/up` |

Les applications mobiles et le site Nuxt sont des projets séparés : ils se branchent sur l'adresse
de ce serveur ([vue-ensemble.md](../architecture/vue-ensemble.md)).

## 6. Remettre la base à zéro

```bash
php artisan migrate:fresh --seed
php artisan optimize:clear
```

⚠️ Efface **toute** la base désignée par `.env`. Vérifier `DB_DATABASE` avant, et lancer
`php artisan config:clear` si une configuration a été mise en cache (`config:cache`, `optimize`) :
un cache périmé peut viser une autre base.

## 7. Dépannage

| Symptôme | Cause et solution |
|---|---|
| Sous Windows, la première commande `php artisan` après l'installation semble bloquée | Constaté lors du test : premier démarrage de 55 s à près de 2 min, puis 1 s aux suivants. Cause probable : analyse antivirus des milliers de fichiers de `vendor/` et `node_modules/`. Patienter ; ne pas interrompre une migration en cours |
| `could not find driver` | Extension `pdo_mysql` (ou `pdo_sqlite` pour les tests) non activée dans `php.ini` |
| `Access denied for user` / `Unknown database` | Identifiants ou nom de base de `.env` ; base non créée (étape 4) |
| La configuration semble ignorée après une modification de `.env` | `php artisan config:clear` (puis `optimize:clear`) |
| Erreur « Vite manifest not found » | `npm run build` jamais lancé : le lancer, ou démarrer `npm run dev` |
| Page sans style ni script après l'arrêt de `npm run dev` | Le fichier `public/hot` est resté (arrêt brutal) : le supprimer |
| Les appels du front partent vers un mauvais port | `APP_URL` différent de l'adresse utilisée : corriger puis relancer `npm run build` |
| Une image ou un document envoyé ne s'affiche pas | `php artisan storage:link` |
| Tests PHPUnit : « GARDE SECURITE TESTS » | Une configuration en cache pointe vers une vraie base : `php artisan config:clear` ([tests.md](../developpement/tests.md)) |

## Résultat du test d'installation

Rejoué le 15/11/2026 sur un clone neuf de `dev` (commit du jour), dans `c:\tmp`, avec une base
MySQL dédiée et le port 8001, sans toucher à l'environnement de développement existant.

| Étape | Résultat | Durée |
|---|---|---|
| `git clone` depuis GitHub | réussi | 7 s |
| `composer setup` tel quel (`.env.example`, SQLite) | réussi : `database/database.sqlite` créé, 289 migrations jouées, **aucune donnée** ; `npm install` signale 31 vulnérabilités connues (`npm audit`) ; build réussi | 8 min 40 |
| Passage à MySQL (`.env`), création de la base | réussi | — |
| `php artisan migrate --seed` sur MySQL | réussi (migrations et `DatabaseSeeder` complets) | 3 min 40 |
| `npm run build` avec la nouvelle `APP_URL` | réussi | 3 min |
| `php artisan storage:link` | réussi | 1 s |
| `php artisan serve` puis `GET /` | 302 vers `/login` | — |
| `GET /login`, `GET /up` | 200 | — |
| `GET /install` | 500 (mode `saas` sans `APP_INSTALL_TOKEN`, voulu) | — |
| Connexion avec un compte super administrateur de développement | 302 vers `/backoffice/dashboard`, puis 200 | — |
| `composer dev` | **non concluant** : aucun service démarré en 50 s | — |

**Non testé** : installation sous macOS ou Linux, MariaDB, hôte virtuel Laragon (`*.test`), tests
PHPUnit et E2E sur le clone (voir [tests.md](../developpement/tests.md)).
