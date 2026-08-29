<?php

namespace Tests\Feature\Api\Client;

use App\Enums\CategorieDepense;
use App\Enums\StatutDepense;
use App\Models\Depense;
use App\Models\DepenseType;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Organization;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\MakesClientProfiles;
use Tests\TestCase;

class DepensesControllerTest extends TestCase
{
    use MakesClientProfiles, RefreshDatabase;

    private function makeVehicule(Organization $org, string $proprietaireId, string $nom = 'Vehicule Test'): Vehicule
    {
        return Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => $proprietaireId,
            'nom_vehicule' => $nom,
        ]);
    }

    /** Un seul DepenseType par (org, code) — contrainte unique organization_id+code. */
    private function depenseType(Organization $org, string $code = 'carburant', string $libelle = 'Carburant'): DepenseType
    {
        return DepenseType::firstOrCreate(
            ['organization_id' => $org->id, 'code' => $code],
            ['libelle' => $libelle, 'categorie' => CategorieDepense::VEHICULE->value]
        );
    }

    private function makeDepense(Organization $org, Vehicule $vehicule, array $overrides = []): Depense
    {
        $type = $this->depenseType(
            $org,
            $overrides['type_code'] ?? 'carburant',
            $overrides['type_libelle'] ?? 'Carburant'
        );

        return Depense::factory()->create(array_merge([
            'organization_id' => $org->id,
            'beneficiaire_type' => 'vehicule',
            'beneficiaire_id' => $vehicule->id,
            'depense_type_id' => $type->id,
            'statut' => StatutDepense::VALIDE->value,
            'montant' => 5000,
            'date_depense' => now()->toDateString(),
        ], array_diff_key($overrides, array_flip(['type_code', 'type_libelle']))));
    }

    public function test_lists_depenses_across_all_accessible_vehicules(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehiculeA = $this->makeVehicule($org, $user->proprietaire->id, 'Vehicule A');
        $vehiculeB = $this->makeVehicule($org, $user->proprietaire->id, 'Vehicule B');

        $this->makeDepense($org, $vehiculeA, ['montant' => 4000]);
        $this->makeDepense($org, $vehiculeB, ['montant' => 6000]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.depenses.mine'))->assertOk();

        $response->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing(
            [4000.0, 6000.0],
            collect($response->json('data'))->pluck('montant')->all()
        );
    }

    public function test_avoids_n_plus_one_queries(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehiculeA = $this->makeVehicule($org, $user->proprietaire->id, 'Vehicule A');
        $vehiculeB = $this->makeVehicule($org, $user->proprietaire->id, 'Vehicule B');

        for ($i = 0; $i < 5; $i++) {
            $this->makeDepense($org, $vehiculeA);
            $this->makeDepense($org, $vehiculeB);
        }

        Sanctum::actingAs($user, ['*']);

        DB::enableQueryLog();
        $this->getJson(route('client.depenses.mine'))->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Borne large et stable : peu importe le nombre exact de requêtes (auth,
        // pagination count, dépenses + eager loads), il ne doit JAMAIS croître
        // avec le nombre de dépenses (10 ici) — un N+1 dépasserait largement.
        $this->assertLessThan(15, $queryCount);
    }

    public function test_livreur_can_see_depenses_of_their_teams_vehicule(): void
    {
        $org = Organization::factory()->create();
        $livreurUser = $this->makeLivreurUser($org);
        $proprietaireUser = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $proprietaireUser->proprietaire->id);

        $equipe = EquipeLivraison::create([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Equipe Test',
            'is_active' => true,
            'taux_commission_proprietaire' => 0,
        ]);
        EquipeLivreur::create([
            'equipe_id' => $equipe->id,
            'livreur_id' => $livreurUser->livreur->id,
            'role' => 'chauffeur',
            'ordre' => 1,
        ]);

        $this->makeDepense($org, $vehicule, ['montant' => 7000]);

        Sanctum::actingAs($livreurUser, ['*']);

        $this->getJson(route('client.depenses.mine'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.montant', 7000);
    }

    public function test_isolates_another_proprietaires_depenses(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->makeProprietaireUser($org);
        $ownerVehicule = $this->makeVehicule($org, $owner->proprietaire->id);
        $this->makeDepense($org, $ownerVehicule, ['montant' => 1000]);

        $other = $this->makeProprietaireUser($org);
        $otherVehicule = $this->makeVehicule($org, $other->proprietaire->id);
        $this->makeDepense($org, $otherVehicule, ['montant' => 9999]);

        Sanctum::actingAs($other, ['*']);

        $response = $this->getJson(route('client.depenses.mine'))->assertOk();

        $response->assertJsonCount(1, 'data');
        $this->assertSame(9999, $response->json('data.0.montant'));
    }

    public function test_isolates_another_organization(): void
    {
        $orgA = Organization::factory()->create();
        $userA = $this->makeProprietaireUser($orgA);
        $vehiculeA = $this->makeVehicule($orgA, $userA->proprietaire->id);
        $this->makeDepense($orgA, $vehiculeA, ['montant' => 1000]);

        $orgB = Organization::factory()->create();
        $userB = $this->makeProprietaireUser($orgB);

        Sanctum::actingAs($userB, ['*']);

        $this->getJson(route('client.depenses.mine'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_filters_by_vehicule_id(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehiculeA = $this->makeVehicule($org, $user->proprietaire->id, 'Vehicule A');
        $vehiculeB = $this->makeVehicule($org, $user->proprietaire->id, 'Vehicule B');

        $this->makeDepense($org, $vehiculeA, ['montant' => 1000]);
        $this->makeDepense($org, $vehiculeB, ['montant' => 2000]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.depenses.mine', ['vehicule_id' => $vehiculeA->id]))->assertOk();

        $response->assertJsonCount(1, 'data');
        $this->assertSame(1000, $response->json('data.0.montant'));
    }

    public function test_filters_by_statut(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);

        $this->makeDepense($org, $vehicule, ['montant' => 1000, 'statut' => StatutDepense::VALIDE->value]);
        $this->makeDepense($org, $vehicule, ['montant' => 2000, 'statut' => StatutDepense::BROUILLON->value]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.depenses.mine', ['statut' => StatutDepense::BROUILLON->value]))->assertOk();

        $response->assertJsonCount(1, 'data');
        $this->assertSame(2000, $response->json('data.0.montant'));
    }

    public function test_filters_by_depense_type(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);

        $typeCarburant = $this->depenseType($org, 'carburant', 'Carburant');
        $typeEntretien = $this->depenseType($org, 'entretien', 'Entretien');

        Depense::factory()->create([
            'organization_id' => $org->id, 'beneficiaire_type' => 'vehicule', 'beneficiaire_id' => $vehicule->id,
            'depense_type_id' => $typeCarburant->id, 'statut' => StatutDepense::VALIDE->value, 'montant' => 1000,
            'date_depense' => now()->toDateString(),
        ]);
        Depense::factory()->create([
            'organization_id' => $org->id, 'beneficiaire_type' => 'vehicule', 'beneficiaire_id' => $vehicule->id,
            'depense_type_id' => $typeEntretien->id, 'statut' => StatutDepense::VALIDE->value, 'montant' => 2000,
            'date_depense' => now()->toDateString(),
        ]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.depenses.mine', ['depense_type_id' => $typeEntretien->id]))->assertOk();

        $response->assertJsonCount(1, 'data');
        $this->assertSame('entretien', $response->json('data.0.type_code'));
    }

    public function test_filters_by_date_range(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);

        $this->makeDepense($org, $vehicule, ['montant' => 1000, 'date_depense' => now()->subYear()->toDateString()]);
        $this->makeDepense($org, $vehicule, ['montant' => 2000, 'date_depense' => now()->toDateString()]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.depenses.mine', [
            'date_debut' => now()->subDays(7)->toDateString(),
            'date_fin' => now()->toDateString(),
        ]))->assertOk();

        $response->assertJsonCount(1, 'data');
        $this->assertSame(2000, $response->json('data.0.montant'));
    }

    public function test_paginates_results(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);

        for ($i = 0; $i < 5; $i++) {
            $this->makeDepense($org, $vehicule, ['montant' => 1000 + $i]);
        }

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.depenses.mine', ['per_page' => 2]))->assertOk();

        $response->assertJsonCount(2, 'data');
        $this->assertSame(5, $response->json('meta.total'));
        $this->assertSame(3, $response->json('meta.last_page'));
    }

    public function test_returns_empty_list_when_no_data(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.depenses.mine'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_forbidden_for_staff_only_account(): void
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.depenses.mine'))->assertStatus(403);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson(route('client.depenses.mine'))->assertStatus(401);
    }
}
