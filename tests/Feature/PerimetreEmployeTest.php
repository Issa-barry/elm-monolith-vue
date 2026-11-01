<?php

namespace Tests\Feature;

use App\Models\Contrat;
use App\Models\Employe;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\DesigneUnPdg;
use Tests\TestCase;

/**
 * Fiche employé et contrats limités au périmètre d'agence (App\Support\Rh\PerimetreEmploye,
 * ADR 0025 / 0034), comme les listes : consulter = agences de rattachement ou vision de toutes les
 * agences ; modifier = agences de rattachement, sauf administrateur ou superviseur.
 */
class PerimetreEmployeTest extends TestCase
{
    use DesigneUnPdg;
    use RefreshDatabase;

    private const DROITS = [
        'rh-employes.read', 'rh-employes.update', 'rh-employes.delete',
        'rh-contrats.read', 'rh-contrats.create', 'rh-contrats.update', 'rh-contrats.delete',
    ];

    private Organization $org;

    private Site $matoto;

    private Site $cba;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->org = Organization::factory()->create();
        $this->matoto = Site::create(['organization_id' => $this->org->id, 'nom' => 'Matoto', 'type' => 'depot']);
        $this->cba = Site::create(['organization_id' => $this->org->id, 'nom' => 'CBA', 'type' => 'depot']);
    }

    private function utilisateur(Site $site, array $permissions = self::DROITS, string $role = 'manager'): User
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole($role);
        $user->givePermissionTo($permissions);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function employe(?Site $site): Employe
    {
        $personne = Personne::create(['organization_id' => $this->org->id, 'nom' => 'SOW', 'prenom' => 'Mamadou',
            'telephone' => '+22462'.fake()->unique()->numerify('#######')]);

        return Employe::create(['organization_id' => $this->org->id, 'personne_id' => $personne->id,
            'matricule' => fake()->unique()->numerify('######'), 'type_employe' => 'interne', 'statut' => 'actif', 'site_id' => $site?->id]);
    }

    private function contrat(Employe $employe): Contrat
    {
        return Contrat::create(['organization_id' => $this->org->id, 'employe_id' => $employe->id, 'type_contrat' => 'cdi',
            'date_debut' => '2025-01-01', 'salaire_base' => 500000, 'statut_contrat' => 'actif']);
    }

    private function payload(): array
    {
        return ['nom' => 'SOW', 'prenom' => 'Mamadou', 'type_employe' => 'interne', 'statut' => 'actif'];
    }

    public function test_un_gestionnaire_d_une_autre_agence_ne_voit_ni_ne_modifie_la_fiche(): void
    {
        $employe = $this->employe($this->matoto);
        $contrat = $this->contrat($employe);
        $cba = $this->utilisateur($this->cba);

        $this->actingAs($cba)->get(route('employes.show', $employe))->assertForbidden();
        $this->actingAs($cba)->get(route('employes.edit', $employe))->assertForbidden();
        $this->actingAs($cba)->put(route('employes.update', $employe), $this->payload())->assertForbidden();
        $this->actingAs($cba)->patch(route('employes.transferer-site', $employe), ['site_id' => $this->cba->id])->assertForbidden();
        $this->actingAs($cba)->delete(route('employes.destroy', $employe))->assertForbidden();
        $this->actingAs($cba)->get(route('contrats.edit', $contrat))->assertForbidden();
        $this->actingAs($cba)->put(route('contrats.update', $contrat), [
            'employe_id' => $employe->id, 'type_contrat' => 'cdi', 'date_debut' => '2025-01-01',
        ])->assertForbidden();
        $this->actingAs($cba)->delete(route('contrats.destroy', $contrat))->assertForbidden();
        $contrat->update(['statut_contrat' => 'termine', 'date_fin' => '2025-12-31']);
        $this->actingAs($cba)->post(route('contrats.store'), [
            ...$this->conditionsContrat(),
            'employe_id' => $employe->id, 'type_contrat' => 'cdi', 'date_debut' => '2026-01-01',
        ])->assertForbidden();

        $this->assertNull($employe->fresh()->deleted_at);
        $this->assertSame($this->matoto->id, $employe->fresh()->site_id);
        $this->assertSame(1, Contrat::count());
    }

    public function test_le_gestionnaire_de_l_agence_du_salarie_garde_ses_acces(): void
    {
        $employe = $this->employe($this->matoto);
        $matoto = $this->utilisateur($this->matoto);

        $this->actingAs($matoto)->get(route('employes.show', $employe))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('employe.dans_perimetre_ecriture', true));
        $this->actingAs($matoto)->get(route('employes.edit', $employe))->assertOk();
        $this->actingAs($matoto)->put(route('employes.update', $employe), $this->payload())->assertSessionHasNoErrors();
    }

    public function test_la_vision_de_toutes_les_agences_ouvre_la_lecture_pas_la_modification(): void
    {
        $employe = $this->employe($this->matoto);
        $lecteur = $this->utilisateur($this->cba, [...self::DROITS, 'sites.lecture_toutes_agences']);

        $this->actingAs($lecteur)->get(route('employes.show', $employe))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('employe.dans_perimetre_ecriture', false));
        $this->actingAs($lecteur)->get(route('employes.edit', $employe))->assertForbidden();
        $this->actingAs($lecteur)->get(route('employes.index'))
            ->assertInertia(fn (Assert $page) => $page->where('employes.0.dans_perimetre_ecriture', false));
    }

    public function test_un_salarie_sans_agence_n_est_visible_qu_avec_la_vision_toutes_agences(): void
    {
        $employe = $this->employe(null);

        $this->actingAs($this->utilisateur($this->matoto))->get(route('employes.show', $employe))->assertForbidden();
        $this->actingAs($this->utilisateur($this->matoto, [...self::DROITS, 'sites.lecture_toutes_agences']))
            ->get(route('employes.show', $employe))->assertOk();
    }

    public function test_un_administrateur_agit_sur_toutes_les_agences_mais_pas_hors_organisation(): void
    {
        $employe = $this->employe($this->matoto);
        $admin = $this->utilisateur($this->cba, self::DROITS, 'admin_entreprise');

        $this->actingAs($admin)->get(route('employes.show', $employe))->assertOk();
        $this->actingAs($admin)->get(route('employes.edit', $employe))->assertOk();

        $autreOrg = Organization::factory()->create();
        $site = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Kaloum', 'type' => 'depot']);
        $personne = Personne::create(['organization_id' => $autreOrg->id, 'nom' => 'BAH', 'prenom' => 'Awa', 'telephone' => '+224621112233']);
        $etranger = Employe::create(['organization_id' => $autreOrg->id, 'personne_id' => $personne->id, 'matricule' => '777777',
            'type_employe' => 'interne', 'statut' => 'actif', 'site_id' => $site->id]);
        $this->actingAs($admin)->get(route('employes.show', $etranger))->assertForbidden();
    }
}
