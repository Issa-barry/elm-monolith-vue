# CI/CD GitHub -> Hostinger (SSH)

Ce projet inclut maintenant un workflow GitHub Actions:
- [`.github/workflows/deploy-hostinger.yml`](.github/workflows/deploy-hostinger.yml)

## Strategie de branches

Flux recommande:
- `dev` -> `pre-prod` -> `main`

Comportement des workflows:
- CI (`.github/workflows/lint.yml` et `.github/workflows/tests.yml`) sur Pull Request vers `pre-prod` et `main` (pas de doublon push/pull_request)
  - `tests.yml`:
    - PR `dev -> pre-prod`: `phpunit` + E2E Playwright complets
    - PR `pre-prod -> main`: `phpunit` + E2E smoke
- Controle du flux (`.github/workflows/branch-flow.yml`):
  - autorise seulement `dev` -> `pre-prod`
  - autorise seulement `pre-prod` -> `main`
- CD (`.github/workflows/deploy-hostinger.yml`) uniquement sur `main` (et manuel via `workflow_dispatch`)

## Ce que fait le pipeline

Sur chaque push sur `main` (ou lancement manuel):
1. `npm ci`
2. `npm run build`
3. Synchronisation des fichiers vers Hostinger via `rsync` (SSH)
4. Sur le serveur:
   - `composer2 install --no-dev` (ou `composer` si `composer2` absent)
   - `php artisan migrate --force`
   - `php artisan optimize:clear`
   - `php artisan optimize`
   - assure `public/storage` (symlink si possible, sinon copie miroir de `storage/app/public`)

## Secrets GitHub a configurer

Dans GitHub: `Settings` -> `Secrets and variables` -> `Actions` -> `New repository secret`

- `HOSTINGER_HOST`: hostname SSH (ex: `123.45.67.89`)
- `HOSTINGER_SSH_PORT`: port SSH (souvent `22` ou celui fourni par Hostinger)
- `HOSTINGER_SSH_USER`: utilisateur SSH
- `HOSTINGER_SSH_PRIVATE_KEY`: cle privee (format OpenSSH)
- `HOSTINGER_DEPLOY_PATH`: dossier de deploiement (ex: `~/domains/eau-la-maman.fr/public_html`)

## Preparation serveur (une seule fois)

1. Creer et configurer le fichier `.env` en production.
2. Verifier que `storage/` et `bootstrap/cache/` sont accessibles en ecriture.
3. Lancer une fois:
   ```bash
   php artisan key:generate
   ln -s "$PWD/storage/app/public" "$PWD/public/storage" || true
   ```
4. Verifier que la base de donnees de production est correcte.

## Important

- Le workflow ne deploie pas `storage/` ni `.env`.
- Le workflow utilise `--delete` avec `rsync`: les fichiers supprimes dans le repo seront supprimes sur le serveur (sauf exclusions).
- Eviter `migrate:fresh --seed` en production.

## Lancer un deploiement manuel

GitHub -> `Actions` -> `deploy-hostinger` -> `Run workflow`.

## Import flotte (Parametres -> Import en masse)

Traitement synchrone (pas de file d'attente) : la confirmation cree
propretaires/vehicules/livreurs/equipes directement dans le cycle de la
requete HTTP. Plafonne a 500 lignes au total (`ImportFlotteParser::MAX_LIGNES`)
pour rester rapide — au-dela, l'utilisateur doit scinder son fichier en
plusieurs imports.

Un import echoue peut etre relance sans risque directement depuis sa fiche
(bouton "Relancer l'import") : `ImportFlotteExecutor` s'execute dans une
transaction globale, donc un echec ne laisse jamais de creation partielle en
base — relancer revient a rejouer la confirmation a partir du meme fichier
deja stocke, sans double creation possible (les entites deja existantes sont
detectees et reutilisees, pas recreees).

Si le volume devait un jour depasser ce plafond, la migration naturelle est le
*job batching* Laravel (`Bus::batch()`, table `job_batches` deja presente
depuis `0001_01_01_000002_create_jobs_tables.php`) plutot qu'un simple job en
file d'attente — cela donnerait une vraie progression consultable
(`processedJobs()`/`totalJobs()`), utile pour un traitement qui prend plus de
quelques secondes. A ce moment-la seulement, il faudra egalement mettre en
place une tache Cron Hostinger (`queue:work --stop-when-empty`), un worker
permanent n'etant pas disponible sur cet hebergement mutualise.
