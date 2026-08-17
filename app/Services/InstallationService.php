<?php

namespace App\Services;

use App\Actions\Fortify\PasswordValidationRules;
use App\Enums\DomaineActivite;
use App\Models\AppInstallation;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\User;
use App\Models\UserAuthIdentity;
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
 *
 * Le premier site de l'organisation N'EST PLUS créé ici (cf. creerPremierSite()) : install()
 * produit une organisation sans aucun site, complétée ensuite via l'onboarding post-connexion
 * (OnboardingSiteController, gardé par le middleware EnsureOrganizationHasSite).
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
     * Verrou d'accès à /install (web uniquement — la CLI `app:install` passe par install(), qui
     * porte désormais son propre verrou métier, cf. son docblock). En on_premise, une seule
     * organisation jamais plus : dès qu'une installation existe, l'assistant se ferme. En saas,
     * jamais verrouillé : chaque visite peut créer une nouvelle organisation.
     */
    public function isLocked(): bool
    {
        return ! $this->isSaas() && $this->isInstalled();
    }

    /**
     * Lecture seule, sans effet de bord — contrairement à l'ancien resolveOrganization() public,
     * ne crée JAMAIS d'organisation. Utilisé par InstallApp pour le pré-check avant même d'avoir
     * demandé le domaine/téléphone/mot de passe : créer l'organisation à ce stade (comme le
     * faisait l'ancienne implémentation) la laisserait orpheline en base si l'installation
     * échouait ensuite (téléphone invalide, mot de passe rejeté...), la création réelle
     * n'ayant plus lieu que dans la transaction de install().
     */
    public function hasSuperAdminForName(string $nom): bool
    {
        RolesAndPermissionsSeeder::seedRolesEtPermissions();

        $org = Organization::whereRaw('LOWER(name) = ?', [mb_strtolower(trim($nom))])->first();

        return $org !== null && $org->users()->role('super_admin')->exists();
    }

    /**
     * Résout l'organisation à installer — comportement volontairement différent selon le mode,
     * car le nom ne peut PAS servir d'identité technique en SaaS (deux entreprises indépendantes
     * peuvent légitimement porter le même nom commercial) :
     *
     * - saas : crée systématiquement une NOUVELLE organisation, jamais de réutilisation par nom.
     *   Si un besoin de reprendre une installation SaaS interrompue apparaît un jour, ce sera un
     *   mécanisme dédié (ex: lié au téléphone de l'admin), pas une recherche par nom.
     * - on_premise : réutilise une organisation existante de même nom (insensible à la casse) —
     *   c'est ce qui permet de reprendre une installation interrompue, ou de recréer un
     *   super_admin après `php artisan accounts:purge` (cf. procédure formation du README) sans
     *   dupliquer l'organisation. Sans risque de fusionner deux entreprises distinctes : le
     *   verrou plus bas dans install() garantit qu'il n'existe jamais qu'une seule organisation
     *   en on_premise.
     */
    private function resolveOrganization(string $nom, DomaineActivite $domaine): Organization
    {
        $nom = trim($nom);

        if ($this->isSaas()) {
            return Organization::create([
                'name' => $nom,
                'slug' => $this->generateUniqueSlug($nom),
                'is_active' => true,
                'domaine_activite' => $domaine,
            ]);
        }

        $existing = Organization::whereRaw('LOWER(name) = ?', [mb_strtolower($nom)])->first();
        if ($existing) {
            // Ne réécrit jamais un domaine déjà renseigné (donnée métier établie) — ne complète
            // que si l'organisation existante n'en avait pas encore (ex: créée avant l'ajout de
            // ce champ, ou par ProductionSeeder).
            if ($existing->domaine_activite === null) {
                $existing->update(['domaine_activite' => $domaine]);
            }

            return $existing;
        }

        return Organization::create([
            'name' => $nom,
            'slug' => $this->generateUniqueSlug($nom),
            'is_active' => true,
            'domaine_activite' => $domaine,
        ]);
    }

    private function resolveDomaine(?string $value): DomaineActivite
    {
        $domaine = $value !== null ? DomaineActivite::tryFrom($value) : null;

        if ($domaine === null) {
            throw ValidationException::withMessages([
                'organisation.domaine' => "Le domaine d'activité est obligatoire.",
            ]);
        }

        return $domaine;
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
     * rôles/permissions, super_admin, catalogue de départ, marquage installed_at — tout ou rien.
     * installed_at n'est jamais renseigné si une étape échoue en cours de route.
     *
     * Le catalogue de départ (catégories, options, types de véhicule) n'est plus un choix : il
     * est désormais créé systématiquement, comme ProduitTypeDefaultSeeder l'était déjà — une
     * organisation fraîche part toujours avec le même socle, modifiable/supprimable ensuite via
     * les CRUD. Valable en on_premise comme en saas.
     *
     * Verrou on-premise : "1 instance on-premise = 1 organisation" est désormais garanti ICI,
     * pas seulement par InstallWizardController::isLocked() (web) — donc également respecté par
     * `php artisan app:install`, qui n'a jamais été concerné par isLocked(). On ne peut pas se
     * contenter de tester isInstalled() (qui ne bloquerait pas une deuxième organisation tant que
     * la première installation n'est pas allée à son terme) : on compare directement le nombre
     * d'organisations à celle qu'on vient de résoudre, pour aussi couvrir le cas d'une
     * organisation créée mais jamais finalisée (pas encore de super_admin).
     *
     * @param  array{nom: string, domaine: string}  $organisation
     * @param  array{prenom: string, nom: string, telephone: string, email: ?string, password: string, password_confirmation?: string}  $admin
     *
     * @throws ValidationException
     */
    public function install(array $organisation, array $admin): Organization
    {
        return DB::transaction(function () use ($organisation, $admin) {
            RolesAndPermissionsSeeder::seedRolesEtPermissions();

            $domaine = $this->resolveDomaine($organisation['domaine'] ?? null);
            $org = $this->resolveOrganization($organisation['nom'], $domaine);

            if (! $this->isSaas() && Organization::where('id', '!=', $org->id)->exists()) {
                throw ValidationException::withMessages([
                    'organisation.nom' => 'Cette instance est déjà associée à une autre organisation — impossible d\'en créer une seconde en mode on-premise.',
                ]);
            }

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

            if (UserAuthIdentity::resoudre(UserAuthIdentity::TYPE_TELEPHONE, Personne::normaliserTelephone($telephoneInfo['telephone'])) !== null) {
                throw ValidationException::withMessages([
                    'admin.telephone' => 'Ce numéro est déjà utilisé par un autre compte.',
                ]);
            }

            $this->validatePassword(
                $admin['password'],
                $admin['password_confirmation'] ?? $admin['password'],
                'admin.password',
            );

            // Identité de l'admin saisie une seule fois — cf. Personne::resoudreOuCreer() : ne
            // recrée jamais un doublon si une Personne existe déjà dans cette organisation pour
            // ce téléphone (ex: reprise d'installation on-premise interrompue).
            $personne = Personne::resoudreOuCreer($org->id, [
                'nom' => $admin['nom'],
                'prenom' => $admin['prenom'],
                'telephone' => $telephoneInfo['telephone'],
                'code_pays' => $telephoneInfo['code_pays'],
                'pays' => $telephoneInfo['pays'],
                'code_phone_pays' => $telephoneInfo['indicatif'],
                'email' => $admin['email'] ?: null,
            ]);

            $user = User::create([
                'organization_id' => $org->id,
                'personne_id' => $personne->id,
                'password' => $admin['password'],
            ]);
            $user->authIdentities()->create([
                'type' => UserAuthIdentity::TYPE_TELEPHONE,
                'value' => $telephoneInfo['telephone'],
                'normalized_value' => Personne::normaliserTelephone($telephoneInfo['telephone']),
                'verified_at' => now(),
                'is_primary' => true,
            ]);
            if ($admin['email'] ?? null) {
                $user->authIdentities()->create([
                    'type' => UserAuthIdentity::TYPE_EMAIL,
                    'value' => $admin['email'],
                    'normalized_value' => UserAuthIdentity::normaliser(UserAuthIdentity::TYPE_EMAIL, $admin['email']),
                    'verified_at' => now(),
                ]);
            }
            $user->syncRoles(['super_admin']);
            app(MatriculeService::class)->assignForUser($user);

            // Propriétaire interne par défaut de l'organisation (véhicules "interne" et
            // commissions propriétaire associées, cf. Organization::proprietaireInterne()) —
            // réutilise la Personne du super_admin qui vient d'être saisie une seule fois,
            // plutôt que de la redemander : dans l'immense majorité des cas, la personne qui
            // installe l'application EST la propriétaire de l'entreprise. Fiche Proprietaire
            // distincte du compte User (même personne_id, rattachée aussi via user_id pour
            // l'accès rapide) : le propriétaire économique reste stable même si l'admin
            // connecté change plus tard (cf. Organization::proprietaire_interne_id).
            if (! $org->proprietaire_interne_id) {
                $proprietaireInterne = Proprietaire::create([
                    'organization_id' => $org->id,
                    'user_id' => $user->id,
                    'personne_id' => $personne->id,
                    'is_active' => true,
                ]);
                $org->forceFill(['proprietaire_interne_id' => $proprietaireInterne->id])->save();
            }

            // Socle systématique pour toute organisation fraîche, adapté au domaine d'activité
            // qui vient d'être résolu — cf. chaque seeder pour le détail de la préconfiguration.
            // Les produits eux-mêmes ne sont jamais seedés ici : à créer manuellement après
            // installation.
            ProduitTypeDefaultSeeder::seedPourOrganisation($org->id);
            CategorieDefaultSeeder::seedPourOrganisation($org->id, $domaine);
            // Bibliothèque d'options volontairement universelle (cf. docblock du seeder) : les
            // options usuelles (Couleur, Taille, Volume, Poids...) sont réutilisables quel que
            // soit le domaine, contrairement aux catégories qui, elles, sont propres au métier.
            OptionCatalogueDefaultSeeder::seedPourOrganisation($org->id);
            TypeVehiculesSeeder::seedPourOrganisation($org->id);

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

    /**
     * Crée le premier site d'une organisation, depuis l'onboarding post-connexion
     * (OnboardingSiteController) — le site n'est plus créé pendant install() (cf. docblock de
     * classe). Attache l'utilisateur (le super_admin qui vient de s'installer, dans l'immense
     * majorité des cas) à ce site comme site par défaut, même pattern que
     * UserController::store()/UserInvitationService::accepter() — sans ça, `default_site` resterait
     * vide côté frontend (cf. HandleInertiaRequests::defaultSite()) alors qu'un site vient
     * d'être créé.
     *
     * @param  array{nom: string, type: string, ville: string, quartier: string, localisation: ?string, telephone: ?string}  $data
     */
    public function creerPremierSite(User $user, array $data): Site
    {
        return DB::transaction(function () use ($user, $data) {
            $site = Site::create([
                'organization_id' => $user->organization_id,
                'nom' => trim($data['nom']),
                'type' => $data['type'],
                'ville' => trim($data['ville']),
                'quartier' => trim($data['quartier']),
                'localisation' => $data['localisation'] ?? null,
                'telephone' => $data['telephone'] ?? null,
            ]);

            $user->sites()->syncWithoutDetaching([$site->id => ['role' => 'employe', 'is_default' => true]]);

            return $site;
        });
    }
}
