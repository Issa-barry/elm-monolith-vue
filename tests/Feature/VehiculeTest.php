<?php

namespace Tests\Feature;

use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Personne;
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

    /**
     * Crée le propriétaire interne par défaut de $this->org et le rattache via
     * Organization::proprietaire_interne_id (cf. InstallationService::install() en
     * conditions réelles) — seule façon dont Proprietaire::interneParDefautId() peut
     * désormais le retrouver, plus aucune magie sur le numéro de téléphone.
     */
    private function defaultInterneProprietaire(array $attributes = []): Proprietaire
    {
        $proprietaire = Proprietaire::factory()->create([
            'organization_id' => $this->org->id,
            ...$attributes,
        ]);
        $this->org->forceFill(['proprietaire_interne_id' => $proprietaire->id])->save();

        return $proprietaire;
    }

    private function makeVehicule(Organization $org, ?bool $livraisonVente = null, ?bool $livraisonLogistique = null): Vehicule
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
            'site_id' => $site->id,
            ...($livraisonVente !== null ? ['livraison_vente' => $livraisonVente] : []),
            ...($livraisonLogistique !== null ? ['livraison_logistique' => $livraisonLogistique] : []),
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

    public function test_index_expose_la_capacite_packs_du_type(): void
    {
        // Capacité gérée exclusivement par le type (décision produit du 16/08/2026, cf.
        // VehiculeCapaciteService) — jamais propre au véhicule, quel que soit son origine
        // (formulaire manuel ou import flotte, cf. ImportFlotteExecutor).
        $type = TypeVehicule::factory()->create(['organization_id' => $this->org->id, 'capacite_defaut' => 270]);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'type_vehicule_id' => $type->id,
            'proprietaire_id' => $proprietaire->id,
        ]);

        $this->actingAs($this->user)
            ->get(route('vehicules.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('vehicules.0.capacite_packs', 270)
            );
    }

    public function test_index_expose_la_capacite_bouteilles_du_type(): void
    {
        $type = TypeVehicule::factory()->create([
            'organization_id' => $this->org->id,
            'capacite_defaut_bouteilles' => 40,
        ]);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'type_vehicule_id' => $type->id,
            'proprietaire_id' => $proprietaire->id,
        ]);

        $this->actingAs($this->user)
            ->get(route('vehicules.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('vehicules.0.capacite_bouteilles', 40)
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
                'proprietaire_id' => $proprietaire->id,
                'categorie' => 'partenaire',
                'site_id' => $site->id,
                'is_active' => true,
                'livraison_vente' => true,
                'livraison_logistique' => false,
            ]);

        $vehicule = Vehicule::query()
            ->where('organization_id', $this->org->id)
            ->where('immatriculation', 'RC-001-GN')
            ->firstOrFail();

        $response->assertRedirect(route('vehicules.show', $vehicule));

        $this->assertDatabaseHas('vehicules', [
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'livraison_vente' => true,
            'livraison_logistique' => false,
        ]);
    }

    /**
     * capacite_packs/capacite_bouteilles ne sont plus des champs du formulaire véhicule
     * (décision produit du 16/08/2026) — un envoi malgré tout (ancien client, requête forgée)
     * n'a aucun effet : ni dans validationRules(), ces clés sont silencieusement ignorées par
     * Request::validate(), la capacité affichée reste celle du type.
     */
    public function test_store_ignore_capacite_packs_et_bouteilles_envoyes_dans_le_payload(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $site = $this->user->sites()->first();

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Minibus 01',
                'immatriculation' => 'MB-001-GN',
                'type_vehicule_id' => $this->typeId(),
                'proprietaire_id' => $proprietaire->id,
                'categorie' => 'partenaire',
                'site_id' => $site->id,
                'capacite_packs' => 200,
                'capacite_bouteilles' => 60,
                'is_active' => true,
                'livraison_vente' => true,
                'livraison_logistique' => false,
            ]);

        $this->assertDatabaseHas('vehicules', [
            'organization_id' => $this->org->id,
            'immatriculation' => 'MB-001-GN',
            'capacite_packs' => null,
            'capacite_bouteilles' => null,
        ]);
    }

    public function test_store_creates_vehicule_logistique_seul(): void
    {
        $this->defaultInterneProprietaire();
        $site = $this->user->sites()->first();

        $response = $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Tricycle 01',
                'immatriculation' => 'TC-TEST-GN',
                'type_vehicule_id' => $this->typeId(),
                'categorie' => 'interne',
                'site_id' => $site->id,
                'is_active' => true,
                'livraison_vente' => false,
                'livraison_logistique' => true,
            ]);

        $vehicule = Vehicule::query()
            ->where('organization_id', $this->org->id)
            ->where('immatriculation', 'TC-TEST-GN')
            ->firstOrFail();

        $response->assertRedirect(route('vehicules.show', $vehicule));

        $this->assertDatabaseHas('vehicules', [
            'organization_id' => $this->org->id,
            'livraison_vente' => false,
            'livraison_logistique' => true,
            'site_id' => $site->id,
        ]);
    }

    public function test_store_accepte_usage_mixte(): void
    {
        $this->defaultInterneProprietaire();
        $site = $this->user->sites()->first();

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion Mixte',
                'immatriculation' => 'MX-001-GN',
                'type_vehicule_id' => $this->typeId(),
                'categorie' => 'interne',
                'site_id' => $site->id,
                'livraison_vente' => true,
                'livraison_logistique' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('vehicules', [
            'immatriculation' => 'MX-001-GN',
            'livraison_vente' => true,
            'livraison_logistique' => true,
        ]);
    }

    public function test_store_fails_sans_aucun_usage(): void
    {
        $site = $this->user->sites()->first();

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion Sans Usage',
                'immatriculation' => 'NU-001-GN',
                'type_vehicule_id' => $this->typeId(),
                'categorie' => 'interne',
                'site_id' => $site->id,
                'livraison_vente' => false,
                'livraison_logistique' => false,
            ])
            ->assertSessionHasErrors('livraison_vente');

        $this->assertDatabaseMissing('vehicules', ['immatriculation' => 'NU-001-GN']);
    }

    public function test_store_fails_sans_site_id(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion Sans Site',
                'immatriculation' => 'RC-NOSITE-GN',
                'type_vehicule_id' => $this->typeId(),
                'proprietaire_id' => $proprietaire->id,
                'categorie' => 'partenaire',
                'livraison_vente' => true,
                'livraison_logistique' => false,
            ])
            ->assertSessionHasErrors('site_id');
    }

    public function test_store_fails_avec_site_autre_org(): void
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
                'livraison_vente' => true,
                'livraison_logistique' => false,
            ])
            ->assertSessionHasErrors('site_id');
    }

    public function test_store_fails_with_empty_data(): void
    {
        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [])
            ->assertSessionHasErrors(['nom_vehicule', 'immatriculation', 'type_vehicule_id', 'site_id', 'categorie', 'livraison_vente', 'livraison_logistique']);
    }

    public function test_store_fails_with_invalid_type_vehicule(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion Type Invalide',
                'immatriculation' => 'RC-002-GN',
                'type_vehicule_id' => 'invalid-ulid-that-does-not-exist',
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
                'proprietaire_id' => $proprietaire->id,
            ])
            ->assertSessionHasErrors('proprietaire_id');
    }

    public function test_store_conserve_site_id(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $site = $this->user->sites()->first();

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion Externe',
                'immatriculation' => 'RC-EXT-GN',
                'type_vehicule_id' => $this->typeId(),
                'proprietaire_id' => $proprietaire->id,
                'categorie' => 'partenaire',
                'site_id' => $site->id,
                'livraison_vente' => true,
                'livraison_logistique' => false,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('vehicules', [
            'immatriculation' => 'RC-EXT-GN',
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
                'proprietaire_id' => $proprietaire->id,
                'categorie' => 'partenaire',
                'site_id' => $otherSite->id,
                'livraison_vente' => true,
                'livraison_logistique' => false,
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
                'proprietaire_id' => $proprietaire->id,
                'categorie' => 'partenaire',
                'site_id' => $ownSite->id,
                'livraison_vente' => true,
                'livraison_logistique' => false,
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
                'proprietaire_id' => $nouveauProprietaire->id,
                'categorie' => 'partenaire',
                'site_id' => $vehicule->site_id,
                'is_active' => true,
                'livraison_vente' => true,
                'livraison_logistique' => false,
            ])
            ->assertRedirect(route('vehicules.edit', $vehicule));

        $this->assertDatabaseHas('vehicules', [
            'id' => $vehicule->id,
            'proprietaire_id' => $nouveauProprietaire->id,
        ]);
    }

    public function test_update_fails_with_missing_required_fields(): void
    {
        $vehicule = $this->makeVehicule($this->org);

        $this->actingAs($this->user)
            ->put(route('vehicules.update', $vehicule), [])
            ->assertSessionHasErrors(['nom_vehicule', 'immatriculation', 'type_vehicule_id', 'site_id', 'categorie', 'livraison_vente', 'livraison_logistique']);
    }

    public function test_update_fails_sans_aucun_usage(): void
    {
        $vehicule = $this->makeVehicule($this->org);

        $this->actingAs($this->user)
            ->put(route('vehicules.update', $vehicule), [
                'nom_vehicule' => $vehicule->nom_vehicule,
                'immatriculation' => $vehicule->immatriculation,
                'type_vehicule_id' => $vehicule->type_vehicule_id,
                'proprietaire_id' => $vehicule->proprietaire_id,
                'categorie' => 'partenaire',
                'site_id' => $vehicule->site_id,
                'livraison_vente' => false,
                'livraison_logistique' => false,
            ])
            ->assertSessionHasErrors('livraison_vente');
    }

    public function test_update_peut_activer_les_deux_usages(): void
    {
        $vehicule = $this->makeVehicule($this->org);
        $vehicule->update(['livraison_vente' => true, 'livraison_logistique' => false]);

        $this->actingAs($this->user)
            ->put(route('vehicules.update', $vehicule), [
                'nom_vehicule' => $vehicule->nom_vehicule,
                'immatriculation' => $vehicule->immatriculation,
                'type_vehicule_id' => $vehicule->type_vehicule_id,
                'proprietaire_id' => $vehicule->proprietaire_id,
                'categorie' => 'partenaire',
                'site_id' => $vehicule->site_id,
                'livraison_vente' => true,
                'livraison_logistique' => true,
            ])
            ->assertRedirect(route('vehicules.edit', $vehicule));

        $this->assertDatabaseHas('vehicules', [
            'id' => $vehicule->id,
            'livraison_vente' => true,
            'livraison_logistique' => true,
        ]);
    }

    public function test_update_autorise_meme_immatriculation(): void
    {
        $vehicule = $this->makeVehicule($this->org);

        $this->actingAs($this->user)
            ->put(route('vehicules.update', $vehicule), [
                'nom_vehicule' => $vehicule->nom_vehicule,
                'immatriculation' => $vehicule->immatriculation,
                'type_vehicule_id' => $vehicule->type_vehicule_id,
                'proprietaire_id' => $vehicule->proprietaire_id,
                'categorie' => 'partenaire',
                'site_id' => $vehicule->site_id,
                'is_active' => true,
                'livraison_vente' => true,
                'livraison_logistique' => false,
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
                'proprietaire_id' => $vehicule->proprietaire_id,
                'categorie' => 'partenaire',
                'site_id' => $otherSite->id,
                'livraison_vente' => true,
                'livraison_logistique' => false,
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

    public function test_show_expose_proprietaire_id_pour_vehicule_avec_proprietaire(): void
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
        // Une fiche peut rester sans propriétaire (donnée historique antérieure à la règle
        // du propriétaire par défaut, ou fiche Proprietaire par défaut absente de
        // l'organisation) : Show doit gérer ce cas sans planter.
        $site = $this->user->sites()->first();
        $typeVehicule = TypeVehicule::factory()->create(['organization_id' => $this->org->id]);

        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'type_vehicule_id' => $typeVehicule->id,
            'site_id' => $site->id,
            'proprietaire_id' => null,
            'categorie' => 'interne',
        ]);

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $vehicule))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Vehicules/Show')
                ->where('vehicule.proprietaire_id', null)
                ->where('vehicule.proprietaire_nom', null)
            );
    }

    public function test_store_creates_vehicule_avec_proprietaire_par_defaut(): void
    {
        // Cf. Proprietaire::interneParDefautId() : le propriétaire interne configuré sur
        // l'organisation est assigné par défaut à tout véhicule créé sans proprietaire_id
        // explicite.
        $defaut = $this->defaultInterneProprietaire(['nom' => 'SIDIBE', 'prenom' => 'Moussa']);
        $site = $this->user->sites()->first();

        $response = $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Tricycle Défaut',
                'immatriculation' => 'TC-DEFAUT-GN',
                'type_vehicule_id' => $this->typeId(),
                'categorie' => 'interne',
                'site_id' => $site->id,
                'is_active' => true,
                'livraison_vente' => false,
                'livraison_logistique' => true,
            ]);

        $vehicule = Vehicule::query()
            ->where('organization_id', $this->org->id)
            ->where('immatriculation', 'TC-DEFAUT-GN')
            ->firstOrFail();

        $response->assertRedirect(route('vehicules.show', $vehicule));

        $this->assertDatabaseHas('vehicules', [
            'organization_id' => $this->org->id,
            'immatriculation' => 'TC-DEFAUT-GN',
            'proprietaire_id' => $defaut->id,
        ]);
    }

    public function test_store_creates_vehicule_avec_proprietaire_explicite(): void
    {
        // Un propriétaire explicite (différent du défaut) prime toujours sur la valeur
        // par défaut, quel que soit l'usage du véhicule.
        $this->defaultInterneProprietaire();
        $autre = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $site = $this->user->sites()->first();

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Tricycle Explicite',
                'immatriculation' => 'TC-EXPLICITE-GN',
                'type_vehicule_id' => $this->typeId(),
                'proprietaire_id' => $autre->id,
                'categorie' => 'partenaire',
                'site_id' => $site->id,
                'is_active' => true,
                'livraison_vente' => false,
                'livraison_logistique' => true,
            ]);

        $this->assertDatabaseHas('vehicules', [
            'organization_id' => $this->org->id,
            'immatriculation' => 'TC-EXPLICITE-GN',
            'proprietaire_id' => $autre->id,
        ]);
    }

    // ── catégorie (propriété INTERNE/PARTENAIRE) — obligatoire et cohérente avec le ────────
    // propriétaire, cf. CategorieVehicule::coherentAvecProprietaireTiers() ─────────────────

    public function test_store_fails_sans_categorie(): void
    {
        $site = $this->user->sites()->first();

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion Sans Categorie',
                'immatriculation' => 'CAT-001-GN',
                'type_vehicule_id' => $this->typeId(),
                'site_id' => $site->id,
                'livraison_vente' => true,
                'livraison_logistique' => false,
            ])
            ->assertSessionHasErrors('categorie');

        $this->assertDatabaseMissing('vehicules', ['immatriculation' => 'CAT-001-GN']);
    }

    public function test_store_fails_avec_categorie_invalide(): void
    {
        $site = $this->user->sites()->first();

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion Categorie Invalide',
                'immatriculation' => 'CAT-002-GN',
                'type_vehicule_id' => $this->typeId(),
                'categorie' => 'externe',
                'site_id' => $site->id,
                'livraison_vente' => true,
                'livraison_logistique' => false,
            ])
            ->assertSessionHasErrors('categorie');
    }

    public function test_store_fails_partenaire_sans_proprietaire_tiers(): void
    {
        // Categorie PARTENAIRE sans proprietaire_id du tout : le contrôleur assignerait
        // silencieusement le propriétaire interne par défaut si la cohérence n'était pas
        // vérifiée après coup — cf. VehiculeController::ensureCategorieCoherente().
        $site = $this->user->sites()->first();

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion Partenaire Sans Proprio',
                'immatriculation' => 'CAT-003-GN',
                'type_vehicule_id' => $this->typeId(),
                'categorie' => 'partenaire',
                'site_id' => $site->id,
                'livraison_vente' => true,
                'livraison_logistique' => false,
            ])
            ->assertSessionHasErrors('categorie');

        $this->assertDatabaseMissing('vehicules', ['immatriculation' => 'CAT-003-GN']);
    }

    public function test_store_fails_partenaire_avec_proprietaire_interne_par_defaut(): void
    {
        $defaut = $this->defaultInterneProprietaire();
        $site = $this->user->sites()->first();

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion Partenaire Proprio Interne',
                'immatriculation' => 'CAT-004-GN',
                'type_vehicule_id' => $this->typeId(),
                'proprietaire_id' => $defaut->id,
                'categorie' => 'partenaire',
                'site_id' => $site->id,
                'livraison_vente' => true,
                'livraison_logistique' => false,
            ])
            ->assertSessionHasErrors('categorie');

        $this->assertDatabaseMissing('vehicules', ['immatriculation' => 'CAT-004-GN']);
    }

    public function test_store_fails_interne_avec_proprietaire_tiers(): void
    {
        $tiers = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $site = $this->user->sites()->first();

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion Interne Proprio Tiers',
                'immatriculation' => 'CAT-005-GN',
                'type_vehicule_id' => $this->typeId(),
                'proprietaire_id' => $tiers->id,
                'categorie' => 'interne',
                'site_id' => $site->id,
                'livraison_vente' => true,
                'livraison_logistique' => false,
            ])
            ->assertSessionHasErrors('categorie');

        $this->assertDatabaseMissing('vehicules', ['immatriculation' => 'CAT-005-GN']);
    }

    public function test_store_succeeds_interne_sans_proprietaire(): void
    {
        // Un propriétaire interne configuré sur l'organisation permet de créer un véhicule
        // "interne" sans choisir explicitement de propriétaire — il y retombe automatiquement.
        $defaut = $this->defaultInterneProprietaire();
        $site = $this->user->sites()->first();

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion Interne Ok',
                'immatriculation' => 'CAT-006-GN',
                'type_vehicule_id' => $this->typeId(),
                'categorie' => 'interne',
                'site_id' => $site->id,
                'livraison_vente' => true,
                'livraison_logistique' => false,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('vehicules', [
            'immatriculation' => 'CAT-006-GN',
            'categorie' => 'interne',
            'proprietaire_id' => $defaut->id,
        ]);
    }

    public function test_store_fails_interne_sans_proprietaire_interne_configure(): void
    {
        // Aucun propriétaire interne configuré sur l'organisation (cf. defaultInterneProprietaire()
        // jamais appelé ici) : un véhicule "interne" sans propriétaire explicite ne doit jamais se
        // retrouver silencieusement avec proprietaire_id = null (véhicule sans propriétaire
        // économique, invisible des commissions) — erreur métier claire à la place.
        $site = $this->user->sites()->first();

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion Interne Sans Defaut',
                'immatriculation' => 'CAT-008-GN',
                'type_vehicule_id' => $this->typeId(),
                'categorie' => 'interne',
                'site_id' => $site->id,
                'livraison_vente' => true,
                'livraison_logistique' => false,
            ])
            ->assertSessionHasErrors('categorie');

        $this->assertDatabaseMissing('vehicules', ['immatriculation' => 'CAT-008-GN']);
    }

    public function test_store_succeeds_partenaire_avec_proprietaire_tiers(): void
    {
        $tiers = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $site = $this->user->sites()->first();

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion Partenaire Ok',
                'immatriculation' => 'CAT-007-GN',
                'type_vehicule_id' => $this->typeId(),
                'proprietaire_id' => $tiers->id,
                'categorie' => 'partenaire',
                'site_id' => $site->id,
                'livraison_vente' => true,
                'livraison_logistique' => false,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('vehicules', [
            'immatriculation' => 'CAT-007-GN',
            'proprietaire_id' => $tiers->id,
            'categorie' => 'partenaire',
        ]);
    }

    public function test_update_fails_quand_categorie_devient_incoherente_avec_le_proprietaire(): void
    {
        $tiers = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = $this->makeVehicule($this->org);
        $vehicule->update(['categorie' => 'partenaire', 'proprietaire_id' => $tiers->id]);

        $this->actingAs($this->user)
            ->put(route('vehicules.update', $vehicule), [
                'nom_vehicule' => $vehicule->nom_vehicule,
                'immatriculation' => $vehicule->immatriculation,
                'type_vehicule_id' => $vehicule->type_vehicule_id,
                'proprietaire_id' => $tiers->id,
                // Bascule vers INTERNE sans changer le propriétaire tiers : incohérent.
                'categorie' => 'interne',
                'site_id' => $vehicule->site_id,
                'livraison_vente' => true,
                'livraison_logistique' => false,
            ])
            ->assertSessionHasErrors('categorie');

        $this->assertDatabaseHas('vehicules', [
            'id' => $vehicule->id,
            'categorie' => 'partenaire',
        ]);
    }

    public function test_show_expose_categorie(): void
    {
        $vehicule = $this->makeVehicule($this->org);
        $vehicule->update(['categorie' => 'partenaire']);

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $vehicule))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Vehicules/Show')
                ->where('vehicule.categorie', 'partenaire')
                ->where('vehicule.categorie_label', 'Partenaire')
            );
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

        $livreur = Livreur::factory()->create([
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

    // ── proprietairesOptions() — régression Sentry (Unknown column 'nom' in order clause) ──
    // après la refonte Personne : nom/prenom/telephone ne sont plus des colonnes physiques
    // de `proprietaires`, `livreurs`, `employes` ni `users` (portées par `personnes`) — un
    // ->orderBy('nom') direct sur ces tables lève SQLSTATE[42S22]. VehiculeController::show()
    // charge proprietairesOptions() (liste "proprietaires" exposée pour le sélecteur de
    // réassignation), qui exécute réellement cette requête.

    public function test_show_expose_le_proprietaire_dans_la_liste_apres_creation_explicite_dune_personne(): void
    {
        // Reproduit le parcours métier réel : Personne créée d'abord, Proprietaire rattaché
        // ensuite via personne_id — pas le raccourci de compatibilité de ProprietaireFactory.
        $personne = Personne::factory()->create([
            'organization_id' => $this->org->id,
            'nom' => 'DIALLO',
            'prenom' => 'Mariama',
        ]);
        $proprietaire = Proprietaire::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'is_active' => true,
        ]);
        $vehicule = $this->makeVehicule($this->org);
        $vehicule->update(['proprietaire_id' => $proprietaire->id]);

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $vehicule))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Vehicules/Show')
                ->where('proprietaires', fn ($list) => collect($list)->contains(
                    fn ($p) => $p['value'] === $proprietaire->id && $p['label'] === 'Mariama DIALLO'
                ))
            );
    }

    public function test_show_trie_la_liste_des_proprietaires_par_ordre_alphabetique(): void
    {
        // Ordre de création volontairement inverse de l'ordre alphabétique attendu : si une
        // régression réintroduit ->orderBy('nom') sur la requête (colonne inexistante sur
        // `proprietaires`), la requête lève une QueryException et la réponse ne serait plus
        // 200 — ce test exécute la vraie requête SQL, pas seulement l'accesseur nom/prenom.
        $vehicule = $this->makeVehicule($this->org);

        Proprietaire::factory()->create(['organization_id' => $this->org->id, 'nom' => 'Zoumanigui', 'prenom' => 'Sekou']);
        Proprietaire::factory()->create(['organization_id' => $this->org->id, 'nom' => 'Bah', 'prenom' => 'Aminata']);
        Proprietaire::factory()->create(['organization_id' => $this->org->id, 'nom' => 'Kone', 'prenom' => 'Ibrahim']);

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $vehicule))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Vehicules/Show')
                ->where('proprietaires', function ($list) {
                    $labels = collect($list)->pluck('label')->values()->all();
                    $posBah = array_search('Aminata Bah', $labels, true);
                    $posKone = array_search('Ibrahim Kone', $labels, true);
                    $posZoumanigui = array_search('Sekou Zoumanigui', $labels, true);

                    return $posBah !== false && $posKone !== false && $posZoumanigui !== false
                        && $posBah < $posKone && $posKone < $posZoumanigui;
                })
            );
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
            ])
            ->assertSessionHasErrors('immatriculation');
    }

    /** Même plaque, écrite différemment (espaces/points/casse) : doit rester un doublon détecté. */
    public function test_store_fails_si_immatriculation_correspond_apres_normalisation(): void
    {
        Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'immatriculation' => 'BK-4627-02',
        ]);

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), [
                'nom_vehicule' => 'Camion Doublon',
                'immatriculation' => 'bk 4627.02',
                'type_vehicule_id' => $this->typeId(),
            ])
            ->assertSessionHasErrors('immatriculation');

        $this->assertSame(1, Vehicule::where('organization_id', $this->org->id)->count());
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
                'proprietaire_id' => $vehicule->proprietaire_id,
            ])
            ->assertSessionHasErrors('immatriculation');
    }

    // ── usage vente/logistique — règle métier centralisée (Vehicule::usage_label / ────
    // aAuMoinsUnUsage()) et exclusion des sélecteurs opérationnels (scopes) ───────────

    public function test_usage_label_reflects_livraison_flags(): void
    {
        $this->assertSame('Vente', $this->makeVehicule($this->org, true, false)->usage_label);
        $this->assertSame('Logistique', $this->makeVehicule($this->org, false, true)->usage_label);
        $this->assertSame('Vente + Logistique', $this->makeVehicule($this->org, true, true)->usage_label);
        $this->assertSame('Usage non défini', $this->makeVehicule($this->org, false, false)->usage_label);
    }

    public function test_a_au_moins_un_usage_helper(): void
    {
        $this->assertTrue($this->makeVehicule($this->org, true, false)->aAuMoinsUnUsage());
        $this->assertTrue($this->makeVehicule($this->org, false, true)->aAuMoinsUnUsage());
        $this->assertFalse($this->makeVehicule($this->org, false, false)->aAuMoinsUnUsage());
    }

    /**
     * Les scopes ci-dessous sont le mécanisme réel utilisé par tous les sélecteurs
     * opérationnels (CommandeVenteController::vehiculesActifs(), PdvController,
     * TransfertLogistiqueController, RessourcesController) — les tester directement
     * couvre donc l'exclusion effective d'un véhicule sans usage (ou avec un usage
     * différent de celui demandé) de ces sélecteurs.
     */
    public function test_scope_livraison_vente_exclut_vehicule_sans_aucun_usage(): void
    {
        $sansUsage = $this->makeVehicule($this->org, false, false);

        $this->assertFalse(Vehicule::livraisonVente()->whereKey($sansUsage->id)->exists());
    }

    public function test_scope_livraison_logistique_exclut_vehicule_sans_aucun_usage(): void
    {
        $sansUsage = $this->makeVehicule($this->org, false, false);

        $this->assertFalse(Vehicule::livraisonLogistique()->whereKey($sansUsage->id)->exists());
    }

    public function test_scope_livraison_logistique_exclut_vehicule_vente_uniquement(): void
    {
        $venteUniquement = $this->makeVehicule($this->org, true, false);

        $this->assertFalse(Vehicule::livraisonLogistique()->whereKey($venteUniquement->id)->exists());
    }

    public function test_scope_livraison_vente_exclut_vehicule_logistique_uniquement(): void
    {
        $logistiqueUniquement = $this->makeVehicule($this->org, false, true);

        $this->assertFalse(Vehicule::livraisonVente()->whereKey($logistiqueUniquement->id)->exists());
    }

    public function test_scope_livraison_vente_et_logistique_incluent_vehicule_avec_les_deux_usages(): void
    {
        $vehicule = $this->makeVehicule($this->org, true, true);

        $this->assertTrue(Vehicule::livraisonVente()->whereKey($vehicule->id)->exists());
        $this->assertTrue(Vehicule::livraisonLogistique()->whereKey($vehicule->id)->exists());
    }
}
