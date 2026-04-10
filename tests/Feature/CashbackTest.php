<?php

namespace Tests\Feature;

use App\Features\ModuleFeature;
use App\Models\CashbackSolde;
use App\Models\CashbackTransaction;
use App\Models\CashbackVersement;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\Organization;
use App\Models\Parametre;
use App\Models\Site;
use App\Models\User;
use App\Services\CashbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
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
            'cle'             => Parametre::CLE_CASHBACK_SEUIL_ACHAT,
            'valeur'          => (string) $seuil,
            'type'            => Parametre::TYPE_INTEGER,
            'groupe'          => Parametre::GROUPE_CASHBACK,
            'description'     => 'Seuil test',
        ]);
        Parametre::create([
            'organization_id' => $org->id,
            'cle'             => Parametre::CLE_CASHBACK_MONTANT_GAIN,
            'valeur'          => (string) $gain,
            'type'            => Parametre::TYPE_INTEGER,
            'groupe'          => Parametre::GROUPE_CASHBACK,
            'description'     => 'Gain test',
        ]);

        return $org;
    }

    private function makeVenteSilently(Organization $org, Client $client, int $montant): CommandeVente
    {
        return CommandeVente::withoutEvents(fn () => CommandeVente::factory()->create([
            'organization_id' => $org->id,
            'client_id'       => $client->id,
            'total_commande'  => $montant,
        ]));
    }

    private function staffUser(Organization $org, string $role = 'admin_entreprise'): User
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole($role);

        $site = Site::create([
            'organization_id' => $org->id,
            'nom'             => 'Site Test',
            'type'            => 'depot',
            'localisation'    => 'Conakry',
        ]);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    /** Crée une transaction en attente avec un solde associé (firstOrCreate pour éviter les doublons). */
    private function makeTransaction(Organization $org, Client $client, int $montant, string $statut = CashbackTransaction::STATUT_EN_ATTENTE): CashbackTransaction
    {
        $t = CashbackTransaction::create([
            'organization_id' => $org->id,
            'client_id'       => $client->id,
            'type'            => CashbackTransaction::TYPE_GAIN,
            'montant'         => $montant,
            'montant_verse'   => 0,
            'statut'          => $statut,
        ]);

        CashbackSolde::firstOrCreate(
            ['organization_id' => $org->id, 'client_id' => $client->id],
            ['cumul_achats' => 0, 'cashback_en_attente' => 0, 'total_cashback_gagne' => 0, 'total_cashback_verse' => 0],
        )->increment('cashback_en_attente', $montant);

        return $t;
    }

    // ── processVente ───────────────────────────────────────────────────────────

    public function test_process_vente_increments_cumul_achats(): void
    {
        $org    = $this->createOrgWithCashback(seuil: 500000, gain: 25000);
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
        $org    = $this->createOrgWithCashback(seuil: 100000, gain: 10000);
        $client = Client::factory()->create(['organization_id' => $org->id]);

        $vente = $this->makeVenteSilently($org, $client, 100000);
        (new CashbackService())->processVente($vente);

        $this->assertDatabaseHas('cashback_transactions', [
            'organization_id' => $org->id,
            'client_id'       => $client->id,
            'type'            => CashbackTransaction::TYPE_GAIN,
            'montant'         => 10000,
            'statut'          => CashbackTransaction::STATUT_EN_ATTENTE,
            'vente_id'        => $vente->id,
        ]);

        $solde = CashbackSolde::where('client_id', $client->id)->first();
        $this->assertSame(0, $solde->cumul_achats);
        $this->assertSame(10000, $solde->cashback_en_attente);
        $this->assertSame(10000, $solde->total_cashback_gagne);
    }

    public function test_cumul_entre_plusieurs_ventes_avant_seuil(): void
    {
        $org     = $this->createOrgWithCashback(seuil: 300000, gain: 15000);
        $client  = Client::factory()->create(['organization_id' => $org->id]);
        $service = new CashbackService();

        $service->processVente($this->makeVenteSilently($org, $client, 100000));
        $service->processVente($this->makeVenteSilently($org, $client, 100000));

        $solde = CashbackSolde::where('client_id', $client->id)->first();
        $this->assertSame(200000, $solde->cumul_achats);
        $this->assertSame(0, $solde->cashback_en_attente);

        $service->processVente($this->makeVenteSilently($org, $client, 100000));

        $solde->refresh();
        $this->assertSame(0, $solde->cumul_achats);
        $this->assertSame(15000, $solde->cashback_en_attente);
    }

    public function test_pas_de_doublon_gain_pour_meme_vente(): void
    {
        $org     = $this->createOrgWithCashback(seuil: 100000, gain: 10000);
        $client  = Client::factory()->create(['organization_id' => $org->id]);
        $service = new CashbackService();

        $vente = $this->makeVenteSilently($org, $client, 100000);
        $service->processVente($vente);
        $service->processVente($vente); // idempotent

        $this->assertSame(
            1,
            CashbackTransaction::where('vente_id', $vente->id)
                ->where('type', CashbackTransaction::TYPE_GAIN)
                ->count(),
        );
    }

    public function test_vente_sans_client_ignoree(): void
    {
        $org   = $this->createOrgWithCashback(seuil: 100000, gain: 10000);
        $vente = $this->makeVenteSilently($org, Client::factory()->create(['organization_id' => $org->id]), 200000);
        $vente->client_id = null;

        (new CashbackService())->processVente($vente);

        $this->assertDatabaseCount('cashback_soldes', 0);
    }

    // ── valider ────────────────────────────────────────────────────────────────

    public function test_valider_passe_statut_en_valide(): void
    {
        $org    = $this->createOrgWithCashback();
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $staff  = $this->staffUser($org);

        $t = $this->makeTransaction($org, $client, 10000);

        (new CashbackService())->valider($t, $staff, 'OK');

        $t->refresh();
        $this->assertSame(CashbackTransaction::STATUT_VALIDE, $t->statut);
        $this->assertSame($staff->id, $t->valide_par);
        $this->assertSame('OK', $t->note);
        $this->assertNotNull($t->valide_le);
    }

    public function test_valider_deja_valide_leve_exception(): void
    {
        $org    = $this->createOrgWithCashback();
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $staff  = $this->staffUser($org);
        $t      = $this->makeTransaction($org, $client, 10000, CashbackTransaction::STATUT_VALIDE);

        $this->expectException(\InvalidArgumentException::class);
        (new CashbackService())->valider($t, $staff);
    }

    // ── verser (versement total) ───────────────────────────────────────────────

    public function test_verser_total_passe_statut_verse(): void
    {
        $org    = $this->createOrgWithCashback();
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $staff  = $this->staffUser($org);
        $t      = $this->makeTransaction($org, $client, 10000, CashbackTransaction::STATUT_VALIDE);

        (new CashbackService())->verser($t, $staff, 10000, 'especes', '2026-04-10', 'Remis en main propre');

        $t->refresh();
        $this->assertSame(CashbackTransaction::STATUT_VERSE, $t->statut);
        $this->assertSame(10000, $t->montant_verse);
        $this->assertSame(0, $t->montant_restant);
        $this->assertSame($staff->id, $t->verse_par);
        $this->assertNotNull($t->verse_le);

        $this->assertDatabaseHas('cashback_versements', [
            'cashback_transaction_id' => $t->id,
            'montant'                 => 10000,
            'mode_paiement'           => 'especes',
        ]);

        $solde = CashbackSolde::where('client_id', $client->id)->first();
        $this->assertSame(0, $solde->cashback_en_attente);
        $this->assertSame(10000, $solde->total_cashback_verse);
    }

    public function test_verser_partiel_passe_statut_partiel(): void
    {
        $org    = $this->createOrgWithCashback();
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $staff  = $this->staffUser($org);
        $t      = $this->makeTransaction($org, $client, 10000, CashbackTransaction::STATUT_VALIDE);

        (new CashbackService())->verser($t, $staff, 3000, 'mobile_money', '2026-04-10');

        $t->refresh();
        $this->assertSame(CashbackTransaction::STATUT_PARTIEL, $t->statut);
        $this->assertSame(3000, $t->montant_verse);
        $this->assertSame(7000, $t->montant_restant);
    }

    public function test_versement_partiel_puis_solde_complet(): void
    {
        $org    = $this->createOrgWithCashback();
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $staff  = $this->staffUser($org);
        $t      = $this->makeTransaction($org, $client, 10000, CashbackTransaction::STATUT_VALIDE);

        $service = new CashbackService();
        $service->verser($t, $staff, 3000, 'especes', '2026-04-10');
        $t->refresh();
        $this->assertSame(CashbackTransaction::STATUT_PARTIEL, $t->statut);

        $service->verser($t, $staff, 7000, 'mobile_money', '2026-04-11');
        $t->refresh();
        $this->assertSame(CashbackTransaction::STATUT_VERSE, $t->statut);
        $this->assertSame(10000, $t->montant_verse);
        $this->assertSame(0, $t->montant_restant);

        $this->assertSame(2, CashbackVersement::where('cashback_transaction_id', $t->id)->count());
    }

    public function test_verser_montant_superieur_au_restant_leve_exception(): void
    {
        $org    = $this->createOrgWithCashback();
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $staff  = $this->staffUser($org);
        $t      = $this->makeTransaction($org, $client, 10000, CashbackTransaction::STATUT_VALIDE);

        $this->expectException(\InvalidArgumentException::class);
        (new CashbackService())->verser($t, $staff, 99999, 'especes', '2026-04-10');
    }

    public function test_verser_transaction_non_versable_leve_exception(): void
    {
        $org    = $this->createOrgWithCashback();
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $staff  = $this->staffUser($org);
        // en_attente → non versable (pas encore validée)
        $t = $this->makeTransaction($org, $client, 10000, CashbackTransaction::STATUT_EN_ATTENTE);

        $this->expectException(\InvalidArgumentException::class);
        (new CashbackService())->verser($t, $staff, 10000, 'especes', '2026-04-10');
    }

    public function test_verser_transaction_deja_verse_leve_exception(): void
    {
        $org    = $this->createOrgWithCashback();
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $staff  = $this->staffUser($org);
        $t      = $this->makeTransaction($org, $client, 10000, CashbackTransaction::STATUT_VERSE);

        $this->expectException(\InvalidArgumentException::class);
        (new CashbackService())->verser($t, $staff, 10000, 'especes', '2026-04-10');
    }

    // ── Cohérence des montants (règles métier minimales) ──────────────────────

    public function test_montant_restant_est_max_zero(): void
    {
        $t = new CashbackTransaction(['montant' => 100, 'montant_verse' => 150]);
        $this->assertSame(0, $t->montant_restant);
    }

    public function test_is_versable_valide_et_partiel_uniquement(): void
    {
        foreach ([CashbackTransaction::STATUT_VALIDE, CashbackTransaction::STATUT_PARTIEL] as $s) {
            $t = new CashbackTransaction(['statut' => $s]);
            $this->assertTrue($t->isVersable(), "statut=$s devrait être versable");
        }

        foreach ([CashbackTransaction::STATUT_EN_ATTENTE, CashbackTransaction::STATUT_VERSE] as $s) {
            $t = new CashbackTransaction(['statut' => $s]);
            $this->assertFalse($t->isVersable(), "statut=$s ne devrait pas être versable");
        }
    }

    // ── Données héritées (bug statut='verse' + montant_verse=0) ───────────────

    public function test_controller_calcule_montant_verse_depuis_versements(): void
    {
        $org    = $this->createOrgWithCashback();
        $user   = $this->staffUser($org);
        $client = Client::factory()->create(['organization_id' => $org->id]);

        // Simule un legacy : statut='verse' mais montant_verse=0, aucun versement
        CashbackTransaction::create([
            'organization_id' => $org->id,
            'client_id'       => $client->id,
            'type'            => CashbackTransaction::TYPE_GAIN,
            'montant'         => 100,
            'montant_verse'   => 0,   // donnée héritée incohérente
            'statut'          => CashbackTransaction::STATUT_VERSE,
        ]);

        $this->actingAs($user)
            ->get('/cashback')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Cashback/Index')
                ->has('transactions', 1)
                // Le controller recompute depuis la relation — 0 versement = montant_verse 0
                ->where('transactions.0.montant_verse', 0)
                ->where('transactions.0.montant_restant', 100)
            );
    }

    public function test_migration_repair_corrige_verse_sans_versements(): void
    {
        // Simule l'état incohérent avant la migration de réparation
        $org    = $this->createOrgWithCashback();
        $client = Client::factory()->create(['organization_id' => $org->id]);

        $stale = CashbackTransaction::create([
            'organization_id' => $org->id,
            'client_id'       => $client->id,
            'type'            => CashbackTransaction::TYPE_GAIN,
            'montant'         => 100,
            'montant_verse'   => 0,
            'statut'          => CashbackTransaction::STATUT_VERSE,
        ]);

        // Rejoue la logique de réparation
        \Illuminate\Support\Facades\DB::table('cashback_transactions as ct')
            ->leftJoin('cashback_versements as cv', 'cv.cashback_transaction_id', '=', 'ct.id')
            ->whereNull('cv.id')
            ->where('ct.statut', 'verse')
            ->where('ct.montant_verse', 0)
            ->select('ct.id', 'ct.montant')
            ->get()
            ->each(fn ($row) => \Illuminate\Support\Facades\DB::table('cashback_transactions')
                ->where('id', $row->id)
                ->update(['montant_verse' => $row->montant]));

        $stale->refresh();
        $this->assertSame(100, $stale->montant_verse);
        $this->assertSame(0, $stale->montant_restant);
    }

    // ── Controller / autorisations ─────────────────────────────────────────────

    public function test_index_accessible_admin_entreprise(): void
    {
        $org  = $this->createOrgWithCashback();
        $user = $this->staffUser($org, 'admin_entreprise');

        $this->actingAs($user)->get('/cashback')->assertOk();
    }

    public function test_index_interdit_role_client(): void
    {
        $org = $this->createOrgWithCashback();
        Role::firstOrCreate(['name' => 'client', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('client');

        $this->actingAs($user)->get('/cashback')->assertForbidden();
    }

    public function test_index_filtre_par_statut(): void
    {
        $org     = $this->createOrgWithCashback();
        $user    = $this->staffUser($org);
        $client1 = Client::factory()->create(['organization_id' => $org->id]);
        $client2 = Client::factory()->create(['organization_id' => $org->id]);

        $this->makeTransaction($org, $client1, 10000, CashbackTransaction::STATUT_EN_ATTENTE);
        $this->makeTransaction($org, $client2, 20000, CashbackTransaction::STATUT_VALIDE);

        $this->actingAs($user)
            ->get('/cashback?statut=en_attente')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Cashback/Index')
                ->has('transactions', 1)
                ->where('transactions.0.montant', 10000)
                ->where('transactions.0.statut', 'en_attente')
            );
    }

    public function test_valider_via_controller(): void
    {
        $org    = $this->createOrgWithCashback();
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $user   = $this->staffUser($org, 'admin_entreprise');
        $t      = $this->makeTransaction($org, $client, 10000, CashbackTransaction::STATUT_EN_ATTENTE);

        $this->actingAs($user)
            ->patch("/cashback/{$t->id}/valider", ['note' => 'Vérifié'])
            ->assertRedirect();

        $t->refresh();
        $this->assertSame(CashbackTransaction::STATUT_VALIDE, $t->statut);
        $this->assertSame('Vérifié', $t->note);
    }

    public function test_verser_via_controller(): void
    {
        $org    = $this->createOrgWithCashback();
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $user   = $this->staffUser($org, 'admin_entreprise');
        // Transaction déjà validée (étape 1 faite)
        $t = $this->makeTransaction($org, $client, 10000, CashbackTransaction::STATUT_VALIDE);

        $this->actingAs($user)
            ->patch("/cashback/{$t->id}/verser", [
                'montant'          => 10000,
                'mode_paiement'    => 'especes',
                'date_versement'   => '2026-04-10',
                'note'             => 'Remis en main propre',
            ])
            ->assertRedirect();

        $t->refresh();
        $this->assertSame(CashbackTransaction::STATUT_VERSE, $t->statut);
        $this->assertSame(10000, $t->montant_verse);
    }

    public function test_verser_partiel_via_controller(): void
    {
        $org    = $this->createOrgWithCashback();
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $user   = $this->staffUser($org, 'admin_entreprise');
        $t      = $this->makeTransaction($org, $client, 10000, CashbackTransaction::STATUT_VALIDE);

        $this->actingAs($user)
            ->patch("/cashback/{$t->id}/verser", [
                'montant'        => 3000,
                'mode_paiement'  => 'mobile_money',
                'date_versement' => '2026-04-10',
            ])
            ->assertRedirect();

        $t->refresh();
        $this->assertSame(CashbackTransaction::STATUT_PARTIEL, $t->statut);
        $this->assertSame(3000, $t->montant_verse);
        $this->assertSame(7000, $t->montant_restant);
    }

    public function test_verser_sur_en_attente_retourne_422(): void
    {
        $org    = $this->createOrgWithCashback();
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $user   = $this->staffUser($org, 'admin_entreprise');
        $t      = $this->makeTransaction($org, $client, 10000, CashbackTransaction::STATUT_EN_ATTENTE);

        $this->actingAs($user)
            ->patch("/cashback/{$t->id}/verser", [
                'montant'        => 10000,
                'mode_paiement'  => 'especes',
                'date_versement' => '2026-04-10',
            ])
            ->assertStatus(422);
    }

    public function test_verser_montant_superieur_rejete_par_validation(): void
    {
        $org    = $this->createOrgWithCashback();
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $user   = $this->staffUser($org, 'admin_entreprise');
        $t      = $this->makeTransaction($org, $client, 10000, CashbackTransaction::STATUT_VALIDE);

        // Sur une route web, Laravel redirige avec les erreurs en session (302)
        $this->actingAs($user)
            ->patch("/cashback/{$t->id}/verser", [
                'montant'        => 99999,
                'mode_paiement'  => 'especes',
                'date_versement' => '2026-04-10',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('montant');

        // La transaction ne doit pas avoir été modifiée
        $t->refresh();
        $this->assertSame(CashbackTransaction::STATUT_VALIDE, $t->statut);
        $this->assertSame(0, $t->montant_verse);
    }
}
