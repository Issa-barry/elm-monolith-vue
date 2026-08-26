<?php

namespace Tests\Feature\Api\Client;

use App\Enums\CommissionActivationStatut;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutCommission;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionLogistique;
use App\Models\CommissionLogistiquePart;
use App\Models\CommissionProcessus;
use App\Models\Depense;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\MakesClientProfiles;
use Tests\TestCase;

/**
 * Contrat + parité Inertia/API du dashboard financier. Le même moteur
 * (ClientEarningsService) alimente `client/Dashboard` (Inertia, web) et
 * `GET /v1/mobile/dashboard` (cette classe) : chaque scénario ci-dessous
 * construit une seule fois les données puis vérifie que les deux surfaces
 * renvoient EXACTEMENT les mêmes montants, jamais une formule recalculée
 * indépendamment côté test.
 *
 * Note sur assertEquals vs assertSame : une valeur qui traverse un
 * json_encode/decode perd la distinction int/float (15000.0 redevient
 * l'entier 15000) — assertEquals est donc utilisé face à un littéral PHP
 * codé en dur, assertSame reste utilisé pour comparer deux valeurs QUI ONT
 * TOUTES LES DEUX déjà traversé ce même round-trip JSON (Inertia vs API),
 * où la perte de type est identique des deux côtés.
 */
class DashboardControllerTest extends TestCase
{
    use MakesClientProfiles, RefreshDatabase;

    private function inertiaProps(User $user, array $query = []): array
    {
        $version = (new HandleInertiaRequests)->version(request());

        $response = $this->actingAs($user)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
            ->get(route('client.dashboard', $query));

        $response->assertStatus(200);

        return json_decode($response->getContent(), true)['props'];
    }

    private function apiJson(User $user, array $query = []): array
    {
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.dashboard.mine', $query));
        $response->assertStatus(200);

        return $response->json();
    }

    private function makeTransfert(Organization $org, Vehicule $vehicule): TransfertLogistique
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $creator = User::factory()->create(['organization_id' => $org->id]);

        $site = Site::create([
            'organization_id' => $org->id,
            'nom' => 'Site '.uniqid(),
            'type' => 'depot',
            'localisation' => 'Test',
        ]);

        return TransfertLogistique::create([
            'organization_id' => $org->id,
            'reference' => 'TRF-'.uniqid(),
            'site_source_id' => $site->id,
            'site_destination_id' => $site->id,
            'vehicule_id' => $vehicule->id,
            'statut' => 'cloture',
            'created_by' => $creator->id,
        ]);
    }

    /** Un seul CommissionProcessus "vente" par organisation (contrainte unique organization_id+code). */
    private function venteProcessus(Organization $org): CommissionProcessus
    {
        return CommissionProcessus::firstOrCreate(
            ['organization_id' => $org->id, 'code' => CommissionProcessus::CODE_VENTE],
            [
                'libelle' => 'Vente',
                'declencheur' => 'chargement_valide',
                'strategie_ancrage_site' => 'operation',
                'statut' => CommissionActivationStatut::ACTIF->value,
            ]
        );
    }

    private function makeVenteCommission(
        Organization $org,
        Vehicule $vehicule,
        Proprietaire $proprietaire,
        float $montant,
        float $montantVerse = 0.0,
        ?string $earnedAt = null
    ): CommissionEnveloppePart {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::LIVRAISON_EN_COURS->value,
            'validated_at' => $earnedAt ?? now(),
        ]);

        $enveloppe = CommissionEnveloppe::create([
            'organization_id' => $org->id,
            'source_type' => CommandeVente::class,
            'source_id' => $commande->id,
            'processus_id' => $this->venteProcessus($org)->id,
            'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
            'cible_id' => (string) Str::ulid(),
            'montant_total' => $montant,
            'earned_at' => $earnedAt ?? now(),
            'statut' => $this->statutFor($montant, $montantVerse),
        ]);

        return CommissionEnveloppePart::create([
            'enveloppe_id' => $enveloppe->id,
            'beneficiaire_type' => CommissionEnveloppePart::TYPE_PROPRIETAIRE,
            'beneficiaire_id' => $proprietaire->id,
            'montant_brut' => $montant,
            'montant_net' => $montant,
            'montant_verse' => $montantVerse,
            'statut' => $this->statutFor($montant, $montantVerse),
        ]);
    }

    private function makeLogistiqueCommission(
        Organization $org,
        Vehicule $vehicule,
        Proprietaire $proprietaire,
        float $montant,
        float $montantVerse = 0.0
    ): CommissionLogistiquePart {
        $commission = CommissionLogistique::create([
            'organization_id' => $org->id,
            'transfert_logistique_id' => $this->makeTransfert($org, $vehicule)->id,
            'vehicule_id' => $vehicule->id,
            'base_calcul' => 'forfait',
            'valeur_base' => $montant,
            'montant_total' => $montant,
            'montant_verse' => $montantVerse,
            'statut' => $this->statutFor($montant, $montantVerse)->value,
        ]);

        return CommissionLogistiquePart::create([
            'commission_logistique_id' => $commission->id,
            'type_beneficiaire' => 'proprietaire',
            'proprietaire_id' => $proprietaire->id,
            'beneficiaire_nom' => 'Proprietaire Test',
            'taux_commission' => 100,
            'montant_brut' => $montant,
            'frais_supplementaires' => 0,
            'montant_net' => $montant,
            'montant_verse' => $montantVerse,
            'statut' => $this->statutFor($montant, $montantVerse),
            'earned_at' => now()->toDateString(),
        ]);
    }

    private function statutFor(float $montant, float $verse): StatutCommission
    {
        return match (true) {
            $verse <= 0 => StatutCommission::IMPAYE,
            $verse >= $montant => StatutCommission::PAYE,
            default => StatutCommission::PARTIEL,
        };
    }

    private function makeVehicule(Organization $org, Proprietaire $proprietaire, string $nom = 'Vehicule Test'): Vehicule
    {
        return Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => $proprietaire->id,
            'nom_vehicule' => $nom,
        ]);
    }

    // ── Parité Inertia ↔ API ──────────────────────────────────────────────────

    public function test_parity_vente_only(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $proprietaire = $user->proprietaire;
        $vehicule = $this->makeVehicule($org, $proprietaire);

        $this->makeVenteCommission($org, $vehicule, $proprietaire, 15000, 0);

        $inertia = $this->inertiaProps($user);
        $api = $this->apiJson($user);

        $this->assertEquals(15000.0, $inertia['earnings']['total_earned']);
        $this->assertSame($inertia['earnings']['total_earned'], $api['summary']['total_earned']);
        $this->assertSame($inertia['earnings']['total_paid'], $api['summary']['total_paid']);
        $this->assertSame($inertia['earnings']['balance'], $api['summary']['balance']);
        $this->assertSame($inertia['earnings_by_vehicule'][0]['total_earned'], $api['par_vehicule'][0]['total_earned']);
        $this->assertSame($inertia['earnings_by_vehicule'][0]['vehicule_id'], $api['par_vehicule'][0]['vehicule_id']);
    }

    public function test_parity_logistique_only(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $proprietaire = $user->proprietaire;
        $vehicule = $this->makeVehicule($org, $proprietaire);

        $this->makeLogistiqueCommission($org, $vehicule, $proprietaire, 8000, 0);

        $inertia = $this->inertiaProps($user);
        $api = $this->apiJson($user);

        $this->assertEquals(8000.0, $inertia['earnings']['total_earned']);
        $this->assertSame($inertia['earnings']['total_earned'], $api['summary']['total_earned']);
        $this->assertSame($inertia['earnings_by_vehicule'][0]['total_earned'], $api['par_vehicule'][0]['total_earned']);
    }

    public function test_parity_vente_and_logistique(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $proprietaire = $user->proprietaire;
        $vehicule = $this->makeVehicule($org, $proprietaire);

        $this->makeVenteCommission($org, $vehicule, $proprietaire, 15000, 3000);
        $this->makeLogistiqueCommission($org, $vehicule, $proprietaire, 8000, 8000);

        $inertia = $this->inertiaProps($user);
        $api = $this->apiJson($user);

        $this->assertEquals(23000.0, $inertia['earnings']['total_earned']);
        $this->assertEquals(11000.0, $inertia['earnings']['total_paid']);
        $this->assertSame($inertia['earnings'], $api['summary']);
        $this->assertSame($inertia['earnings_by_vehicule'][0]['total_earned'], $api['par_vehicule'][0]['total_earned']);
        $this->assertSame($inertia['earnings_by_vehicule'][0]['total_paid'], $api['par_vehicule'][0]['total_paid']);
    }

    public function test_parity_with_depenses(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $proprietaire = $user->proprietaire;
        $vehicule = $this->makeVehicule($org, $proprietaire);

        // Paiement PARTIEL (5000 sur 15000) : la formule `balance` ne descend
        // jamais sous 0 (comportement documenté de calculateEarnings()) — un
        // scénario entièrement payé masquerait l'effet des dépenses ici.
        $this->makeVenteCommission($org, $vehicule, $proprietaire, 15000, 5000);

        Depense::factory()->valide()->create([
            'organization_id' => $org->id,
            'beneficiaire_type' => 'vehicule',
            'beneficiaire_id' => $vehicule->id,
            'montant' => 4000,
            'date_depense' => now()->toDateString(),
        ]);

        $inertia = $this->inertiaProps($user);
        $api = $this->apiJson($user);

        $this->assertEquals(4000.0, $inertia['earnings']['frais_depenses_total']);
        $this->assertEquals(6000.0, $inertia['earnings']['balance']);
        $this->assertSame($inertia['earnings'], $api['summary']);
        $this->assertSame($inertia['earnings_by_vehicule'][0]['frais_depenses'], $api['par_vehicule'][0]['frais_depenses']);
    }

    public function test_parity_full_payment(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $proprietaire = $user->proprietaire;
        $vehicule = $this->makeVehicule($org, $proprietaire);

        $this->makeVenteCommission($org, $vehicule, $proprietaire, 10000, 10000);

        $inertia = $this->inertiaProps($user);
        $api = $this->apiJson($user);

        $this->assertEquals(0.0, $inertia['earnings']['balance']);
        $this->assertSame($inertia['earnings'], $api['summary']);
    }

    public function test_parity_partial_payment(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $proprietaire = $user->proprietaire;
        $vehicule = $this->makeVehicule($org, $proprietaire);

        $this->makeVenteCommission($org, $vehicule, $proprietaire, 10000, 4000);

        $inertia = $this->inertiaProps($user);
        $api = $this->apiJson($user);

        $this->assertEquals(6000.0, $inertia['earnings']['balance']);
        $this->assertSame($inertia['earnings'], $api['summary']);
    }

    public function test_parity_no_payment(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $proprietaire = $user->proprietaire;
        $vehicule = $this->makeVehicule($org, $proprietaire);

        $this->makeVenteCommission($org, $vehicule, $proprietaire, 10000, 0);

        $inertia = $this->inertiaProps($user);
        $api = $this->apiJson($user);

        $this->assertEquals(0.0, $inertia['earnings']['total_paid']);
        $this->assertEquals(10000.0, $inertia['earnings']['balance']);
        $this->assertSame($inertia['earnings'], $api['summary']);
    }

    public function test_parity_multiple_vehicules(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $proprietaire = $user->proprietaire;
        $vehiculeA = $this->makeVehicule($org, $proprietaire, 'Vehicule A');
        $vehiculeB = $this->makeVehicule($org, $proprietaire, 'Vehicule B');

        $this->makeVenteCommission($org, $vehiculeA, $proprietaire, 15000, 5000);
        $this->makeVenteCommission($org, $vehiculeB, $proprietaire, 9000, 9000);

        $inertia = $this->inertiaProps($user);
        $api = $this->apiJson($user);

        $this->assertCount(2, $inertia['earnings_by_vehicule']);
        $this->assertSame($inertia['earnings_by_vehicule'], $api['par_vehicule']);
        $this->assertSame($inertia['earnings'], $api['summary']);
    }

    public function test_parity_no_data(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);

        $inertia = $this->inertiaProps($user);
        $api = $this->apiJson($user);

        $this->assertEquals(0.0, $inertia['earnings']['total_earned']);
        $this->assertSame([], $inertia['earnings_by_vehicule']);
        $this->assertSame($inertia['earnings'], $api['summary']);
        $this->assertSame([], $api['par_vehicule']);
    }

    public function test_parity_multi_role_staff_and_proprietaire(): void
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $user->assignRole('admin_entreprise');
        $proprietaire = $user->proprietaire;
        $vehicule = $this->makeVehicule($org, $proprietaire);

        $this->makeVenteCommission($org, $vehicule, $proprietaire, 12000, 2000);

        $inertia = $this->inertiaProps($user);
        $api = $this->apiJson($user);

        $this->assertEquals(12000.0, $inertia['earnings']['total_earned']);
        $this->assertSame($inertia['earnings'], $api['summary']);
    }

    // ── Isolation / autorisation ─────────────────────────────────────────────

    public function test_isolates_another_proprietaires_earnings(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->makeProprietaireUser($org);
        $ownerVehicule = $this->makeVehicule($org, $owner->proprietaire);
        $this->makeVenteCommission($org, $ownerVehicule, $owner->proprietaire, 15000, 0);

        $other = $this->makeProprietaireUser($org);
        $otherVehicule = $this->makeVehicule($org, $other->proprietaire);
        $this->makeVenteCommission($org, $otherVehicule, $other->proprietaire, 99999, 0);

        $api = $this->apiJson($other);

        $this->assertEquals(99999.0, $api['summary']['total_earned']);
        $this->assertCount(1, $api['par_vehicule']);
        $this->assertSame($otherVehicule->id, $api['par_vehicule'][0]['vehicule_id']);
    }

    public function test_isolates_another_organization(): void
    {
        $orgA = Organization::factory()->create();
        $userA = $this->makeProprietaireUser($orgA);
        $vehiculeA = $this->makeVehicule($orgA, $userA->proprietaire);
        $this->makeVenteCommission($orgA, $vehiculeA, $userA->proprietaire, 20000, 0);

        $orgB = Organization::factory()->create();
        $userB = $this->makeProprietaireUser($orgB);

        $api = $this->apiJson($userB);

        $this->assertEquals(0.0, $api['summary']['total_earned']);
        $this->assertSame([], $api['par_vehicule']);
    }

    public function test_forbidden_for_staff_only_account(): void
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.dashboard.mine'))->assertStatus(403);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson(route('client.dashboard.mine'))->assertStatus(401);
    }

    public function test_filters_by_vehicule_id(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $proprietaire = $user->proprietaire;
        $vehiculeA = $this->makeVehicule($org, $proprietaire, 'Vehicule A');
        $vehiculeB = $this->makeVehicule($org, $proprietaire, 'Vehicule B');

        $this->makeVenteCommission($org, $vehiculeA, $proprietaire, 15000, 0);
        $this->makeVenteCommission($org, $vehiculeB, $proprietaire, 9000, 0);

        $api = $this->apiJson($user, ['period' => 'custom', 'vehicule_id' => $vehiculeA->id]);

        // Le total agrégé ne retient que le véhicule filtré, mais le détail par
        // véhicule liste toujours l'ensemble du parc accessible (même
        // comportement que le dashboard Inertia — cf. dashboardPayload() : seul
        // le calcul des montants est restreint, pas la liste des lignes).
        $this->assertEquals(15000.0, $api['summary']['total_earned']);
        $this->assertCount(2, $api['par_vehicule']);
        $parVehiculeA = collect($api['par_vehicule'])->firstWhere('vehicule_id', $vehiculeA->id);
        $parVehiculeB = collect($api['par_vehicule'])->firstWhere('vehicule_id', $vehiculeB->id);
        $this->assertEquals(15000.0, $parVehiculeA['total_earned']);
        $this->assertEquals(0.0, $parVehiculeB['total_earned']);
    }

    public function test_filters_by_custom_period_excludes_out_of_range_earnings(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $proprietaire = $user->proprietaire;
        $vehicule = $this->makeVehicule($org, $proprietaire);

        $this->makeVenteCommission($org, $vehicule, $proprietaire, 15000, 0, now()->subYear()->toDateTimeString());

        $api = $this->apiJson($user, [
            'period' => 'custom',
            'date_debut' => now()->subDays(7)->toDateString(),
            'date_fin' => now()->toDateString(),
        ]);

        $this->assertEquals(0.0, $api['summary']['total_earned']);
    }
}
