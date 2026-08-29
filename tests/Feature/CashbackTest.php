<?php

namespace Tests\Feature;

use App\Features\ModuleFeature;
use App\Models\CashbackSolde;
use App\Models\CashbackTransaction;
use App\Models\CashbackVersement;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\Organization;
use App\Models\ProduitVariante;
use App\Models\Site;
use App\Models\User;
use App\Services\CashbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Role;
use Tests\Concerns\HasProduitVariante;
use Tests\TestCase;

class CashbackTest extends TestCase
{
    use HasProduitVariante, RefreshDatabase;

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function createOrgAvecCashbackActif(): Organization
    {
        $org = Organization::factory()->create();
        Feature::for($org)->activate(ModuleFeature::CASHBACK);

        return $org;
    }

    /** Variante d'un produit fabricable — seul type ouvrant droit au cashback (cf. CashbackService::quantiteEligible()). */
    private function makeFabricable(Organization $org, array $varianteOverrides = []): ProduitVariante
    {
        $produit = $this->makeProduitAvecVariante(
            $org,
            ['nom' => 'Pack Test', 'type' => 'fabricable'],
            array_merge([
                'prix_vente' => 20000,
                'prix_usine' => 15000,
                'prix_usine_tricycle' => 15000,
                'prix_externe' => 15000,
                'prix_revendeur' => 16000,
                'prix_distributeur' => 15500,
            ], $varianteOverrides),
        );

        return $produit->variantePrincipale()->first();
    }

    /**
     * Vente avec des lignes explicites (jamais juste total_commande) — nécessaire depuis que
     * CashbackService calcule la quantité éligible à partir des lignes elles-mêmes, plus du
     * seul montant de la commande.
     *
     * @param  array<int, array<string, mixed>>  $lignes
     */
    private function makeVenteAvecLignes(Organization $org, Client $client, array $lignes, ?int $totalCommande = null): CommandeVente
    {
        $vente = CommandeVente::withoutEvents(fn () => CommandeVente::factory()->create([
            'organization_id' => $org->id,
            'client_id' => $client->id,
            'total_commande' => $totalCommande ?? array_sum(array_column($lignes, 'total_ligne')),
        ]));

        foreach ($lignes as $ligne) {
            $vente->lignes()->create($ligne);
        }

        return $vente->fresh();
    }

    /** @return array<string, mixed> */
    private function ligne(ProduitVariante $variante, int $qteDemandee, ?int $qteLivree = null, int $prixVente = 20000): array
    {
        return [
            'variante_id' => $variante->id,
            'quantite_demandee' => $qteDemandee,
            'quantite_livree' => $qteLivree,
            'prix_usine_snapshot' => 0,
            'prix_vente_snapshot' => $prixVente,
            'total_ligne' => $qteDemandee * $prixVente,
        ];
    }

    private function makeVenteSilently(Organization $org, Client $client, int $montant): CommandeVente
    {
        return CommandeVente::withoutEvents(fn () => CommandeVente::factory()->create([
            'organization_id' => $org->id,
            'client_id' => $client->id,
            'total_commande' => $montant,
        ]));
    }

    private function staffUser(Organization $org, string $role = 'admin_entreprise'): User
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole($role);

        $site = Site::create([
            'organization_id' => $org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    /** Crée une transaction en attente avec un solde associé (firstOrCreate pour éviter les doublons). */
    private function makeTransaction(Organization $org, Client $client, int $montant, string $statut = CashbackTransaction::STATUT_EN_ATTENTE): CashbackTransaction
    {
        $t = CashbackTransaction::create([
            'organization_id' => $org->id,
            'client_id' => $client->id,
            'type' => CashbackTransaction::TYPE_GAIN,
            'montant' => $montant,
            'montant_verse' => 0,
            'statut' => $statut,
        ]);

        CashbackSolde::firstOrCreate(
            ['organization_id' => $org->id, 'client_id' => $client->id],
            ['cumul_achats' => 0, 'cashback_en_attente' => 0, 'total_cashback_gagne' => 0, 'total_cashback_verse' => 0],
        )->increment('cashback_en_attente', $montant);

        return $t;
    }

    // ── processVente : cashback = commission propre au client, jamais un seuil global ───────
    // (décision produit du 28/08/2026, EN REMPLACEMENT du modèle seuil d'achat/gain fixe —
    // cf. docs/cashback.md, CASHBACK-002/003).

    public function test_process_vente_incremente_cumul_achats_de_facon_purement_informative(): void
    {
        $org = $this->createOrgAvecCashbackActif();
        $client = Client::factory()->create(['organization_id' => $org->id, 'cashback_eligible' => true, 'cashback_montant_par_pack' => 300]);
        $variante = $this->makeFabricable($org);

        $vente = $this->makeVenteAvecLignes($org, $client, [$this->ligne($variante, 10)]);
        (new CashbackService)->processVente($vente);

        $solde = CashbackSolde::where('client_id', $client->id)->first();
        $this->assertNotNull($solde);
        // cumul_achats reflète le total_commande, mais ne déclenche plus rien ni ne se
        // réinitialise jamais (contrairement à l'ancien modèle à seuil).
        $this->assertSame((int) $vente->total_commande, $solde->cumul_achats);
    }

    public function test_gain_genere_selon_le_montant_par_pack_propre_au_client(): void
    {
        $org = $this->createOrgAvecCashbackActif();
        $client = Client::factory()->create(['organization_id' => $org->id, 'cashback_eligible' => true, 'cashback_montant_par_pack' => 300]);
        $variante = $this->makeFabricable($org);

        // 20 packs éligibles × 300 GNF/pack = 6 000 GNF (cf. exemple du chantier).
        $vente = $this->makeVenteAvecLignes($org, $client, [$this->ligne($variante, 20)]);
        (new CashbackService)->processVente($vente);

        $this->assertDatabaseHas('cashback_transactions', [
            'organization_id' => $org->id,
            'client_id' => $client->id,
            'type' => CashbackTransaction::TYPE_GAIN,
            'montant' => 6000,
            'montant_unitaire_snapshot' => 300,
            'quantite_eligible_snapshot' => 20,
            'statut' => CashbackTransaction::STATUT_EN_ATTENTE,
            'vente_id' => $vente->id,
        ]);

        $solde = CashbackSolde::where('client_id', $client->id)->first();
        $this->assertSame(6000, $solde->cashback_en_attente);
        $this->assertSame(6000, $solde->total_cashback_gagne);
    }

    /** Deux clients avec des montants par pack différents obtiennent des gains différents pour la même quantité. */
    public function test_deux_clients_ont_des_montants_par_pack_independants(): void
    {
        $org = $this->createOrgAvecCashbackActif();
        $variante = $this->makeFabricable($org);
        $clientA = Client::factory()->create(['organization_id' => $org->id, 'cashback_eligible' => true, 'cashback_montant_par_pack' => 300]);
        $clientB = Client::factory()->create(['organization_id' => $org->id, 'cashback_eligible' => true, 'cashback_montant_par_pack' => 500]);
        $service = new CashbackService;

        $service->processVente($this->makeVenteAvecLignes($org, $clientA, [$this->ligne($variante, 10)]));
        $service->processVente($this->makeVenteAvecLignes($org, $clientB, [$this->ligne($variante, 10)]));

        $this->assertSame(3000, (int) CashbackSolde::where('client_id', $clientA->id)->value('total_cashback_gagne'));
        $this->assertSame(5000, (int) CashbackSolde::where('client_id', $clientB->id)->value('total_cashback_gagne'));
    }

    public function test_pas_de_doublon_gain_pour_meme_vente(): void
    {
        $org = $this->createOrgAvecCashbackActif();
        $client = Client::factory()->create(['organization_id' => $org->id, 'cashback_eligible' => true, 'cashback_montant_par_pack' => 300]);
        $variante = $this->makeFabricable($org);
        $service = new CashbackService;

        $vente = $this->makeVenteAvecLignes($org, $client, [$this->ligne($variante, 10)]);
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
        $org = $this->createOrgAvecCashbackActif();
        $vente = $this->makeVenteSilently($org, Client::factory()->create(['organization_id' => $org->id]), 200000);
        $vente->client_id = null;

        (new CashbackService)->processVente($vente);

        $this->assertDatabaseCount('cashback_soldes', 0);
    }

    public function test_client_non_eligible_ne_recoit_pas_cashback(): void
    {
        $org = $this->createOrgAvecCashbackActif();
        $client = Client::factory()->create(['organization_id' => $org->id, 'cashback_eligible' => false, 'cashback_montant_par_pack' => 300]);
        $variante = $this->makeFabricable($org);

        $vente = $this->makeVenteAvecLignes($org, $client, [$this->ligne($variante, 10)]);
        (new CashbackService)->processVente($vente);

        $this->assertDatabaseCount('cashback_soldes', 0);
        $this->assertDatabaseCount('cashback_transactions', 0);
    }

    /** CASHBACK-002 : un client éligible sans montant configuré ne génère jamais de cashback (jamais un montant implicite). */
    public function test_client_eligible_sans_montant_configure_ne_genere_aucun_cashback(): void
    {
        $org = $this->createOrgAvecCashbackActif();
        $client = Client::factory()->create(['organization_id' => $org->id, 'cashback_eligible' => true, 'cashback_montant_par_pack' => null]);
        $variante = $this->makeFabricable($org);

        $vente = $this->makeVenteAvecLignes($org, $client, [$this->ligne($variante, 10)]);
        (new CashbackService)->processVente($vente);

        $this->assertDatabaseCount('cashback_transactions', 0);
    }

    /** Un produit non fabricable (matériel, service…) n'est jamais un "pack" éligible au cashback. */
    public function test_produit_non_fabricable_nest_jamais_eligible_au_cashback(): void
    {
        $org = $this->createOrgAvecCashbackActif();
        $client = Client::factory()->create(['organization_id' => $org->id, 'cashback_eligible' => true, 'cashback_montant_par_pack' => 300]);
        $produitMateriel = $this->makeProduitAvecVariante($org, ['nom' => 'Rouleau', 'type' => 'materiel'], ['prix_vente' => 500, 'prix_achat' => 300]);
        $varianteMateriel = $produitMateriel->variantePrincipale()->first();

        $vente = $this->makeVenteAvecLignes($org, $client, [$this->ligne($varianteMateriel, 10, prixVente: 500)]);
        (new CashbackService)->processVente($vente);

        $this->assertDatabaseCount('cashback_transactions', 0);
    }

    /** Vente multi-lignes : seules les lignes fabricables comptent, la quantité livrée prime sur la demandée quand elle existe. */
    public function test_vente_multi_lignes_calcule_la_quantite_et_le_montant_corrects(): void
    {
        $org = $this->createOrgAvecCashbackActif();
        $client = Client::factory()->create(['organization_id' => $org->id, 'cashback_eligible' => true, 'cashback_montant_par_pack' => 300]);
        $varianteFabricable = $this->makeFabricable($org);
        $produitMateriel = $this->makeProduitAvecVariante($org, ['nom' => 'Rouleau', 'type' => 'materiel'], ['prix_vente' => 500, 'prix_achat' => 300]);
        $varianteMateriel = $produitMateriel->variantePrincipale()->first();

        $vente = $this->makeVenteAvecLignes($org, $client, [
            // 15 demandés mais seulement 12 réellement livrés (véhicule) : 12 comptent.
            $this->ligne($varianteFabricable, 15, qteLivree: 12),
            // Ligne matériel : jamais comptée, quelle que soit sa quantité.
            $this->ligne($varianteMateriel, 100, prixVente: 500),
        ]);
        (new CashbackService)->processVente($vente);

        $this->assertDatabaseHas('cashback_transactions', [
            'vente_id' => $vente->id,
            'montant' => 3600, // 12 × 300
            'quantite_eligible_snapshot' => 12,
        ]);
    }

    /** CASHBACK-004 : une modification du montant n'est jamais rétroactive. */
    public function test_modification_du_montant_ne_recalcule_jamais_les_gains_deja_generes(): void
    {
        $org = $this->createOrgAvecCashbackActif();
        $client = Client::factory()->create(['organization_id' => $org->id, 'cashback_eligible' => true, 'cashback_montant_par_pack' => 300]);
        $variante = $this->makeFabricable($org);
        $service = new CashbackService;

        $venteA = $this->makeVenteAvecLignes($org, $client, [$this->ligne($variante, 10)]);
        $service->processVente($venteA);

        $client->update(['cashback_montant_par_pack' => 500]);

        $venteB = $this->makeVenteAvecLignes($org, $client, [$this->ligne($variante, 10)]);
        $service->processVente($venteB);

        $this->assertSame(300, (int) CashbackTransaction::where('vente_id', $venteA->id)->value('montant_unitaire_snapshot'));
        $this->assertSame(3000, (int) CashbackTransaction::where('vente_id', $venteA->id)->value('montant'));
        $this->assertSame(500, (int) CashbackTransaction::where('vente_id', $venteB->id)->value('montant_unitaire_snapshot'));
        $this->assertSame(5000, (int) CashbackTransaction::where('vente_id', $venteB->id)->value('montant'));

        $solde = CashbackSolde::where('client_id', $client->id)->first();
        $this->assertSame(8000, $solde->total_cashback_gagne);
    }

    /** CASHBACK-005/006 : la désactivation bloque les nouvelles générations mais ne touche jamais à l'historique. */
    public function test_desactivation_conserve_lhistorique_et_bloque_les_nouvelles_generations(): void
    {
        $org = $this->createOrgAvecCashbackActif();
        $client = Client::factory()->create(['organization_id' => $org->id, 'cashback_eligible' => true, 'cashback_montant_par_pack' => 300]);
        $variante = $this->makeFabricable($org);
        $service = new CashbackService;

        $service->processVente($this->makeVenteAvecLignes($org, $client, [$this->ligne($variante, 10)]));
        $soldeAvant = CashbackSolde::where('client_id', $client->id)->first();
        $this->assertSame(3000, $soldeAvant->total_cashback_gagne);

        $client->update(['cashback_eligible' => false]);

        $service->processVente($this->makeVenteAvecLignes($org, $client, [$this->ligne($variante, 10)]));

        $this->assertSame(1, CashbackTransaction::where('client_id', $client->id)->count());
        $soldeApres = CashbackSolde::where('client_id', $client->id)->first();
        $this->assertSame(3000, $soldeApres->total_cashback_gagne, 'le total historique ne doit ni être supprimé, ni annulé, ni recalculé');
        $this->assertSame(3000, $soldeApres->cashback_en_attente);
    }

    /** Isolation multi-organisation : le cashback d'un client ne fuite jamais vers une autre organisation. */
    public function test_isolation_organization_id(): void
    {
        $orgA = $this->createOrgAvecCashbackActif();
        $orgB = $this->createOrgAvecCashbackActif();
        $clientA = Client::factory()->create(['organization_id' => $orgA->id, 'cashback_eligible' => true, 'cashback_montant_par_pack' => 300]);
        $clientB = Client::factory()->create(['organization_id' => $orgB->id, 'cashback_eligible' => true, 'cashback_montant_par_pack' => 500]);
        $varianteA = $this->makeFabricable($orgA);
        $varianteB = $this->makeFabricable($orgB);
        $service = new CashbackService;

        $service->processVente($this->makeVenteAvecLignes($orgA, $clientA, [$this->ligne($varianteA, 10)]));
        $service->processVente($this->makeVenteAvecLignes($orgB, $clientB, [$this->ligne($varianteB, 10)]));

        $this->assertSame(1, CashbackTransaction::where('organization_id', $orgA->id)->count());
        $this->assertSame(1, CashbackTransaction::where('organization_id', $orgB->id)->count());
        $this->assertSame(3000, (int) CashbackSolde::where('organization_id', $orgA->id)->value('total_cashback_gagne'));
        $this->assertSame(5000, (int) CashbackSolde::where('organization_id', $orgB->id)->value('total_cashback_gagne'));
    }

    // ── valider ────────────────────────────────────────────────────────────────

    public function test_valider_passe_statut_en_valide(): void
    {
        $org = $this->createOrgAvecCashbackActif();
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $staff = $this->staffUser($org);

        $t = $this->makeTransaction($org, $client, 10000);

        (new CashbackService)->valider($t, $staff, 'OK');

        $t->refresh();
        $this->assertSame(CashbackTransaction::STATUT_VALIDE, $t->statut);
        $this->assertSame($staff->id, $t->valide_par);
        $this->assertSame('OK', $t->note);
        $this->assertNotNull($t->valide_le);
    }

    public function test_valider_deja_valide_leve_exception(): void
    {
        $org = $this->createOrgAvecCashbackActif();
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $staff = $this->staffUser($org);
        $t = $this->makeTransaction($org, $client, 10000, CashbackTransaction::STATUT_VALIDE);

        $this->expectException(\InvalidArgumentException::class);
        (new CashbackService)->valider($t, $staff);
    }

    // ── verser (versement total) ───────────────────────────────────────────────

    public function test_verser_total_passe_statut_verse(): void
    {
        $org = $this->createOrgAvecCashbackActif();
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $staff = $this->staffUser($org);
        $t = $this->makeTransaction($org, $client, 10000, CashbackTransaction::STATUT_VALIDE);

        (new CashbackService)->verser($t, $staff, 10000, 'especes', '2026-04-10', 'Remis en main propre');

        $t->refresh();
        $this->assertSame(CashbackTransaction::STATUT_VERSE, $t->statut);
        $this->assertSame(10000, $t->montant_verse);
        $this->assertSame(0, $t->montant_restant);
        $this->assertSame($staff->id, $t->verse_par);
        $this->assertNotNull($t->verse_le);

        $this->assertDatabaseHas('cashback_versements', [
            'cashback_transaction_id' => $t->id,
            'montant' => 10000,
            'mode_paiement' => 'especes',
        ]);

        $solde = CashbackSolde::where('client_id', $client->id)->first();
        $this->assertSame(0, $solde->cashback_en_attente);
        $this->assertSame(10000, $solde->total_cashback_verse);
    }

    public function test_verser_partiel_passe_statut_partiel(): void
    {
        $org = $this->createOrgAvecCashbackActif();
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $staff = $this->staffUser($org);
        $t = $this->makeTransaction($org, $client, 10000, CashbackTransaction::STATUT_VALIDE);

        (new CashbackService)->verser($t, $staff, 3000, 'mobile_money', '2026-04-10');

        $t->refresh();
        $this->assertSame(CashbackTransaction::STATUT_PARTIEL, $t->statut);
        $this->assertSame(3000, $t->montant_verse);
        $this->assertSame(7000, $t->montant_restant);
    }

    public function test_versement_partiel_puis_solde_complet(): void
    {
        $org = $this->createOrgAvecCashbackActif();
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $staff = $this->staffUser($org);
        $t = $this->makeTransaction($org, $client, 10000, CashbackTransaction::STATUT_VALIDE);

        $service = new CashbackService;
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
        $org = $this->createOrgAvecCashbackActif();
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $staff = $this->staffUser($org);
        $t = $this->makeTransaction($org, $client, 10000, CashbackTransaction::STATUT_VALIDE);

        $this->expectException(\InvalidArgumentException::class);
        (new CashbackService)->verser($t, $staff, 99999, 'especes', '2026-04-10');
    }

    public function test_verser_transaction_non_versable_leve_exception(): void
    {
        $org = $this->createOrgAvecCashbackActif();
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $staff = $this->staffUser($org);
        // en_attente → non versable (pas encore validée)
        $t = $this->makeTransaction($org, $client, 10000, CashbackTransaction::STATUT_EN_ATTENTE);

        $this->expectException(\InvalidArgumentException::class);
        (new CashbackService)->verser($t, $staff, 10000, 'especes', '2026-04-10');
    }

    public function test_verser_transaction_deja_verse_leve_exception(): void
    {
        $org = $this->createOrgAvecCashbackActif();
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $staff = $this->staffUser($org);
        $t = $this->makeTransaction($org, $client, 10000, CashbackTransaction::STATUT_VERSE);

        $this->expectException(\InvalidArgumentException::class);
        (new CashbackService)->verser($t, $staff, 10000, 'especes', '2026-04-10');
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
        $org = $this->createOrgAvecCashbackActif();
        $user = $this->staffUser($org);
        $client = Client::factory()->create(['organization_id' => $org->id]);

        // Simule un legacy : statut='verse' mais montant_verse=0, aucun versement
        CashbackTransaction::create([
            'organization_id' => $org->id,
            'client_id' => $client->id,
            'type' => CashbackTransaction::TYPE_GAIN,
            'montant' => 100,
            'montant_verse' => 0,   // donnée héritée incohérente
            'statut' => CashbackTransaction::STATUT_VERSE,
        ]);

        $this->actingAs($user)
            ->get('/backoffice/cashback')
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
        $org = $this->createOrgAvecCashbackActif();
        $client = Client::factory()->create(['organization_id' => $org->id]);

        $stale = CashbackTransaction::create([
            'organization_id' => $org->id,
            'client_id' => $client->id,
            'type' => CashbackTransaction::TYPE_GAIN,
            'montant' => 100,
            'montant_verse' => 0,
            'statut' => CashbackTransaction::STATUT_VERSE,
        ]);

        // Rejoue la logique de réparation
        DB::table('cashback_transactions as ct')
            ->leftJoin('cashback_versements as cv', 'cv.cashback_transaction_id', '=', 'ct.id')
            ->whereNull('cv.id')
            ->where('ct.statut', 'verse')
            ->where('ct.montant_verse', 0)
            ->select('ct.id', 'ct.montant')
            ->get()
            ->each(fn ($row) => DB::table('cashback_transactions')
                ->where('id', $row->id)
                ->update(['montant_verse' => $row->montant]));

        $stale->refresh();
        $this->assertSame(100, $stale->montant_verse);
        $this->assertSame(0, $stale->montant_restant);
    }

    // ── Controller / autorisations ─────────────────────────────────────────────

    public function test_index_accessible_admin_entreprise(): void
    {
        $org = $this->createOrgAvecCashbackActif();
        $user = $this->staffUser($org, 'admin_entreprise');

        $this->actingAs($user)->get('/backoffice/cashback')->assertOk();
    }

    public function test_index_interdit_role_client(): void
    {
        $org = $this->createOrgAvecCashbackActif();
        Role::firstOrCreate(['name' => 'client', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('client');

        $this->actingAs($user)->get('/backoffice/cashback')->assertForbidden();
    }

    public function test_index_filtre_par_statut(): void
    {
        $org = $this->createOrgAvecCashbackActif();
        $user = $this->staffUser($org);
        $client1 = Client::factory()->create(['organization_id' => $org->id]);
        $client2 = Client::factory()->create(['organization_id' => $org->id]);

        $this->makeTransaction($org, $client1, 10000, CashbackTransaction::STATUT_EN_ATTENTE);
        $this->makeTransaction($org, $client2, 20000, CashbackTransaction::STATUT_VALIDE);

        $this->actingAs($user)
            ->get('/backoffice/cashback?statut=en_attente')
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
        $org = $this->createOrgAvecCashbackActif();
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $user = $this->staffUser($org, 'admin_entreprise');
        $t = $this->makeTransaction($org, $client, 10000, CashbackTransaction::STATUT_EN_ATTENTE);

        $this->actingAs($user)
            ->patch("/backoffice/cashback/{$t->id}/valider", ['note' => 'Vérifié'])
            ->assertRedirect();

        $t->refresh();
        $this->assertSame(CashbackTransaction::STATUT_VALIDE, $t->statut);
        $this->assertSame('Vérifié', $t->note);
    }

    public function test_verser_via_controller(): void
    {
        $org = $this->createOrgAvecCashbackActif();
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $user = $this->staffUser($org, 'admin_entreprise');
        // Transaction déjà validée (étape 1 faite)
        $t = $this->makeTransaction($org, $client, 10000, CashbackTransaction::STATUT_VALIDE);

        $this->actingAs($user)
            ->patch("/backoffice/cashback/{$t->id}/verser", [
                'montant' => 10000,
                'mode_paiement' => 'especes',
                'date_versement' => '2026-04-10',
                'note' => 'Remis en main propre',
            ])
            ->assertRedirect();

        $t->refresh();
        $this->assertSame(CashbackTransaction::STATUT_VERSE, $t->statut);
        $this->assertSame(10000, $t->montant_verse);
    }

    public function test_verser_partiel_via_controller(): void
    {
        $org = $this->createOrgAvecCashbackActif();
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $user = $this->staffUser($org, 'admin_entreprise');
        $t = $this->makeTransaction($org, $client, 10000, CashbackTransaction::STATUT_VALIDE);

        $this->actingAs($user)
            ->patch("/backoffice/cashback/{$t->id}/verser", [
                'montant' => 3000,
                'mode_paiement' => 'mobile_money',
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
        $org = $this->createOrgAvecCashbackActif();
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $user = $this->staffUser($org, 'admin_entreprise');
        $t = $this->makeTransaction($org, $client, 10000, CashbackTransaction::STATUT_EN_ATTENTE);

        $this->actingAs($user)
            ->patch("/backoffice/cashback/{$t->id}/verser", [
                'montant' => 10000,
                'mode_paiement' => 'especes',
                'date_versement' => '2026-04-10',
            ])
            ->assertStatus(422);
    }

    public function test_verser_montant_superieur_rejete_par_validation(): void
    {
        $org = $this->createOrgAvecCashbackActif();
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $user = $this->staffUser($org, 'admin_entreprise');
        $t = $this->makeTransaction($org, $client, 10000, CashbackTransaction::STATUT_VALIDE);

        // Sur une route web, Laravel redirige avec les erreurs en session (302)
        $this->actingAs($user)
            ->patch("/backoffice/cashback/{$t->id}/verser", [
                'montant' => 99999,
                'mode_paiement' => 'especes',
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
