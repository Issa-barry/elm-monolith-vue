<?php

namespace App\Services;

use App\Actions\Fortify\PasswordValidationRules;
use App\Enums\SiteType;
use App\Models\AppInstallation;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CategorieDefaultSeeder;
use Database\Seeders\OptionCatalogueDefaultSeeder;
use Database\Seeders\ProduitTypeDefaultSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TypeVehiculesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Point d'entrée UNIQUE pour l'installation initiale de l'application — utilisé aussi bien par
 * `php artisan app:install` (InstallApp) que par l'assistant web (InstallWizardController).
 * Toute règle métier de l'installation (organisation, super_admin, catalogue de départ, marquage
 * installed_at) vit ici et nulle part ailleurs, pour que CLI et web ne puissent jamais diverger.
 */
class InstallationService
{
    use PasswordValidationRules;

    public function isInstalled(): bool
    {
        return AppInstallation::isInstalled();
    }

    public function isSaas(): bool
    {
        return config('app.deployment_mode') === 'saas';
    }

    /**
     * Verrou d'accès à /install (web uniquement — la CLI `app:install` s'appuie sur
     * hasSuperAdmin() par organisation et n'est jamais concernée par ce verrou). En on_premise,
     * une seule organisation jamais plus : dès qu'une installation existe, l'assistant se ferme.
     * En saas, jamais verrouillé : chaque visite peut créer une nouvelle organisation.
     */
    public function isLocked(): bool
    {
        return ! $this->isSaas() && $this->isInstalled();
    }

    /**
     * Le slug technique n'est jamais demandé à l'installation — généré automatiquement à
     * partir du nom, modifiable ensuite dans les paramètres de l'organisation (backoffice).
     * Une organisation existante portant exactement le même nom (insensible à la casse) est
     * réutilisée telle quelle plutôt que recréée — c'est ce qui rend `install()` idempotent
     * pour une même entreprise (ex: relancer l'installation après une interruption).
     */
    public function resolveOrganization(string $nom): Organization
    {
        $nom = trim($nom);

        $existing = Organization::whereRaw('LOWER(name) = ?', [mb_strtolower($nom)])->first();
        if ($existing) {
            return $existing;
        }

        return Organization::create([
            'name' => $nom,
            'slug' => $this->generateUniqueSlug($nom),
            'is_active' => true,
        ]);
    }

    private function generateUniqueSlug(string $nom): string
    {
        $base = Str::slug($nom) ?: 'organisation';
        $slug = $base;
        $i = 2;

        while (Organization::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    public function hasSuperAdmin(Organization $org): bool
    {
        RolesAndPermissionsSeeder::seedRolesEtPermissions();

        return $org->users()->role('super_admin')->exists();
    }

    /**
     * @return array{telephone: string, code_pays: string, indicatif: string, pays: string, devise: ?string, fuseau: ?string}|null
     */
    public function resolveTelephone(string $telephone): ?array
    {
        return PhoneCountryInfo::resolve($telephone);
    }

    /**
     * $field permet au CLI (clé plate "password") et au wizard web (clé imbriquée
     * "admin.password", pour que l'erreur remonte sur le bon champ du formulaire Vue) de
     * partager cette même validation sans se marcher dessus.
     */
    public function validatePassword(string $password, string $confirmation, string $field = 'password'): void
    {
        // data_set() construit un tableau réellement imbriqué à partir du chemin en notation
        // pointée ("admin.password" → ['admin' => ['password' => ...]]) — une simple clé
        // "admin.password" au premier niveau ne serait PAS résolue par le Validator, qui
        // interprète les points comme de l'imbrication, pas comme des caractères littéraux.
        $data = [];
        data_set($data, $field, $password);
        data_set($data, "{$field}_confirmation", $confirmation);

        Validator::make($data, [$field => $this->passwordRules()])->validate();
    }

    /**
     * Exécute l'installation complète dans une transaction : organisation (créée ou réutilisée),
     * rôles/permissions, super_admin, site siège, catalogue de départ, marquage installed_at —
     * tout ou rien. installed_at n'est jamais renseigné si une étape échoue en cours de route.
     *
     * Le catalogue de départ (catégories, options, types de véhicule) n'est plus un choix : il
     * est désormais créé systématiquement, comme ProduitTypeDefaultSeeder l'était déjà — une
     * organisation fraîche part toujours avec le même socle, modifiable/supprimable ensuite via
     * les CRUD. Valable en on_premise comme en saas.
     *
     * @param  array{nom: string}  $organisation
     * @param  array{prenom: string, nom: string, telephone: string, email: ?string, password: string}  $admin
     * @param  array{ville: string, quartier: string}  $siege
     *
     * @throws ValidationException
     */
    public function install(array $organisation, array $admin, array $siege): Organization
    {
        return DB::transaction(function () use ($organisation, $admin, $siege) {
            RolesAndPermissionsSeeder::seedRolesEtPermissions();

            $org = $this->resolveOrganization($organisation['nom']);

            if ($org->users()->role('super_admin')->exists()) {
                throw ValidationException::withMessages([
                    'organisation.nom' => "Cette entreprise (« {$org->name} ») a déjà un compte super_admin — installation déjà faite.",
                ]);
            }

            if (trim($siege['ville'] ?? '') === '') {
                throw ValidationException::withMessages([
                    'siege.ville' => 'La ville du siège est obligatoire.',
                ]);
            }
            if (trim($siege['quartier'] ?? '') === '') {
                throw ValidationException::withMessages([
                    'siege.quartier' => 'Le quartier du siège est obligatoire.',
                ]);
            }

            $telephoneInfo = $this->resolveTelephone($admin['telephone']);
            if ($telephoneInfo === null) {
                throw ValidationException::withMessages([
                    'admin.telephone' => 'Numéro invalide ou pays non déterminable. Utilisez le format international (ex: +224622000000).',
                ]);
            }

            if (User::where('telephone', $telephoneInfo['telephone'])->exists()) {
                throw ValidationException::withMessages([
                    'admin.telephone' => 'Ce numéro est déjà utilisé par un autre compte.',
                ]);
            }

            $this->validatePassword(
                $admin['password'],
                $admin['password_confirmation'] ?? $admin['password'],
                'admin.password',
            );

            $user = User::create([
                'organization_id' => $org->id,
                'prenom' => $admin['prenom'],
                'nom' => $admin['nom'],
                'telephone' => $telephoneInfo['telephone'],
                'code_pays' => $telephoneInfo['code_pays'],
                'pays' => $telephoneInfo['pays'],
                'code_phone_pays' => $telephoneInfo['indicatif'],
                'email' => $admin['email'] ?: null,
                'email_verified_at' => now(),
                'password' => $admin['password'],
            ]);
            $user->syncRoles(['super_admin']);
            app(MatriculeService::class)->assignForUser($user);

            // Socle systématique pour toute organisation fraîche — plus un choix (ancien wizard
            // "Catalogue initial" retiré) : catégories, options et types de véhicule sont
            // toujours créés, au même titre que ProduitTypeDefaultSeeder ci-dessous (obligatoire
            // car un produit ne peut pas exister sans type). Modifiable/supprimable ensuite via
            // les CRUD respectifs. Les produits eux-mêmes ne sont jamais seedés ici : à créer
            // manuellement après installation.
            ProduitTypeDefaultSeeder::seedPourOrganisation($org->id);
            CategorieDefaultSeeder::seedPourOrganisation($org->id);
            OptionCatalogueDefaultSeeder::seedPourOrganisation($org->id);
            TypeVehiculesSeeder::seedPourOrganisation($org->id);

            // Site siège unique créé à l'installation, à partir de l'adresse saisie — les autres
            // sites (usines, dépôts, agences) sont ajoutés ensuite par l'organisation elle-même
            // (CRUD Sites ou import flotte), jamais seedés. Téléphone repris du super_admin
            // (aucun numéro dédié au siège n'est demandé à l'installation) — modifiable ensuite
            // comme le reste depuis la fiche Site. firstOrCreate : un réinstall après
            // interruption (organisation existante, pas encore de super_admin) ne duplique pas
            // le siège si l'étape avait déjà été atteinte une première fois.
            Site::firstOrCreate(
                ['organization_id' => $org->id, 'nom' => 'Siège'],
                [
                    'type' => SiteType::SIEGE,
                    'ville' => trim($siege['ville']),
                    'quartier' => trim($siege['quartier']),
                    'pays' => $telephoneInfo['pays'] ?? null,
                    'telephone' => $telephoneInfo['telephone'],
                ],
            );

            // Une ligne par installation (pas un updateOrCreate([]) qui écraserait toujours la
            // même) : en saas, /install peut être rejoué pour créer plusieurs organisations —
            // cette table sert alors d'historique/audit. En on_premise il n'y en aura jamais
            // qu'une, puisque isLocked() ferme /install dès la première ligne insérée.
            AppInstallation::create([
                'organization_id' => $org->id,
                'installed_at' => now(),
            ]);

            return $org;
        });
    }
}
