<?php

namespace App\Services;

use App\Actions\Fortify\PasswordValidationRules;
use App\Models\AppInstallation;
use App\Models\Organization;
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
     * rôles/permissions, super_admin, catalogue de départ optionnel, marquage installed_at — tout
     * ou rien. installed_at n'est jamais renseigné si une étape échoue en cours de route.
     *
     * @param  array{nom: string}  $organisation
     * @param  array{prenom: string, nom: string, telephone: string, email: ?string, password: string}  $admin
     * @param  array{categories: bool, options: bool, types_vehicule: bool}  $catalogue
     *
     * @throws ValidationException
     */
    public function install(array $organisation, array $admin, array $catalogue): Organization
    {
        return DB::transaction(function () use ($organisation, $admin, $catalogue) {
            RolesAndPermissionsSeeder::seedRolesEtPermissions();

            $org = $this->resolveOrganization($organisation['nom']);

            if ($org->users()->role('super_admin')->exists()) {
                throw ValidationException::withMessages([
                    'organisation.nom' => "Cette entreprise (« {$org->name} ») a déjà un compte super_admin — installation déjà faite.",
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

            // Obligatoire et inconditionnel (contrairement aux catalogues ci-dessous) : un
            // produit ne peut pas exister sans type, une organisation ne doit donc jamais rester
            // sans aucun type disponible — cf. docblock de ProduitTypeDefaultSeeder.
            ProduitTypeDefaultSeeder::seedPourOrganisation($org->id);

            if ($catalogue['categories'] ?? false) {
                CategorieDefaultSeeder::seedPourOrganisation($org->id);
            }
            if ($catalogue['options'] ?? false) {
                OptionCatalogueDefaultSeeder::seedPourOrganisation($org->id);
            }
            if ($catalogue['types_vehicule'] ?? false) {
                TypeVehiculesSeeder::seedPourOrganisation($org->id);
            }

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
