<?php

namespace Tests\Feature;

use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TypeVehicule;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class VehiculeTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['vehicules.read', 'vehicules.create', 'vehicules.update', 'vehicules.delete']);
    }

    private function typeId(): string
    {
        return TypeVehicule::where('organization_id', $this->org->id)->value('id');
    }

    private function makeVehicule(Organization $org): Vehicule
    {
        $typeVehicule = TypeVehicule::factory()->create(['organization_id' => $org->id]);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);
        $site = Site::create([
            'organization_id' => $org->id,
            'nom' => 'Site '.uniqid(),
            'type' => 'depot',
        ]);

        return Vehicule::factory()->create([
            'organization_id' => $org->id,
            'type_vehicule_id' => $typeVehicule->id,
            'proprietaire_id' => $proprietaire->id,
            'categorie' => 'externe',
            'site_id' => $site->id,
        ]);
    }

    /** Utilisateur non-admin, rattaché uniquement à $site — cf. VehiculeController::store()/update(). */
    private function makeNonAdminUser(Organization $org, Site $site): User
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        foreach (['vehicules.read', 'vehicules.create', 'vehicules.update'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('manager');
        $user->givePermissionTo(['vehicules.read', 'vehicules.create', 'vehicules.update']);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('vehicules.index'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated_user(): void
    {
        $this->get(route('vehicules.index'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->get(route('vehicules.index'))
            ->assertStatus(403);
    }

    public function test_index_falls_back_to_type_default_capacity_when_vehicule_has_none(): void
    {
        // cas réel : véhicules créés par l'import flotte, qui ne saisit jamais
        // de capacité propre au véhicule (voir ImportFlotteExecutor).
        $type = TypeVehicule::factory()->create(['organization_id' => $this->org->id, 'capacite_defaut' => 270]);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'type_vehicule_id' => $type->id,
            'proprietaire_id' => $proprietaire->id,
            'categorie' => 'externe',
            'capacite_packs' => null,
        ]);

        $this->actingAs($this->user)
            ->get(route('vehicules.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('vehicules.0.capacite_packs', 270)
            );
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function test_create_returns_200_for_authorized_user(): void
    {
        $site = $this->user->sites()->first();

        $this->actingAs($this->user)
            ->get(route('vehicules.create', ['site_id' => $site->id]))
            ->assertStatus(200);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_vehicule_and_redirects(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $site = $this->user->sites()->first();

        $response = $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion 01',
                'immatriculation' => 'RC-001-GN',
                'type_vehicule_id' => $this->typeId(),
                'categorie' => 'externe',
                'proprietaire_id' => $proprietaire->id,
                'site_id' => $site->id,
                'capacite_packs' => 200,
                'is_active' => true,
                'pris_en_charge_par_usine' => false,
                'commission_eligible' => true,
            ]);

        $vehicule = Vehicule::query()
            ->where('organization_id', $this->org->id)
            ->where('immatriculation', 'RC-001-GN')
            ->firstOrFail();

        $response->assertRedirect(route('vehicules.show', $vehicule));

        $this->assertDatabaseHas('vehicules', [
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'categorie' => 'externe',
        ]);
    }

    public function test_store_creates_vehicule_interne(): void
    {
        $site = $this->user->sites()->first();

        $response = $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Tricycle 01',
                'immatriculation' => 'TC-TEST-GN',
                'type_vehicule_id' => $this->typeId(),
                'categorie' => 'interne',
                'site_id' => $site->id,
                'capacite_packs' => 50,
                'is_active' => true,
                'pris_en_charge_par_usine' => false,
                'commission_eligible' => true,
            ]);

        $vehicule = Vehicule::query()
            ->where('organization_id', $this->org->id)
            ->where('immatriculation', 'TC-TEST-GN')
            ->firstOrFail();

        $response->assertRedirect(route('vehicules.show', $vehicule));

        $this->assertDatabaseHas('vehicules', [
            'organization_id' => $this->org->id,
            'categorie' => 'interne',
            'site_id' => $site->id,
            'proprietaire_id' => null,
        ]);
    }

    public function test_store_fails_sans_site_id(): void
    {
        // Le site est obligatoire quelle que soit la catégorie (interne comme
        // externe) — chaque véhicule est rattaché à un site.
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion Sans Site',
                'immatriculation' => 'RC-NOSITE-GN',
                'type_vehicule_id' => $this->typeId(),
                'categorie' => 'externe',
                'proprietaire_id' => $proprietaire->id,
                'pris_en_charge_par_usine' => false,
                'commission_eligible' => true,
            ])
            ->assertSessionHasErrors('site_id');
    }

    public function test_store_fails_interne_avec_site_autre_org(): void
    {
        $otherOrg = Organization::factory()->create();
        $otherSite = Site::create([
            'organization_id' => $otherOrg->id,
            'nom' => 'Site Autre Org',
            'type' => 'depot',
        ]);

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Tricycle Autre Org',
                'immatriculation' => 'TC-AUTREORG-GN',
                'type_vehicule_id' => $this->typeId(),
                'categorie' => 'interne',
                'site_id' => $otherSite->id,
                'pris_en_charge_par_usine' => true,
                'commission_eligible' => true,
            ])
            ->assertSessionHasErrors('site_id');
    }

    public function test_store_fails_with_empty_data(): void
    {
        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [])
            ->assertSessionHasErrors(['nom_vehicule', 'immatriculation', 'type_vehicule_id', 'categorie', 'site_id', 'pris_en_charge_par_usine', 'commission_eligible']);
    }

    public function test_store_fails_sans_pris_en_charge_par_usine(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion Sans Charge',
                'immatriculation' => 'RC-050-GN',
                'type_vehicule_id' => $this->typeId(),
                'categorie' => 'externe',
                'proprietaire_id' => $proprietaire->id,
                'commission_eligible' => true,
            ])
            ->assertSessionHasErrors('pris_en_charge_par_usine');
    }

    public function test_store_fails_sans_commission_eligible(): void
    {
        // commission_eligible est totalement indépendant de
        // pris_en_charge_par_usine : validé et requis séparément.
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion Sans Eligibilite',
                'immatriculation' => 'RC-054-GN',
                'type_vehicule_id' => $this->typeId(),
                'categorie' => 'externe',
                'proprietaire_id' => $proprietaire->id,
                'pris_en_charge_par_usine' => true,
            ])
            ->assertSessionHasErrors('commission_eligible');
    }

    public function test_store_creates_vehicule_avec_pris_en_charge_oui(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $site = $this->user->sites()->first();

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion Oui',
                'immatriculation' => 'RC-051-GN',
                'type_vehicule_id' => $this->typeId(),
                'categorie' => 'externe',
                'proprietaire_id' => $proprietaire->id,
                'site_id' => $site->id,
                'pris_en_charge_par_usine' => true,
                'commission_eligible' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('vehicules', [
            'immatriculation' => 'RC-051-GN',
            'pris_en_charge_par_usine' => true,
        ]);
    }

    public function test_store_creates_vehicule_avec_pris_en_charge_non(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $site = $this->user->sites()->first();

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion Non',
                'immatriculation' => 'RC-052-GN',
                'type_vehicule_id' => $this->typeId(),
                'categorie' => 'externe',
                'proprietaire_id' => $proprietaire->id,
                'site_id' => $site->id,
                'pris_en_charge_par_usine' => false,
                'commission_eligible' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('vehicules', [
            'immatriculation' => 'RC-052-GN',
            'pris_en_charge_par_usine' => false,
        ]);
    }

    /**
     * Les deux notions sont indépendantes : les 4 combinaisons doivent être
     * acceptées — ici pris_en_charge_par_usine=true mais commission_eligible=false
     * (usine encaisse le plein prix, mais ce véhicule ne génère aucune commission).
     */
    public function test_store_accepte_pris_en_charge_oui_et_commission_eligible_non(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $site = $this->user->sites()->first();

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion Charge Sans Commission',
                'immatriculation' => 'RC-055-GN',
                'type_vehicule_id' => $this->typeId(),
                'categorie' => 'externe',
                'proprietaire_id' => $proprietaire->id,
                'site_id' => $site->id,
                'pris_en_charge_par_usine' => true,
                'commission_eligible' => false,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('vehicules', [
            'immatriculation' => 'RC-055-GN',
            'pris_en_charge_par_usine' => true,
            'commission_eligible' => false,
        ]);
    }

    /** Combinaison inverse : non pris en charge, mais éligible aux commissions. */
    public function test_store_accepte_pris_en_charge_non_et_commission_eligible_oui(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $site = $this->user->sites()->first();

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion Commission Sans Charge',
                'immatriculation' => 'RC-056-GN',
                'type_vehicule_id' => $this->typeId(),
                'categorie' => 'externe',
                'proprietaire_id' => $proprietaire->id,
                'site_id' => $site->id,
                'pris_en_charge_par_usine' => false,
                'commission_eligible' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('vehicules', [
            'immatriculation' => 'RC-056-GN',
            'pris_en_charge_par_usine' => false,
            'commission_eligible' => true,
        ]);
    }

    public function test_store_vehicule_interne_force_pris_en_charge_a_true(): void
    {
        $site = $this->user->sites()->first();

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Interne Charge',
                'immatriculation' => 'TC-053-GN',
                'type_vehicule_id' => $this->typeId(),
                'categorie' => 'interne',
                'site_id' => $site->id,
                'pris_en_charge_par_usine' => false, // ignoré par le backend
                'commission_eligible' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('vehicules', [
            'immatriculation' => 'TC-053-GN',
            'pris_en_charge_par_usine' => true,
        ]);
    }

    /**
     * Contrairement à pris_en_charge_par_usine, commission_eligible n'est
     * jamais forcé par la catégorie interne — reste la valeur soumise.
     */
    public function test_store_vehicule_interne_ne_force_pas_commission_eligible(): void
    {
        $site = $this->user->sites()->first();

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Interne Sans Commission',
                'immatriculation' => 'TC-057-GN',
                'type_vehicule_id' => $this->typeId(),
                'categorie' => 'interne',
                'site_id' => $site->id,
                'pris_en_charge_par_usine' => false,
                'commission_eligible' => false,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('vehicules', [
            'immatriculation' => 'TC-057-GN',
            'pris_en_charge_par_usine' => true,
            'commission_eligible' => false,
        ]);
    }

    public function test_store_fails_with_invalid_type_vehicule(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion Type Invalide',
                'immatriculation' => 'RC-002-GN',
                'type_vehicule_id' => 'invalid-ulid-that-does-not-exist',
                'categorie' => 'externe',
                'proprietaire_id' => $proprietaire->id,
            ])
            ->assertSessionHasErrors('type_vehicule_id');
    }

    public function test_store_fails_with_proprietaire_from_other_org(): void
    {
        $otherOrg = Organization::factory()->create();
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion Autre Org',
                'immatriculation' => 'RC-003-GN',
                'type_vehicule_id' => $this->typeId(),
                'categorie' => 'externe',
                'proprietaire_id' => $proprietaire->id,
            ])
            ->assertSessionHasErrors('proprietaire_id');
    }

    public function test_store_fails_externe_sans_proprietaire(): void
    {
        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion Externe Sans Proprio',
                'immatriculation' => 'RC-004-GN',
                'type_vehicule_id' => $this->typeId(),
                'categorie' => 'externe',
            ])
            ->assertSessionHasErrors('proprietaire_id');
    }

    public function test_store_externe_conserve_site_id(): void
    {
        // Un véhicule externe est aussi rattaché à un site (celui pour lequel
        // il opère), contrairement à l'ancien comportement qui le forçait à null.
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $site = $this->user->sites()->first();

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion Externe',
                'immatriculation' => 'RC-EXT-GN',
                'type_vehicule_id' => $this->typeId(),
                'categorie' => 'externe',
                'proprietaire_id' => $proprietaire->id,
                'site_id' => $site->id,
                'pris_en_charge_par_usine' => false,
                'commission_eligible' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('vehicules', [
            'immatriculation' => 'RC-EXT-GN',
            'categorie' => 'externe',
            'site_id' => $site->id,
        ]);
    }

    public function test_store_fails_for_non_admin_choosing_other_site(): void
    {
        $ownSite = $this->user->sites()->first();
        $otherSite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Autre Site',
            'type' => 'depot',
        ]);
        $nonAdmin = $this->makeNonAdminUser($this->org, $ownSite);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($nonAdmin)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion Non-Admin',
                'immatriculation' => 'RC-NONADMIN-GN',
                'type_vehicule_id' => $this->typeId(),
                'categorie' => 'externe',
                'proprietaire_id' => $proprietaire->id,
                'site_id' => $otherSite->id,
                'pris_en_charge_par_usine' => false,
                'commission_eligible' => true,
            ])
            ->assertStatus(403);

        $this->assertDatabaseMissing('vehicules', ['immatriculation' => 'RC-NONADMIN-GN']);
    }

    public function test_store_succeeds_for_non_admin_using_own_site(): void
    {
        $ownSite = $this->user->sites()->first();
        $nonAdmin = $this->makeNonAdminUser($this->org, $ownSite);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($nonAdmin)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion Non-Admin Ok',
                'immatriculation' => 'RC-NONADMINOK-GN',
                'type_vehicule_id' => $this->typeId(),
                'categorie' => 'externe',
                'proprietaire_id' => $proprietaire->id,
                'site_id' => $ownSite->id,
                'pris_en_charge_par_usine' => false,
                'commission_eligible' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('vehicules', [
            'immatriculation' => 'RC-NONADMINOK-GN',
            'site_id' => $ownSite->id,
        ]);
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function test_edit_returns_200_for_authorized_user(): void
    {
        $vehicule = $this->makeVehicule($this->org);

        $this->actingAs($this->user)
            ->get(route('vehicules.edit', $vehicule))
            ->assertStatus(200);
    }

    public function test_edit_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $vehicule = $this->makeVehicule($otherOrg);

        $this->actingAs($this->user)
            ->get(route('vehicules.edit', $vehicule))
            ->assertStatus(403);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_modifies_vehicule_and_redirects(): void
    {
        $vehicule = $this->makeVehicule($this->org);
        $nouveauProprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->put(route('vehicules.update', $vehicule), [
                'nom_vehicule' => 'Camion modifie',
                'immatriculation' => $vehicule->immatriculation,
                'type_vehicule_id' => $vehicule->type_vehicule_id,
                'categorie' => 'externe',
                'proprietaire_id' => $nouveauProprietaire->id,
                'site_id' => $vehicule->site_id,
                'is_active' => true,
                'pris_en_charge_par_usine' => false,
                'commission_eligible' => true,
            ])
            ->assertRedirect(route('vehicules.edit', $vehicule));

        $this->assertDatabaseHas('vehicules', [
            'id' => $vehicule->id,
            'proprietaire_id' => $nouveauProprietaire->id,
            'categorie' => 'externe',
        ]);
    }

    public function test_update_fails_with_missing_required_fields(): void
    {
        $vehicule = $this->makeVehicule($this->org);

        $this->actingAs($this->user)
            ->put(route('vehicules.update', $vehicule), [])
            ->assertSessionHasErrors(['nom_vehicule', 'immatriculation', 'type_vehicule_id', 'categorie', 'site_id', 'pris_en_charge_par_usine', 'commission_eligible']);
    }

    public function test_update_modifie_pris_en_charge(): void
    {
        $vehicule = $this->makeVehicule($this->org);
        $vehicule->update(['pris_en_charge_par_usine' => false]);

        $this->actingAs($this->user)
            ->put(route('vehicules.update', $vehicule), [
                'nom_vehicule' => $vehicule->nom_vehicule,
                'immatriculation' => $vehicule->immatriculation,
                'type_vehicule_id' => $vehicule->type_vehicule_id,
                'categorie' => $vehicule->categorie,
                'proprietaire_id' => $vehicule->proprietaire_id,
                'site_id' => $vehicule->site_id,
                'pris_en_charge_par_usine' => true,
                'commission_eligible' => true,
            ])
            ->assertRedirect(route('vehicules.edit', $vehicule));

        $this->assertDatabaseHas('vehicules', [
            'id' => $vehicule->id,
            'pris_en_charge_par_usine' => true,
        ]);
    }

    public function test_update_modifie_commission_eligible_independamment(): void
    {
        // commission_eligible peut changer sans toucher pris_en_charge_par_usine,
        // et inversement — les deux colonnes ne sont jamais recalculées l'une
        // à partir de l'autre côté backend.
        $vehicule = $this->makeVehicule($this->org);
        $vehicule->update(['pris_en_charge_par_usine' => true, 'commission_eligible' => true]);

        $this->actingAs($this->user)
            ->put(route('vehicules.update', $vehicule), [
                'nom_vehicule' => $vehicule->nom_vehicule,
                'immatriculation' => $vehicule->immatriculation,
                'type_vehicule_id' => $vehicule->type_vehicule_id,
                'categorie' => $vehicule->categorie,
                'proprietaire_id' => $vehicule->proprietaire_id,
                'site_id' => $vehicule->site_id,
                'pris_en_charge_par_usine' => true,
                'commission_eligible' => false,
            ])
            ->assertRedirect(route('vehicules.edit', $vehicule));

        $this->assertDatabaseHas('vehicules', [
            'id' => $vehicule->id,
            'pris_en_charge_par_usine' => true,
            'commission_eligible' => false,
        ]);
    }

    public function test_update_fails_sans_pris_en_charge_par_usine(): void
    {
        $vehicule = $this->makeVehicule($this->org);

        $this->actingAs($this->user)
            ->put(route('vehicules.update', $vehicule), [
                'nom_vehicule' => $vehicule->nom_vehicule,
                'immatriculation' => $vehicule->immatriculation,
                'type_vehicule_id' => $vehicule->type_vehicule_id,
                'categorie' => $vehicule->categorie,
                'proprietaire_id' => $vehicule->proprietaire_id,
                'commission_eligible' => true,
                // pris_en_charge_par_usine absent
            ])
            ->assertSessionHasErrors('pris_en_charge_par_usine');
    }

    public function test_update_fails_sans_commission_eligible(): void
    {
        $vehicule = $this->makeVehicule($this->org);

        $this->actingAs($this->user)
            ->put(route('vehicules.update', $vehicule), [
                'nom_vehicule' => $vehicule->nom_vehicule,
                'immatriculation' => $vehicule->immatriculation,
                'type_vehicule_id' => $vehicule->type_vehicule_id,
                'categorie' => $vehicule->categorie,
                'proprietaire_id' => $vehicule->proprietaire_id,
                'pris_en_charge_par_usine' => true,
                // commission_eligible absent
            ])
            ->assertSessionHasErrors('commission_eligible');
    }

    public function test_update_autorise_meme_categorie_et_immatriculation(): void
    {
        $vehicule = $this->makeVehicule($this->org);

        $this->actingAs($this->user)
            ->put(route('vehicules.update', $vehicule), [
                'nom_vehicule' => $vehicule->nom_vehicule,
                'immatriculation' => $vehicule->immatriculation,
                'type_vehicule_id' => $vehicule->type_vehicule_id,
                'categorie' => $vehicule->categorie,
                'proprietaire_id' => $vehicule->proprietaire_id,
                'site_id' => $vehicule->site_id,
                'is_active' => true,
                'pris_en_charge_par_usine' => false,
                'commission_eligible' => true,
            ])
            ->assertRedirect(route('vehicules.edit', $vehicule));
    }

    public function test_update_fails_for_non_admin_choosing_other_site(): void
    {
        $ownSite = $this->user->sites()->first();
        $otherSite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Autre Site Update',
            'type' => 'depot',
        ]);
        $nonAdmin = $this->makeNonAdminUser($this->org, $ownSite);
        $vehicule = $this->makeVehicule($this->org);
        $vehicule->update(['site_id' => $ownSite->id]);

        $this->actingAs($nonAdmin)
            ->put(route('vehicules.update', $vehicule), [
                'nom_vehicule' => $vehicule->nom_vehicule,
                'immatriculation' => $vehicule->immatriculation,
                'type_vehicule_id' => $vehicule->type_vehicule_id,
                'categorie' => $vehicule->categorie,
                'proprietaire_id' => $vehicule->proprietaire_id,
                'site_id' => $otherSite->id,
                'pris_en_charge_par_usine' => false,
                'commission_eligible' => true,
            ])
            ->assertStatus(403);

        $this->assertDatabaseHas('vehicules', [
            'id' => $vehicule->id,
            'site_id' => $ownSite->id,
        ]);
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_deletes_vehicule_and_redirects(): void
    {
        $vehicule = $this->makeVehicule($this->org);

        $this->actingAs($this->user)
            ->delete(route('vehicules.destroy', $vehicule))
            ->assertRedirect(route('vehicules.index'));

        $this->assertSoftDeleted('vehicules', ['id' => $vehicule->id]);
    }

    public function test_destroy_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $vehicule = $this->makeVehicule($otherOrg);

        $this->actingAs($this->user)
            ->delete(route('vehicules.destroy', $vehicule))
            ->assertStatus(403);
    }

    // ── show ─────────────────────────────────────────────────────────────────

    public function test_show_retourne_200(): void
    {
        $vehicule = $this->makeVehicule($this->org);

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $vehicule))
            ->assertStatus(200);
    }

    public function test_show_expose_proprietaire_id_pour_vehicule_externe(): void
    {
        $vehicule = $this->makeVehicule($this->org);

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $vehicule))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Vehicules/Show')
                ->where('vehicule.proprietaire_id', $vehicule->proprietaire_id)
                ->where('vehicule.proprietaire_nom', fn ($val) => is_string($val) && strlen($val) > 0)
                ->where('vehicule.proprietaire_telephone', fn ($val) => $val === null || is_string($val))
            );
    }

    public function test_show_expose_proprietaire_id_null_quand_aucun_proprietaire(): void
    {
        // Un véhicule interne a désormais un propriétaire par défaut (Moussa
        // SIDIBE — cf. VehiculeController::defaultProprietaireInterneId()),
        // mais une fiche peut rester sans propriétaire (donnée historique
        // antérieure à cette règle, ou fiche Proprietaire par défaut absente
        // de l'organisation) : Show doit gérer ce cas sans planter.
        $site = $this->user->sites()->first();
        $typeVehicule = TypeVehicule::factory()->create(['organization_id' => $this->org->id]);

        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'type_vehicule_id' => $typeVehicule->id,
            'categorie' => 'interne',
            'site_id' => $site->id,
            'proprietaire_id' => null,
        ]);

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $vehicule))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Vehicules/Show')
                ->where('vehicule.proprietaire_id', null)
                ->where('vehicule.proprietaire_nom', null)
            );
    }

    public function test_store_creates_vehicule_interne_avec_proprietaire_par_defaut(): void
    {
        // Cf. defaultProprietaireInterneId() : la fiche Proprietaire "Moussa
        // SIDIBE" (téléphone +224622602693) est le propriétaire par défaut
        // des véhicules internes lorsque l'organisation en dispose.
        $defaut = Proprietaire::factory()->create([
            'organization_id' => $this->org->id,
            'nom' => 'SIDIBE',
            'prenom' => 'Moussa',
            'telephone' => '+224622602693',
        ]);
        $site = $this->user->sites()->first();

        $response = $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Tricycle Défaut',
                'immatriculation' => 'TC-DEFAUT-GN',
                'type_vehicule_id' => $this->typeId(),
                'categorie' => 'interne',
                'site_id' => $site->id,
                'capacite_packs' => 50,
                'is_active' => true,
                'pris_en_charge_par_usine' => false,
                'commission_eligible' => true,
            ]);

        $vehicule = Vehicule::query()
            ->where('organization_id', $this->org->id)
            ->where('immatriculation', 'TC-DEFAUT-GN')
            ->firstOrFail();

        $response->assertRedirect(route('vehicules.show', $vehicule));

        $this->assertDatabaseHas('vehicules', [
            'organization_id' => $this->org->id,
            'immatriculation' => 'TC-DEFAUT-GN',
            'categorie' => 'interne',
            'proprietaire_id' => $defaut->id,
        ]);
    }

    public function test_store_creates_vehicule_interne_avec_proprietaire_explicite(): void
    {
        // Un véhicule interne reste modifiable : un propriétaire explicite
        // (différent du défaut) prime sur la valeur par défaut.
        Proprietaire::factory()->create([
            'organization_id' => $this->org->id,
            'telephone' => '+224622602693',
        ]);
        $autre = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $site = $this->user->sites()->first();

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Tricycle Explicite',
                'immatriculation' => 'TC-EXPLICITE-GN',
                'type_vehicule_id' => $this->typeId(),
                'categorie' => 'interne',
                'proprietaire_id' => $autre->id,
                'site_id' => $site->id,
                'capacite_packs' => 50,
                'is_active' => true,
                'pris_en_charge_par_usine' => false,
                'commission_eligible' => true,
            ]);

        $this->assertDatabaseHas('vehicules', [
            'organization_id' => $this->org->id,
            'immatriculation' => 'TC-EXPLICITE-GN',
            'categorie' => 'interne',
            'proprietaire_id' => $autre->id,
        ]);
    }

    public function test_show_expose_livreur_nom_sans_doublon_de_designation(): void
    {
        // nom_complet est désormais toujours renseigné à la création d'un
        // livreur (repli sur "Chauffeur-1 {véhicule}" si aucun nom saisi —
        // cf. Livreur::designationParDefaut()) : le libellé "equipe_membres"
        // ne doit pas re-préfixer un ordinal par-dessus, sous peine de
        // doublon ("Chauffeur-1 — Chauffeur-1 ADAMA").
        $vehicule = $this->makeVehicule($this->org);
        $vehicule->update(['nom_vehicule' => 'ADAMA']);

        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'proprietaire_id' => $vehicule->proprietaire_id,
            'is_active' => true,
            'commission_unitaire_par_pack' => 100,
        ]);

        $livreur = Livreur::create([
            'organization_id' => $this->org->id,
            'nom_complet' => 'Chauffeur-1 ADAMA',
            'telephone' => '+224623000001',
            'is_active' => true,
        ]);

        EquipeLivreur::create([
            'equipe_id' => $equipe->id,
            'livreur_id' => $livreur->id,
            'role' => 'chauffeur',
            'montant_par_pack' => 0,
            'taux_commission' => 0,
            'ordre' => 0,
        ]);

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $vehicule))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Vehicules/Show')
                ->where('vehicule.equipe_membres.0.livreur_nom', 'Chauffeur-1 ADAMA')
            );
    }

    public function test_show_retourne_403_pour_autre_organisation(): void
    {
        $otherOrg = Organization::factory()->create();
        $vehicule = $this->makeVehicule($otherOrg);

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $vehicule))
            ->assertStatus(403);
    }

    // ── unicité immatriculation ───────────────────────────────────────────────

    public function test_store_fails_si_immatriculation_deja_utilisee(): void
    {
        Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'immatriculation' => 'RC-100-GN',
        ]);

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion Doublon',
                'immatriculation' => 'RC-100-GN',
                'type_vehicule_id' => $this->typeId(),
                'categorie' => 'interne',
            ])
            ->assertSessionHasErrors('immatriculation');
    }

    public function test_update_fails_si_immatriculation_utilisee_par_autre_vehicule(): void
    {
        $vehicule = $this->makeVehicule($this->org);

        Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'immatriculation' => 'RC-999-GN',
        ]);

        $this->actingAs($this->user)
            ->put(route('vehicules.update', $vehicule), [
                'nom_vehicule' => $vehicule->nom_vehicule,
                'immatriculation' => 'RC-999-GN',
                'type_vehicule_id' => $vehicule->type_vehicule_id,
                'categorie' => $vehicule->categorie,
                'proprietaire_id' => $vehicule->proprietaire_id,
            ])
            ->assertSessionHasErrors('immatriculation');
    }
}
