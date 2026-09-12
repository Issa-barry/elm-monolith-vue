<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Parrain;
use App\Models\Personne;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Parrainage véhicule — phase 1 (cf. docs/parrainage-vehicule.md) : pas de commission, pas
 * d'historique. Couvre le point métier central de l'audit : la recherche par téléphone avant
 * création évite tout doublon de Personne, et une même Personne peut parrainer plusieurs
 * véhicules sans dupliquer ni son identité ni son rôle Parrain.
 */
class ParrainVehiculeTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['vehicules.read', 'vehicules.create', 'vehicules.update']);
    }

    // ── rechercherTelephone ──────────────────────────────────────────────────

    public function test_rechercher_telephone_trouve_une_personne_existante(): void
    {
        $personne = Personne::factory()->create([
            'organization_id' => $this->org->id,
            'nom_complet' => 'Mamadou Diallo',
            'telephone' => '+224622000001',
        ]);
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id]);

        $response = $this->actingAs($this->user)
            ->getJson(route('vehicules.parrain.rechercher', $vehicule).'?telephone=622000001&code_pays=GN')
            ->assertStatus(200);

        $response->assertJson([
            'found' => true,
            'personne' => ['id' => $personne->id, 'nom_complet' => 'Mamadou Diallo'],
        ]);
    }

    public function test_rechercher_telephone_ne_trouve_rien_si_absent(): void
    {
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->getJson(route('vehicules.parrain.rechercher', $vehicule).'?telephone=699999999&code_pays=GN')
            ->assertStatus(200)
            ->assertJson(['found' => false, 'personne' => null]);
    }

    public function test_rechercher_telephone_ignore_une_personne_dune_autre_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        Personne::factory()->create([
            'organization_id' => $autreOrg->id,
            'telephone' => '+224622000001',
        ]);
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->getJson(route('vehicules.parrain.rechercher', $vehicule).'?telephone=622000001&code_pays=GN')
            ->assertStatus(200)
            ->assertJson(['found' => false]);
    }

    public function test_rechercher_telephone_refuse_sans_permission(): void
    {
        $user = $this->makeAdminUser();
        $vehicule = Vehicule::factory()->create(['organization_id' => $user->organization_id]);

        $this->actingAs($user)
            ->getJson(route('vehicules.parrain.rechercher', $vehicule).'?telephone=622000001&code_pays=GN')
            ->assertStatus(403);
    }

    // ── store (association) ──────────────────────────────────────────────────

    public function test_store_reutilise_la_personne_trouvee_sans_la_dupliquer(): void
    {
        $personne = Personne::factory()->create([
            'organization_id' => $this->org->id,
            'telephone' => '+224622000001',
        ]);
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id]);
        $totalPersonnesAvant = Personne::where('organization_id', $this->org->id)->count();

        $this->actingAs($this->user)
            ->post(route('vehicules.parrain.store', $vehicule), ['personne_id' => $personne->id])
            ->assertRedirect(route('vehicules.show', $vehicule));

        $this->assertSame($totalPersonnesAvant, Personne::where('organization_id', $this->org->id)->count());
        $vehicule->refresh();
        $this->assertNotNull($vehicule->parrain_id);
        $this->assertSame($personne->id, $vehicule->parrain->personne_id);
    }

    public function test_store_cree_une_nouvelle_personne_si_aucune_ne_correspond(): void
    {
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->post(route('vehicules.parrain.store', $vehicule), [
                'nom_complet' => 'Mamadou Diallo',
                'telephone' => '622000001',
                'code_pays' => 'GN',
                'ville' => 'Conakry',
                'adresse' => 'Matoto',
            ])
            ->assertRedirect(route('vehicules.show', $vehicule));

        $this->assertDatabaseHas('personnes', [
            'organization_id' => $this->org->id,
            'nom_complet' => 'Mamadou Diallo',
            'telephone_normalise' => '224622000001',
        ]);
        $vehicule->refresh();
        $this->assertNotNull($vehicule->parrain_id);
        $this->assertSame('Mamadou Diallo', $vehicule->parrain->nom_complet);
    }

    public function test_store_ne_cree_pas_de_doublon_si_le_telephone_existe_deja(): void
    {
        $personne = Personne::factory()->create([
            'organization_id' => $this->org->id,
            'nom_complet' => 'Mamadou Diallo',
            'telephone' => '+224622000001',
        ]);
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id]);

        // Même téléphone, format local différent — doit résoudre vers la Personne existante,
        // jamais en créer une deuxième (cf. Personne::resoudreOuCreer()).
        $this->actingAs($this->user)
            ->post(route('vehicules.parrain.store', $vehicule), [
                'nom_complet' => 'Autre Nom Saisi',
                'telephone' => '622000001',
                'code_pays' => 'GN',
            ])
            ->assertRedirect(route('vehicules.show', $vehicule));

        $this->assertSame(1, Personne::where('organization_id', $this->org->id)->where('telephone_normalise', '224622000001')->count());
        $vehicule->refresh();
        $this->assertSame($personne->id, $vehicule->parrain->personne_id);
    }

    public function test_une_meme_personne_peut_parrainer_plusieurs_vehicules_sans_doublon_de_role(): void
    {
        $personne = Personne::factory()->create([
            'organization_id' => $this->org->id,
            'telephone' => '+224622000001',
        ]);
        $vehiculeA = Vehicule::factory()->create(['organization_id' => $this->org->id]);
        $vehiculeB = Vehicule::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->post(route('vehicules.parrain.store', $vehiculeA), ['personne_id' => $personne->id])
            ->assertRedirect();
        $this->actingAs($this->user)
            ->post(route('vehicules.parrain.store', $vehiculeB), ['personne_id' => $personne->id])
            ->assertRedirect();

        $vehiculeA->refresh();
        $vehiculeB->refresh();

        $this->assertSame($vehiculeA->parrain_id, $vehiculeB->parrain_id);
        $this->assertSame(1, Parrain::where('organization_id', $this->org->id)->where('personne_id', $personne->id)->count());
    }

    public function test_store_refuse_une_personne_dune_autre_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        $personneAutreOrg = Personne::factory()->create(['organization_id' => $autreOrg->id]);
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->post(route('vehicules.parrain.store', $vehicule), ['personne_id' => $personneAutreOrg->id])
            ->assertSessionHasErrors('personne_id');

        $this->assertNull($vehicule->fresh()->parrain_id);
    }

    public function test_store_refuse_sans_permission(): void
    {
        $user = $this->makeAdminUser();
        $vehicule = Vehicule::factory()->create(['organization_id' => $user->organization_id]);

        $this->actingAs($user)
            ->post(route('vehicules.parrain.store', $vehicule), [
                'nom_complet' => 'Mamadou Diallo',
                'telephone' => '622000001',
                'code_pays' => 'GN',
            ])
            ->assertStatus(403);
    }

    public function test_store_refuse_un_vehicule_dune_autre_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        $vehicule = Vehicule::factory()->create(['organization_id' => $autreOrg->id]);

        $this->actingAs($this->user)
            ->post(route('vehicules.parrain.store', $vehicule), [
                'nom_complet' => 'Mamadou Diallo',
                'telephone' => '622000001',
                'code_pays' => 'GN',
            ])
            ->assertStatus(403);
    }

    // ── update (édition en place) ─────────────────────────────────────────────

    public function test_update_modifie_lidentite_du_parrain_en_place(): void
    {
        $parrain = Parrain::factory()->create([
            'organization_id' => $this->org->id,
            'nom_complet' => 'Mamadou Diallo',
            'telephone' => '+224622000001',
        ]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'parrain_id' => $parrain->id,
        ]);

        $this->actingAs($this->user)
            ->put(route('vehicules.parrain.update', $vehicule), [
                'nom_complet' => 'Mamadou Diallo Junior',
                'telephone' => '622000001',
                'code_pays' => 'GN',
                'ville' => 'Kindia',
            ])
            ->assertRedirect(route('vehicules.show', $vehicule));

        $this->assertSame('Mamadou Diallo Junior', $parrain->fresh()->nom_complet);
        $this->assertSame('Kindia', $parrain->fresh()->ville);
    }

    public function test_update_refuse_un_telephone_deja_utilise_par_une_autre_personne(): void
    {
        Personne::factory()->create([
            'organization_id' => $this->org->id,
            'telephone' => '+224622000002',
        ]);
        $parrain = Parrain::factory()->create([
            'organization_id' => $this->org->id,
            'telephone' => '+224622000001',
        ]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'parrain_id' => $parrain->id,
        ]);

        $this->actingAs($this->user)
            ->put(route('vehicules.parrain.update', $vehicule), [
                'nom_complet' => 'Mamadou Diallo',
                'telephone' => '622000002',
                'code_pays' => 'GN',
            ])
            ->assertSessionHasErrors('telephone');
    }

    public function test_update_retourne_404_si_aucun_parrain_a_modifier(): void
    {
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id, 'parrain_id' => null]);

        $this->actingAs($this->user)
            ->put(route('vehicules.parrain.update', $vehicule), [
                'nom_complet' => 'Mamadou Diallo',
                'telephone' => '622000001',
                'code_pays' => 'GN',
            ])
            ->assertStatus(404);
    }
}
