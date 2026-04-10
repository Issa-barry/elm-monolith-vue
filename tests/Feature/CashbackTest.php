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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class CashbackTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function createOrgWithCashback(int $seuil = 100000, int $gain = 10000): Organization
    {
        $org = Organization::factory()->create();
        Feature::for($org)->activate(ModuleFeature::CASHBACK);

        // Crée les paramètres cashback pour cette org
        Parametre::factory()->create([
            'organization_id' => $org->id,
            'cle' => Parametre::CLE_CASHBACK_SEUIL_ACHAT,
            'valeur' => (string) $seuil,
            'type' => Parametre::TYPE_INTEGER,
            'groupe' => Parametre::GROUPE_CASHBACK,
        ]);
        Parametre::factory()->create([
            'organization_id' => $org->id,
            'cle' => Parametre::CLE_CASHBACK_MONTANT_GAIN,
            'valeur' => (string) $gain,
            'type' => Parametre::TYPE_INTEGER,
            'groupe' => Parametre::GROUPE_CASHBACK,
        ]);

        return $org;
    }

    private function makeVente(Organization $org, Client $client, int $montant): CommandeVente
    {
        return CommandeVente::factory()->create([
            'organization_id' => $org->id,
            'client_id' => $client->id,
            'total_commande' => $montant,
        ]);
    }

    private function staffUser(Organization $org): User
    {
        return User::factory()->create([
            'organization_id' => $org->id,
        ]);
    }

    // ── processVente ───────────────────────────────────────────────────────────

    public function test_process_vente_increments_cumul_achats(): void
    {
        $org = $this->createOrgWithCashback(seuil: 500000, gain: 25000);
        $client = Client::factory()->create(['organization_id' => $org->id]);

        $vente = $this->makeVente($org, $client, 200000);
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

        $vente = $this->makeVente($org, $client, 100000);
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

        $service->processVente($this->makeVente($org, $client, 100000));
        $service->processVente($this->makeVente($org, $client, 100000));

        $solde = CashbackSolde::where('client_id', $client->id)->first();
        $this->assertSame(200000, $solde->cumul_achats);
        $this->assertSame(0, $solde->cashback_en_attente);

        // Troisième vente → seuil atteint
        $service->processVente($this->makeVente($org, $client, 100000));

        $solde->refresh();
        $this->assertSame(0, $solde->cumul_achats);
        $this->assertSame(15000, $solde->cashback_en_attente);
    }

    public function test_pas_de_doublon_gain_pour_meme_vente(): void
    {
        $org = $this->createOrgWithCashback(seuil: 100000, gain: 10000);
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $service = new CashbackService();

        $vente = $this->makeVente($org, $client, 100000);

        $service->processVente($vente);
        $service->processVente($vente); // deuxième appel → idempotent

        $this->assertSame(
            1,
            CashbackTransaction::where('vente_id', $vente->id)
                ->where('type', CashbackTransaction::TYPE_GAIN)
                ->count(),
        );
    }

    public function test_pas_de_traitement_si_module_cashback_inactif(): void
    {
        $org = Organization::factory()->create();
        Feature::for($org)->deactivate(ModuleFeature::CASHBACK);

        $client = Client::factory()->create(['organization_id' => $org->id]);
        $vente = $this->makeVente($org, $client, 999999);

        // L'observer doit être silencieux si le module est inactif
        // On simule via processVente directement : ici les paramètres n'existent pas
        // donc seuil = défaut 500000 mais pas de solde créé car pas activé via observer
        // → on teste l'observer via la création de vente

        // Aucune transaction ne doit exister
        $this->assertDatabaseCount('cashback_transactions', 0);
        $this->assertDatabaseCount('cashback_soldes', 0);
    }

    public function test_vente_sans_client_ignoree(): void
    {
        $org = $this->createOrgWithCashback(seuil: 100000, gain: 10000);

        $vente = CommandeVente::factory()->create([
            'organization_id' => $org->id,
            'client_id' => null,
            'total_commande' => 200000,
        ]);

        (new CashbackService())->processVente($vente);

        $this->assertDatabaseCount('cashback_soldes', 0);
    }

    // ── verser ─────────────────────────────────────────────────────────────────

    public function test_verser_met_a_jour_transaction_et_solde(): void
    {
        $org = $this->createOrgWithCashback(seuil: 100000, gain: 10000);
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $staff = $this->staffUser($org);

        // Crée un gain manuellement
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
            'statut' => CashbackTransaction::STATUT_VERSE, // déjà versée
        ]);

        $this->expectException(\InvalidArgumentException::class);
        (new CashbackService())->verser($transaction, $staff);
    }

    // ── Controller / autorisations ─────────────────────────────────────────────

    public function test_index_accessible_admin_entreprise(): void
    {
        $org = $this->createOrgWithCashback();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');

        $this->actingAs($user)
            ->get('/cashback')
            ->assertOk();
    }

    public function test_index_interdit_role_client(): void
    {
        $org = $this->createOrgWithCashback();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('client');

        $this->actingAs($user)
            ->get('/cashback')
            ->assertForbidden();
    }

    public function test_verser_via_controller(): void
    {
        $org = $this->createOrgWithCashback();
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');

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
