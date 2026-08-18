<?php

namespace Tests\Feature;

use App\Enums\DomaineActivite;
use App\Models\AppInstallation;
use App\Models\Categorie;
use App\Models\OptionCatalogue;
use App\Models\Organization;
use App\Models\ProduitType;
use App\Models\TypeVehicule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\Concerns\HasInstallAppHelper;
use Tests\TestCase;

/**
 * `php artisan app:install` — façade CLI de InstallationService (partagée avec /install côté
 * web, cf. InstallWizardTest). Le Super Admin choisit directement son mot de passe (saisie
 * masquée) : plus de mot de passe généré, plus de redéfinition forcée à la première connexion.
 */
class InstallAppTest extends TestCase
{
    use HasInstallAppHelper, RefreshDatabase;

    public function test_installation_cree_organisation_et_super_admin(): void
    {
        $this->runInstall()->assertExitCode(0);

        $org = Organization::where('slug', 'elm-test')->firstOrFail();
        $this->assertSame('ELM Test', $org->name);

        $user = User::whereHas('personne', fn ($q) => $q->where('telephone', '+224622000000'))->firstOrFail();
        $this->assertSame('Issa', $user->prenom);
        $this->assertSame('BARRY', $user->nom);
        $this->assertSame('GN', $user->code_pays);
        $this->assertFalse($user->must_change_password);
        $this->assertTrue($user->hasRole('super_admin'));
        $this->assertSame('issa@gmail.com', $user->email);
        $this->assertTrue($user->hasVerifiedEmail());
    }

    public function test_marque_installed_at_apres_succes(): void
    {
        $this->assertFalse(AppInstallation::isInstalled());

        $this->runInstall()->assertExitCode(0);

        $this->assertTrue(AppInstallation::isInstalled());
    }

    public function test_mot_de_passe_saisi_nest_jamais_affiche_en_sortie(): void
    {
        $this->runInstall(password: 'Sup3r$ecretPwd')
            ->expectsOutputToContain('jamais affiché ni conservé en clair')
            ->assertExitCode(0);

        $user = User::whereHas('personne', fn ($q) => $q->where('telephone', '+224622000000'))->firstOrFail();
        $this->assertTrue(Hash::check('Sup3r$ecretPwd', $user->password));
    }

    public function test_reutilise_une_organisation_existante_au_lieu_de_la_recreer(): void
    {
        Organization::create(['name' => 'ELM Test', 'slug' => 'peu-importe', 'is_active' => true]);

        $this->runInstall()->assertExitCode(0);

        $this->assertSame(1, Organization::where('name', 'ELM Test')->count());
        $this->assertSame('peu-importe', Organization::where('name', 'ELM Test')->first()->slug);
    }

    public function test_genere_automatiquement_un_slug_pour_une_nouvelle_organisation(): void
    {
        $this->runInstall()->assertExitCode(0);

        $this->assertSame('elm-test', Organization::where('name', 'ELM Test')->firstOrFail()->slug);
    }

    public function test_refuse_de_creer_un_second_super_admin_pour_la_meme_organisation(): void
    {
        $org = Organization::create(['name' => 'ELM Test', 'slug' => 'elm-test', 'is_active' => true]);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $existing = User::factory()->create(['organization_id' => $org->id]);
        $existing->assignRole('super_admin');

        $this->artisan('app:install')
            ->expectsQuestion("Nom de l'entreprise", 'ELM Test')
            ->assertExitCode(1);

        $this->assertSame(1, User::where('organization_id', $org->id)->count());
    }

    public function test_refuse_un_telephone_deja_utilise(): void
    {
        $org = Organization::create(['name' => 'ELM Test', 'slug' => 'elm-test', 'is_active' => true]);
        User::factory()->create(['organization_id' => $org->id, 'telephone' => '+224622000000']);

        $this->artisan('app:install')
            ->expectsQuestion("Nom de l'entreprise", 'ELM Test')
            ->expectsQuestion("Domaine d'activité de l'entreprise", DomaineActivite::COMMERCE_DISTRIBUTION->label())
            ->expectsQuestion('Prénom', 'Issa')
            ->expectsQuestion('Nom', 'BARRY')
            ->expectsQuestion('Téléphone (format international, ex: +224622000000)', '+224622000000')
            ->expectsQuestion('Email', 'issa@gmail.com')
            ->expectsQuestion('Code reçu par email (6 chiffres)', '123456')
            ->expectsQuestion('Mot de passe (min. 8 caractères, majuscule + minuscule + symbole)', 'Sup3r$ecretPwd')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('personnes', ['telephone' => '+224622000099']);
        $this->assertFalse(AppInstallation::isInstalled());
    }

    public function test_catalogue_par_defaut_est_toujours_cree(): void
    {
        $this->runInstall()->assertExitCode(0);

        $org = Organization::where('slug', 'elm-test')->firstOrFail();
        $this->assertGreaterThan(0, Categorie::where('organization_id', $org->id)->count());
        $this->assertGreaterThan(0, OptionCatalogue::where('organization_id', $org->id)->count());
        $this->assertSame(5, TypeVehicule::where('organization_id', $org->id)->count());
        $this->assertTrue(TypeVehicule::where('organization_id', $org->id)->where('nom', 'Minibus')->exists());
        $this->assertTrue(ProduitType::where('organization_id', $org->id)->where('code', 'matiere_production')->exists());
    }

    public function test_aucun_site_nest_cree_pendant_linstallation(): void
    {
        $this->runInstall()->assertExitCode(0);

        $org = Organization::where('slug', 'elm-test')->firstOrFail();
        $this->assertSame(0, $org->sites()->count());
    }

    /**
     * Propriétaire interne par défaut créé et rattaché à l'organisation dès l'installation CLI —
     * même comportement que le wizard web (cf. InstallWizardTest, InstallationService::install()).
     */
    public function test_propriétaire_interne_est_cree_et_rattache_a_lorganisation(): void
    {
        $this->runInstall()->assertExitCode(0);

        $org = Organization::where('slug', 'elm-test')->firstOrFail();
        $user = User::whereHas('personne', fn ($q) => $q->where('telephone', '+224622000000'))->firstOrFail();

        $this->assertNotNull($org->proprietaire_interne_id);
        $this->assertSame($user->id, $org->proprietaireInterne->user_id);
        $this->assertSame($org->id, $org->proprietaireInterne->organization_id);
    }

    public function test_le_domaine_dactivite_est_persiste(): void
    {
        $this->runInstall(domaine: DomaineActivite::INDUSTRIE_FABRICATION)->assertExitCode(0);

        $org = Organization::where('slug', 'elm-test')->firstOrFail();
        $this->assertSame(DomaineActivite::INDUSTRIE_FABRICATION, $org->domaine_activite);
    }

    public function test_refuse_une_seconde_organisation_en_on_premise_via_cli(): void
    {
        config(['app.deployment_mode' => 'on_premise']);

        $this->runInstall(orgNom: 'ELM Test')->assertExitCode(0);

        $this->artisan('app:install')
            ->expectsQuestion("Nom de l'entreprise", 'Autre Entreprise')
            ->expectsQuestion("Domaine d'activité de l'entreprise", DomaineActivite::COMMERCE_DISTRIBUTION->label())
            ->expectsQuestion('Prénom', 'Issa')
            ->expectsQuestion('Nom', 'BARRY')
            ->expectsQuestion('Téléphone (format international, ex: +224622000000)', '+224622000099')
            ->expectsQuestion('Email', 'autre@gmail.com')
            ->expectsQuestion('Code reçu par email (6 chiffres)', '123456')
            ->expectsQuestion('Mot de passe (min. 8 caractères, majuscule + minuscule + symbole)', 'Sup3r$ecretPwd')
            ->assertExitCode(1);

        $this->assertSame(1, Organization::count());
    }

    public function test_en_saas_lemail_reste_facultatif_via_cli(): void
    {
        config(['app.deployment_mode' => 'saas']);

        $this->artisan('app:install')
            ->expectsQuestion("Nom de l'entreprise", 'ELM Test')
            ->expectsQuestion("Domaine d'activité de l'entreprise", DomaineActivite::COMMERCE_DISTRIBUTION->label())
            ->expectsQuestion('Prénom', 'Issa')
            ->expectsQuestion('Nom', 'BARRY')
            ->expectsQuestion('Téléphone (format international, ex: +224622000000)', '+224622000000')
            ->expectsQuestion('Email (facultatif)', '')
            ->expectsQuestion('Mot de passe (min. 8 caractères, majuscule + minuscule + symbole)', 'Sup3r$ecretPwd')
            ->assertExitCode(0);

        $user = User::whereHas('personne', fn ($q) => $q->where('telephone', '+224622000000'))->firstOrFail();
        $this->assertNull($user->email);
    }
}
