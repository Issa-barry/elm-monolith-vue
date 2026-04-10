<?php

namespace Tests\Feature;

use App\Features\ModuleFeature;
use App\Models\CashbackSolde;
use App\Models\CashbackTransaction;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\Organization;
use App\Models\Parametre;
use App\Models\User;
use App\Services\CashbackService;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CashbackTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function createOrgWithCashback(int $seuil = 100000, int $gain = 10000): Organization
    {
        $org = Organization::factory()->create();
        Feature::for($org)->activate(ModuleFeature::CASHBACK);

        Parametre::create([
            'organization_id' => $org->id,
            'cle' => Parametre::CLE_CASHBACK_SEUIL_ACHAT,
            'valeur' => (string) $seuil,
            'type' => Parametre::TYPE_INTEGER,
            'groupe' => Parametre::GROUPE_CASHBACK,
            'description' => 'Seuil test',
        ]);
        Parametre::create([
            'organization_id' => $org->id,
            'cle' => Parametre::CLE_CASHBACK_MONTANT_GAIN,
            'valeur' => (string) $gain,
            'type' => Parametre::TYPE_INTEGER,
            'groupe' => Parametre::GROUPE_CASHBACK,
            'description' => 'Gain test',
        ]);

        return $org;
    }

    /**
     * Crée une vente SANS déclencher l'observer (pour tester le service directement).
     */
    private function makeVenteSilently(Organization $org, Client $client, int $montant): CommandeVente
    {
        return CommandeVente::withoutEvents(fn () => CommandeVente::factory()->create([
            'organization_id' => $org->id,
            'client_id' => $client->id,
            'total_commande' => $montant,
        ]));
    }

    /**
     * Crée une vente en déclenchant l'observer (pour tester le flux complet).
     */
    private function makeVente(Organization $org, Client $client, int $montant): CommandeVente
    {
        return CommandeVente::factory()->create([
            'organization_id' => $org->id,
            'client_id' => $client->id,
            'total_commande' => $montant,
        ]);
    }

    private function staffUser(Organization $org, string $role = 'admin_entreprise'): User
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole($role);

        // Attache un site par défaut pour passer le middleware RequireSiteAssigned
        $site = Site::create([
            'organization_id' => $org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    // ── processVente (via service direct, sans observer) ───────────────────────

    public function test_process_vente_increments_cumul_achats(): void
    {
        $org = $this->createOrgWithCashback(seuil: 500000, gain: 25000);
        $client = Client::factory()->create(['organization_id' => $org->id]);

        $vente = $this->makeVenteSilently($org, $client, 200000);
        (new CashbackService())->processVente($vente);

        $solde = CashbackSolde::where('client_id', $client->id)->first();

        $this->assertNotNull($solde);
        $this->assertSame(200000, $solde->cumul_achats);
        $this->assertSame(0, $solde->cashback_en_attente);
    }

    public function test_gain_cree_quand_seuil_atteint(): void
    {
        $org = $this->createOrgWithCashback(seuil: 100000, gain: 10000);
        $client = Client::factory()->create(['organization_id' => $org->id]);

        $vente = $this->makeVenteSilently($org, $client, 100000);
        (new CashbackService())->processVente($vente);

        $this->assertDatabaseHas('cashback_transactions', [
            'organization_id' => $org->id,
            'client_id' => $client->id,
            'type' => CashbackTransaction::TYPE_GAIN,
            'montant' => 10000,
            'statut' => CashbackTransaction::STATUT_EN_ATTENTE,
            'vente_id' => $vente->id,
        ]);

        $solde = CashbackSolde::where('client_id', $client->id)->first();
        $this->assertSame(0, $solde->cumul_achats);               // remis à zéro
        $this->assertSame(10000, $solde->cashback_en_attente);
        $this->assertSame(10000, $solde->total_cashback_gagne);
    }

    public function test_cumul_entre_plusieurs_ventes_avant_seuil(): void
    {
        $org = $this->createOrgWithCashback(seuil: 300000, gain: 15000);
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $service = new CashbackService();

        $service->processVente($this->makeVenteSilently($org, $client, 100000));
        $service->processVente($this->makeVenteSilently($org, $client, 100000));

        $solde = CashbackSolde::where('client_id', $client->id)->first();
        $this->assertSame(200000, $solde->cumul_achats);
        $this->assertSame(0, $solde->cashback_en_attente);

        // Troisième vente → seuil atteint
        $service->processVente($this->makeVenteSilently($org, $client, 100000));

        $solde->refresh();
        $this->assertSame(0, $solde->cumul_achats);
        $this->assertSame(15000, $solde->cashback_en_attente);
    }

    public function test_pas_de_doublon_gain_pour_meme_vente(): void
    {
        $org = $this->createOrgWithCashback(seuil: 100000, gain: 10000);
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $service = new CashbackService();

        $vente = $this->makeVenteSilently($org, $client, 100000);

        $service->processVente($vente);
        $service->processVente($vente); // deuxième appel → idempotent

        $this->assertSame(
            1,
            CashbackTransaction::where('vente_id', $vente->id)
                ->where('type', CashbackTransaction::TYPE_GAIN)
                ->count(),
        );
    }

    public function test_observer_declenche_gain_via_creation_vente(): void
    {
        $org = $this->createOrgWithCashback(seuil: 100000, gain: 10000);
        $client = Client::factory()->create(['organization_id' => $org->id]);

        // Ici makeVente déclenche l'observer
        $this->makeVente($org, $client, 100000);

        $this->assertDatabaseHas('cashback_transactions', [
            'client_id' => $client->id,
            'type' => CashbackTransaction::TYPE_GAIN,
            'statut' => CashbackTransaction::STATUT_EN_ATTENTE,
        ]);
    }

    public function test_pas_de_traitement_si_module_cashback_inactif(): void
    {
        $org = Organization::factory()->create();
        Feature::for($org)->deactivate(ModuleFeature::CASHBACK);

        $client = Client::factory()->create(['organization_id' => $org->id]);

        // L'observer skipe si module inactif
        $this->makeVente($org, $client, 999999);

        $this->assertDatabaseCount('cashback_transactions', 0);
        $this->assertDatabaseCount('cashback_soldes', 0);
    }

    public function test_vente_sans_client_ignoree(): void
    {
        $org = $this->createOrgWithCashback(seuil: 100000, gain: 10000);

        $vente = $this->makeVenteSilently($org, Client::factory()->create(['organization_id' => $org->id]), 200000);
        $vente->client_id = null;

        (new CashbackService())->processVente($vente);

        $this->assertDatabaseCount('cashback_soldes', 0);
    }

    // ── verser ─────────────────────────────────────────────────────────────────

    public function test_verser_met_a_jour_transaction_et_solde(): void
    {
        $org = $this->createOrgWithCashback(seuil: 100000, gain: 10000);
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $staff = $this->staffUser($org);

        $transaction = CashbackTransaction::create([
            'organization_id' => $org->id,
            'client_id' => $client->id,
            'type' => CashbackTransaction::TYPE_GAIN,
            'montant' => 10000,
            'statut' => CashbackTransaction::STATUT_EN_ATTENTE,
        ]);

        CashbackSolde::create([
            'organization_id' => $org->id,
            'client_id' => $client->id,
            'cumul_achats' => 0,
            'cashback_en_attente' => 10000,
            'total_cashback_gagne' => 10000,
            'total_cashback_verse' => 0,
        ]);

        (new CashbackService())->verser($transaction, $staff, 'Remis en main propre');

        $transaction->refresh();
        $this->assertSame(CashbackTransaction::STATUT_VERSE, $transaction->statut);
        $this->assertSame($staff->id, $transaction->verse_par);
        $this->assertSame('Remis en main propre', $transaction->note);
        $this->assertNotNull($transaction->verse_le);

        $solde = CashbackSolde::where('client_id', $client->id)->first();
        $this->assertSame(0, $solde->cashback_en_attente);
        $this->assertSame(10000, $solde->total_cashback_verse);
    }

    public function test_verser_deja_versee_leve_exception(): void
    {
        $org = $this->createOrgWithCashback();
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $staff = $this->staffUser($org);

        $transaction = CashbackTransaction::create([
            'organization_id' => $org->id,
            'client_id' => $client->id,
            'type' => CashbackTransaction::TYPE_GAIN,
            'montant' => 10000,
            'statut' => CashbackTransaction::STATUT_VERSE,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        (new CashbackService())->verser($transaction, $staff);
    }

    // ── Controller / autorisations ─────────────────────────────────────────────

    public function test_index_accessible_admin_entreprise(): void
    {
        $org = $this->createOrgWithCashback();
        $user = $this->staffUser($org, 'admin_entreprise');

        $this->actingAs($user)
            ->get('/cashback')
            ->assertOk();
    }

    public function test_index_interdit_role_client(): void
    {
        $org = $this->createOrgWithCashback();
        Role::firstOrCreate(['name' => 'client', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('client');

        // Le rôle client est bloqué par le middleware 'role:' du groupe staff
        // ET par la policy → 403
        $this->actingAs($user)
            ->get('/cashback')
            ->assertForbidden();
    }

    public function test_verser_via_controller(): void
    {
        $org = $this->createOrgWithCashback();
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $user = $this->staffUser($org, 'admin_entreprise');

        $transaction = CashbackTransaction::create([
            'organization_id' => $org->id,
            'client_id' => $client->id,
            'type' => CashbackTransaction::TYPE_GAIN,
            'montant' => 10000,
            'statut' => CashbackTransaction::STATUT_EN_ATTENTE,
        ]);

        CashbackSolde::create([
            'organization_id' => $org->id,
            'client_id' => $client->id,
            'cumul_achats' => 0,
            'cashback_en_attente' => 10000,
            'total_cashback_gagne' => 10000,
            'total_cashback_verse' => 0,
        ]);

        $this->actingAs($user)
            ->patch("/cashback/{$transaction->id}/verser", ['note' => 'Test'])
            ->assertRedirect();

        $transaction->refresh();
        $this->assertSame(CashbackTransaction::STATUT_VERSE, $transaction->statut);
    }
}
