<?php

namespace App\Console\Commands;

use App\Actions\Fortify\PasswordValidationRules;
use App\Enums\CategorieStatut;
use App\Models\Categorie;
use App\Models\Organization;
use App\Models\User;
use App\Services\MatriculeService;
use App\Services\PhoneCountryInfo;
use Database\Seeders\CategorieDefaultSeeder;
use Database\Seeders\OptionCatalogueDefaultSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Première initialisation d'une organisation : crée (ou réutilise) l'organisation, le premier
 * compte super_admin, et optionnellement un jeu de catégories/options de départ.
 *
 * Remplace l'ancien SuperAdminSeeder (retiré) : le mot de passe n'est plus jamais affiché en
 * clair — saisi en masqué, puis le compte doit le redéfinir lui-même à la première connexion
 * (must_change_password, cf. EnsurePasswordIsNotExpired).
 *
 * Idempotent par organisation : si le slug donné existe déjà (ex: "elm" déjà créée par
 * ProductionSeeder), l'organisation est réutilisée telle quelle plutôt que recréée — ce qui
 * permet d'enchaîner `db:seed --class=ProductionSeeder` puis `app:install` sans conflit (cf.
 * README). Refuse en revanche de créer un second super_admin pour une organisation qui en a
 * déjà un — pas de --force pour contourner ce garde-fou (créer un compte supplémentaire, si
 * vraiment nécessaire, se fait via le flux d'invitation normal de l'application).
 */
class InstallApp extends Command
{
    use PasswordValidationRules;

    protected $signature = 'app:install';

    protected $description = "Initialise une organisation (et son premier compte super_admin) — première mise en route de l'application";

    public function handle(): int
    {
        $this->line('========================================');
        $this->line(' Installation de l\'application');
        $this->line('========================================');
        $this->newLine();

        $org = $this->resolveOrganization();
        if ($org === null) {
            return self::FAILURE;
        }

        // Avant toute vérification par rôle : sans ça, role('super_admin') lève
        // RoleDoesNotExist sur une base fraîche où le rôle n'existe pas encore.
        RolesAndPermissionsSeeder::seedRolesEtPermissions();

        if ($org->users()->role('super_admin')->exists()) {
            $this->error("Cette organisation (« {$org->name} ») a déjà un compte super_admin — installation déjà faite.");
            $this->line("Pour ajouter d'autres comptes, utilisez le flux d'invitation de l'application.");

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('----------------------------------------');
        $this->line('Super Administrateur');
        $this->line('----------------------------------------');
        $this->newLine();

        $prenom = $this->ask('Prénom');
        $nom = $this->ask('Nom');
        $telephoneInfo = $this->askTelephone();
        $email = $this->ask('Email (facultatif)') ?: null;
        $password = $this->askPassword();

        $this->newLine();
        $this->info('Création...');
        $this->line('✓ Rôles et permissions initialisés');

        $user = User::create([
            'organization_id' => $org->id,
            'prenom' => $prenom,
            'nom' => $nom,
            'telephone' => $telephoneInfo['telephone'],
            'code_pays' => $telephoneInfo['code_pays'],
            'pays' => $telephoneInfo['pays'],
            'code_phone_pays' => $telephoneInfo['indicatif'],
            'email' => $email,
            'email_verified_at' => now(),
            'password' => $password,
            'must_change_password' => true,
        ]);
        $user->syncRoles(['super_admin']);
        app(MatriculeService::class)->assignForUser($user);
        $this->line('✓ Super Admin créé');

        $this->seedDonneesParDefaut($org);

        $this->newLine();
        $this->line('========================================');
        $this->line(' Installation terminée');
        $this->line('========================================');
        $this->newLine();
        $this->line('Super Admin :');
        $this->line("Téléphone : {$telephoneInfo['telephone']}");
        $this->newLine();
        $this->warn("Pour des raisons de sécurité, aucun mot de passe n'est affiché dans le terminal.");
        $this->warn('Le Super Admin devra définir/modifier son mot de passe lors de sa première connexion.');

        return self::SUCCESS;
    }

    private function resolveOrganization(): ?Organization
    {
        $nom = $this->ask('Nom de l\'organisation');
        $slug = $this->ask('Slug');

        if (! $nom || ! $slug) {
            $this->error('Le nom et le slug sont obligatoires.');

            return null;
        }

        $slug = mb_strtolower(trim($slug));

        $existing = Organization::where('slug', $slug)->first();
        if ($existing) {
            $this->newLine();
            $this->comment("Organisation « {$existing->name} » (slug: {$slug}) trouvée — utilisation de celle-ci.");

            return $existing;
        }

        $org = Organization::create(['name' => $nom, 'slug' => $slug, 'is_active' => true]);
        $this->line('✓ Organisation créée');

        return $org;
    }

    /**
     * Boucle jusqu'à obtenir un numéro international valide, résolu via PhoneCountryInfo
     * (giggsey/libphonenumber-for-php) — jamais de sélection manuelle de pays/indicatif.
     *
     * @return array{telephone: string, code_pays: string, indicatif: string, pays: string, devise: ?string, fuseau: ?string}
     */
    private function askTelephone(): array
    {
        while (true) {
            $saisie = $this->ask('Téléphone (format international, ex: +224622000000)');
            $info = $saisie ? PhoneCountryInfo::resolve($saisie) : null;

            if ($info === null) {
                $this->error('Numéro invalide ou pays non déterminable. Utilisez le format international (ex: +224622000000, +33612345678).');

                continue;
            }

            if (User::where('telephone', $info['telephone'])->exists()) {
                $this->error('Ce numéro est déjà utilisé par un autre compte.');

                continue;
            }

            $this->newLine();
            $this->line('✓ Numéro valide');
            $this->line("✓ Pays détecté : {$info['pays']}");
            $this->line("✓ Indicatif : {$info['indicatif']}");
            $this->line('✓ Devise : '.($info['devise'] ?? 'non déterminée'));
            $this->line('✓ Fuseau horaire : '.($info['fuseau'] ?? 'non déterminé'));
            $this->newLine();

            return $info;
        }
    }

    /**
     * Saisie masquée (jamais affichée, jamais loguée) + confirmation — cf. Password::defaults()
     * dans AppServiceProvider pour les règles de complexité (min 8, majuscule+minuscule, symbole).
     */
    private function askPassword(): string
    {
        while (true) {
            $password = $this->secret('Mot de passe (min. 8 caractères, majuscule + minuscule + symbole)');
            $confirmation = $this->secret('Confirmer le mot de passe');

            $validator = Validator::make(
                ['password' => $password, 'password_confirmation' => $confirmation],
                ['password' => $this->passwordRules()]
            );

            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $message) {
                    $this->error($message);
                }

                continue;
            }

            return $password;
        }
    }

    private function seedDonneesParDefaut(Organization $org): void
    {
        $this->newLine();
        if (! $this->confirm('Voulez-vous installer les données par défaut (catégories, options) ?', true)) {
            return;
        }

        $choix = [
            1 => 'Distribution d\'eau (Boissons > Eau / Sachet / Bouteille)',
            2 => 'Commerce / POS (catalogue générique : vêtements, chaussures, boissons...)',
            3 => 'Installation minimale (aucune donnée catégorie/option)',
        ];
        $reponse = $this->choice('Choisissez un modèle', $choix, 1);

        // Selon le contexte d'exécution (terminal réel vs commande testée), choice() peut
        // renvoyer soit la clé saisie ("1"), soit le libellé complet résolu — on gère les deux
        // plutôt que de supposer l'un ou l'autre.
        $preset = array_search($reponse, $choix, true);
        if ($preset === false) {
            $preset = is_numeric($reponse) ? (int) $reponse : 1;
        }

        if ($preset === 3) {
            return;
        }

        if ($preset === 1) {
            $this->seedPresetDistributionEau($org);
        } else {
            CategorieDefaultSeeder::seedPourOrganisation($org->id);
        }

        OptionCatalogueDefaultSeeder::seedPourOrganisation($org->id);
        $this->line('✓ Catégories créées');
        $this->line('✓ Options créées');
    }

    /**
     * Sous-ensemble volontairement restreint de CategorieDefaultSeeder::ARBRE (qui mélange
     * plusieurs verticales métier) — juste Boissons > Eau/Sachet/Bouteille, pour un déploiement
     * réellement dédié à la distribution d'eau plutôt qu'un catalogue générique multi-métiers.
     */
    private function seedPresetDistributionEau(Organization $org): void
    {
        $parent = Categorie::firstOrCreate(
            ['organization_id' => $org->id, 'nom' => 'Boissons', 'parent_id' => null],
            ['statut' => CategorieStatut::ACTIF]
        );

        foreach (['Eau', 'Sachet', 'Bouteille'] as $nom) {
            Categorie::firstOrCreate(
                ['organization_id' => $org->id, 'nom' => $nom, 'parent_id' => $parent->id],
                ['statut' => CategorieStatut::ACTIF]
            );
        }
    }
}
