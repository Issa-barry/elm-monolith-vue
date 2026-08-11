<?php

namespace Tests\Feature;

use App\Models\Categorie;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\Concerns\HasInstallAppHelper;
use Tests\TestCase;

/**
 * `php artisan app:install` — remplace l'ancien SuperAdminSeeder (retiré) : plus de mot de
 * passe affiché en clair, compte forcé à en redéfinir un à la première connexion.
 */
class InstallAppTest extends TestCase
{
    use HasInstallAppHelper, RefreshDatabase;

    public function test_installation_cree_organisation_et_super_admin(): void
    {
        $this->runInstall()->assertExitCode(0);

        $org = Organization::where('slug', 'elm-test')->firstOrFail();
        $this->assertSame('ELM Test', $org->name);

        $user = User::where('telephone', '+224622000000')->firstOrFail();
        $this->assertSame('Issa', $user->prenom);
        $this->assertSame('BARRY', $user->nom);
        $this->assertSame('GN', $user->code_pays);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue($user->hasRole('super_admin'));
        $this->assertTrue($user->hasVerifiedEmail());
    }

    public function test_mot_de_passe_saisi_nest_jamais_affiche_en_sortie(): void
    {
        $this->runInstall(password: 'Sup3r$ecretPwd')
            ->expectsOutputToContain('aucun mot de passe n\'est affiché')
            ->assertExitCode(0);

        $user = User::where('telephone', '+224622000000')->firstOrFail();
        $this->assertTrue(Hash::check('Sup3r$ecretPwd', $user->password));
    }

    public function test_reutilise_une_organisation_existante_au_lieu_de_la_recreer(): void
    {
        Organization::create(['name' => 'Déjà là', 'slug' => 'elm-test', 'is_active' => true]);

        $this->runInstall()->assertExitCode(0);

        $this->assertSame(1, Organization::where('slug', 'elm-test')->count());
        $this->assertSame('Déjà là', Organization::where('slug', 'elm-test')->first()->name);
    }

    public function test_refuse_de_creer_un_second_super_admin_pour_la_meme_organisation(): void
    {
        $org = Organization::create(['name' => 'ELM Test', 'slug' => 'elm-test', 'is_active' => true]);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $existing = User::factory()->create(['organization_id' => $org->id]);
        $existing->assignRole('super_admin');

        $this->artisan('app:install')
            ->expectsQuestion("Nom de l'organisation", 'ELM Test')
            ->expectsQuestion('Slug', 'elm-test')
            ->assertExitCode(1);

        $this->assertSame(1, User::where('organization_id', $org->id)->count());
    }

    public function test_refuse_un_telephone_deja_utilise(): void
    {
        $org = Organization::create(['name' => 'ELM Test', 'slug' => 'elm-test', 'is_active' => true]);
        User::factory()->create(['organization_id' => $org->id, 'telephone' => '+224622000000']);

        $this->artisan('app:install')
            ->expectsQuestion("Nom de l'organisation", 'ELM Test')
            ->expectsQuestion('Slug', 'elm-test')
            ->expectsQuestion('Prénom', 'Issa')
            ->expectsQuestion('Nom', 'BARRY')
            ->expectsQuestion('Téléphone (format international, ex: +224622000000)', '+224622000000')
            ->expectsQuestion('Téléphone (format international, ex: +224622000000)', '+224622000099')
            ->expectsQuestion('Email (facultatif)', '')
            ->expectsQuestion('Mot de passe (min. 8 caractères, majuscule + minuscule + symbole)', 'Sup3r$ecretPwd')
            ->expectsQuestion('Confirmer le mot de passe', 'Sup3r$ecretPwd')
            ->expectsConfirmation('Voulez-vous installer les données par défaut (catégories, options) ?', 'no')
            ->assertExitCode(0);

        $this->assertDatabaseHas('users', ['telephone' => '+224622000099']);
    }

    public function test_preset_distribution_eau_cree_les_categories_attendues(): void
    {
        $this->runInstall(preset: 1)->assertExitCode(0);

        $org = Organization::where('slug', 'elm-test')->firstOrFail();
        $boissons = Categorie::where('organization_id', $org->id)->where('nom', 'Boissons')->firstOrFail();
        $this->assertDatabaseHas('categories', ['organization_id' => $org->id, 'nom' => 'Sachet', 'parent_id' => $boissons->id]);
        $this->assertDatabaseHas('categories', ['organization_id' => $org->id, 'nom' => 'Bouteille', 'parent_id' => $boissons->id]);
        $this->assertDatabaseMissing('categories', ['organization_id' => $org->id, 'nom' => 'Vêtements']);
    }

    public function test_installation_minimale_ne_cree_aucune_categorie(): void
    {
        $this->runInstall(preset: 3)->assertExitCode(0);

        $org = Organization::where('slug', 'elm-test')->firstOrFail();
        $this->assertSame(0, Categorie::where('organization_id', $org->id)->count());
    }
}
