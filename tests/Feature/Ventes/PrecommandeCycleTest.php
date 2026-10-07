<?php

namespace Tests\Feature\Ventes;

use App\Enums\ModeConfirmationAnnulationExceptionnelle;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutFactureVente;
use App\Enums\StatutReservationStock;
use App\Http\Resources\Api\Client\CommandeVenteMineResource;
use App\Mail\PrecommandeAnnulationCodeMail;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\CompteComptable;
use App\Models\EcritureComptable;
use App\Models\Organization;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\RemboursementVente;
use App\Models\Site;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\UserAuthIdentity;
use App\Models\VarianteStock;
use App\Services\AnnulationExceptionnelleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\TestCase;

/**
 * Cycle de vie d'une précommande (ADR 0019, lot 2) : préparation, retrait, activation de la facture
 * depuis les acomptes, imputation 419100 → 411000, trop-perçu et remboursement, acompte
 * complémentaire, annulation simple et renforcée. `.env.testing` fixe OTP_FIXED_CODE=123456.
 */
class PrecommandeCycleTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasProduitVariante, RefreshDatabase;

    private const PRIX = 20000;

    private Organization $org;

    private User $user;

    private Site $site;

    private Produit $produit;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->org = Organization::factory()->create();
        $this->user = $this->makeUserWithPermissions($this->org, [
            'ventes.read', 'ventes.precommander', 'ventes.preparer', 'ventes.valider_retrait',
            'ventes.rembourser', 'ventes.annuler_precommande', 'ventes.annuler_precommande_preparee',
            'factures.encaisser',
        ]);
        $this->user->authIdentities()->create([
            'type' => UserAuthIdentity::TYPE_EMAIL,
            'value' => 'agent@example.com',
            'normalized_value' => 'agent@example.com',
            'verified_at' => now(),
            'is_primary' => false,
        ]);

        $this->site = Site::create(['organization_id' => $this->org->id, 'nom' => 'Matoto', 'type' => 'depot', 'localisation' => 'Conakry']);
        $this->user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        $this->produit = $this->makeProduitAvecVariante($this->org, ['nom' => 'Pack 1500ml', 'type' => 'fabricable'], ['prix_vente' => self::PRIX, 'prix_usine' => 15000]);
        $this->client = Client::factory()->create(['organization_id' => $this->org->id, 'type' => 'revendeur']);

        VarianteStock::updateOrCreate(
            ['produit_variante_id' => $this->varianteId(), 'site_id' => $this->site->id],
            ['organization_id' => $this->org->id, 'qte_stock' => 100],
        );
        $this->creerCaisseActivePour($this->user, $this->site->id);
        Parametre::setPrecommandeAcompte($this->org->id, false, 0);
        Parametre::setModeConfirmationAnnulationExceptionnelle($this->org->id, ModeConfirmationAnnulationExceptionnelle::SIMPLE);
    }

    private function varianteId(): string
    {
        return $this->produit->variantePrincipale()->first()->id;
    }

    private function precommande(int $qte = 10, float $acompte = 0): CommandeVente
    {
        $this->actingAs($this->user)->post('/backoffice/precommandes', [
            'client_id' => $this->client->id,
            'mode_remise' => 'retrait',
            'date_remise_prevue' => today()->addDay()->toDateString(),
            'lignes' => [['produit_id' => $this->produit->id, 'qte' => $qte, 'prix_vente' => self::PRIX]],
            'acompte_montant' => $acompte,
            'mode_paiement' => $acompte > 0 ? 'especes' : null,
        ])->assertSessionHasNoErrors();

        return CommandeVente::where('est_precommande', true)->latest('created_at')->firstOrFail();
    }

    private function prix(CommandeVente $commande): float
    {
        return (float) $commande->lignes()->first()->prix_vente_snapshot;
    }

    /** @return array<int, array{id: string, quantite: int}> */
    private function lignes(CommandeVente $commande, int $quantite): array
    {
        return $commande->lignes->map(fn ($l) => ['id' => $l->id, 'quantite' => $quantite])->all();
    }

    private function preparer(CommandeVente $commande, int $quantite): void
    {
        $this->post("/backoffice/ventes/{$commande->id}/precommande/preparation/lancer")->assertSessionHasNoErrors();
        $this->post("/backoffice/ventes/{$commande->id}/precommande/preparation/valider", ['lignes' => $this->lignes($commande, $quantite)])->assertSessionHasNoErrors();
    }

    private function retirer(CommandeVente $commande, int $quantite): void
    {
        $this->post("/backoffice/ventes/{$commande->id}/precommande/retrait", ['lignes' => $this->lignes($commande, $quantite)])->assertSessionHasNoErrors();
    }

    private function soldeCompte(string $numero): float
    {
        $compte = CompteComptable::where('organization_id', $this->org->id)->where('numero', $numero)->firstOrFail();
        $e = EcritureComptable::where('compte_comptable_id', $compte->id);

        return round((float) (clone $e)->sum('debit') - (float) (clone $e)->sum('credit'), 2);
    }

    private function stock(): VarianteStock
    {
        return VarianteStock::where('produit_variante_id', $this->varianteId())->where('site_id', $this->site->id)->firstOrFail();
    }

    // ── Préparation ───────────────────────────────────────────────────────────

    public function test_preparation_partielle_libere_le_surplus_et_recalcule_le_montant(): void
    {
        $commande = $this->precommande(10);
        $prix = $this->prix($commande);

        $this->preparer($commande, 7);

        $commande->refresh();
        $this->assertSame(StatutCommandeVente::PREPAREE, $commande->statut);
        $this->assertNotNull($commande->preparee_at);
        $this->assertSame(7, $commande->lignes()->first()->quantite_preparee);
        $this->assertSame(7 * $prix, (float) $commande->total_commande);
        $this->assertSame(7, (int) $this->stock()->qte_reservee);
        $this->assertSame(100, (int) $this->stock()->qte_stock);
        $this->assertSame(7, (int) StockReservation::where('statut', StatutReservationStock::ACTIVE)->sum('quantite'));
    }

    public function test_preparation_au_dela_de_la_demande_ou_a_zero_est_refusee(): void
    {
        $commande = $this->precommande(10);
        $this->actingAs($this->user)->post("/backoffice/ventes/{$commande->id}/precommande/preparation/lancer");

        $this->post("/backoffice/ventes/{$commande->id}/precommande/preparation/valider", ['lignes' => $this->lignes($commande, 11)])
            ->assertSessionHasErrors('lignes');
        $this->post("/backoffice/ventes/{$commande->id}/precommande/preparation/valider", ['lignes' => $this->lignes($commande, 0)])
            ->assertSessionHasErrors('lignes');

        $this->assertSame(StatutCommandeVente::A_PREPARER, $commande->fresh()->statut);
        $this->assertSame(10, (int) $this->stock()->qte_reservee);
    }

    // ── Retrait et activation de la facture ───────────────────────────────────

    public function test_retrait_avec_acompte_partiel_sort_le_stock_impute_l_acompte_et_laisse_un_reste(): void
    {
        $commande = $this->precommande(10, 50000);
        $total = 10 * $this->prix($commande);
        $this->preparer($commande, 10);

        $this->retirer($commande, 10);

        $commande->refresh();
        $facture = $commande->facture;
        $this->assertSame(StatutCommandeVente::FACTURATION, $commande->statut);
        $this->assertNotNull($commande->remise_at);
        $this->assertSame(StatutFactureVente::PARTIEL, $facture->statut_facture);
        $this->assertSame($total - 50000, $facture->montant_restant);
        $this->assertSame(90, (int) $this->stock()->qte_stock);
        $this->assertSame(0, (int) $this->stock()->qte_reservee);
        // Acompte imputé : 419100 soldé, créance client = reste à payer.
        $this->assertSame(0.0, $this->soldeCompte('419100'));
        $this->assertSame($total - 50000, $this->soldeCompte('411000'));
    }

    /** En-tête et frise : même état à chaque étape ; le statut financier reste à part. */
    private function assertAffichage(CommandeVente $commande, string $valeur, string $libelle, bool $stockReserve): void
    {
        $this->get("/backoffice/ventes/{$commande->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('commande.statut_affichage.value', $valeur)
                ->where('commande.statut_affichage.label', $libelle)
                ->where('precommande.etape_courante', $valeur)
                ->where('precommande.stock_reserve', $stockReserve));
    }

    public function test_retrait_l_entete_et_la_frise_suivent_le_meme_parcours_jusqu_a_retiree(): void
    {
        $commande = $this->precommande(10, 50000);

        $this->get("/backoffice/ventes/{$commande->id}")->assertInertia(fn (Assert $page) => $page
            ->where('precommande.etapes', [
                ['cle' => 'reservee', 'libelle' => 'Créée'],
                ['cle' => 'a_preparer', 'libelle' => 'Préparation en cours'],
                ['cle' => 'preparee', 'libelle' => 'Prête au retrait'],
                ['cle' => 'retiree', 'libelle' => 'Retirée'],
                ['cle' => 'cloturee', 'libelle' => 'Clôturée'],
            ]));
        $this->assertAffichage($commande, 'reservee', 'Créée', true);

        $this->post("/backoffice/ventes/{$commande->id}/precommande/preparation/lancer")->assertSessionHasNoErrors();
        $this->assertAffichage($commande, 'a_preparer', 'Préparation en cours', true);

        $this->post("/backoffice/ventes/{$commande->id}/precommande/preparation/valider", ['lignes' => $this->lignes($commande, 10)])->assertSessionHasNoErrors();
        $this->assertAffichage($commande, 'preparee', 'Prête au retrait', true);

        $this->retirer($commande, 10);

        // Statut `facturation` en base, « Retirée » à l'écran et dans l'API : jamais « À encaisser ».
        $commande->refresh();
        $this->assertSame(StatutCommandeVente::FACTURATION, $commande->statut);
        $this->assertAffichage($commande, 'retiree', 'Retirée', false);
        $this->get("/backoffice/ventes/{$commande->id}")->assertInertia(fn (Assert $page) => $page
            ->where('precommande.facture_statut_label', StatutFactureVente::PARTIEL->label()));
        $this->assertSame('Retirée', (new CommandeVenteMineResource($commande))->resolve()['statut_label']);
    }

    public function test_retrait_deja_solde_par_les_acomptes_paye_et_cloture_la_commande(): void
    {
        $commande = $this->precommande(10);
        $total = 10 * $this->prix($commande);
        $this->post("/backoffice/factures/{$commande->facture->id}/encaissements", ['montant' => $total, 'mode_paiement' => 'especes'])
            ->assertSessionHasNoErrors();
        $this->preparer($commande, 10);

        $this->retirer($commande, 10);

        $commande->refresh();
        $this->assertSame(StatutFactureVente::PAYEE, $commande->facture->statut_facture);
        $this->assertSame(StatutCommandeVente::CLOTUREE, $commande->statut);
        $this->assertSame(0.0, $this->soldeCompte('411000'));
    }

    public function test_retrait_superieur_a_la_preparation_est_refuse(): void
    {
        $commande = $this->precommande(10);
        $this->preparer($commande, 6);

        $this->post("/backoffice/ventes/{$commande->id}/precommande/retrait", ['lignes' => $this->lignes($commande, 7)])
            ->assertSessionHasErrors('lignes');

        $this->assertSame(StatutCommandeVente::PREPAREE, $commande->fresh()->statut);
        $this->assertSame(100, (int) $this->stock()->qte_stock);
    }

    // ── Acompte complémentaire ────────────────────────────────────────────────

    public function test_acompte_complementaire_avant_remise_reste_une_avance_client(): void
    {
        $commande = $this->precommande(10, 30000);

        $this->post("/backoffice/factures/{$commande->facture->id}/encaissements", ['montant' => 20000, 'mode_paiement' => 'especes'])
            ->assertSessionHasNoErrors();

        $facture = $commande->facture->fresh('encaissements');
        $this->assertTrue($facture->encaissements->every(fn ($e) => $e->est_acompte));
        $this->assertSame(StatutFactureVente::CREEE, $facture->statut_facture);
        $this->assertSame(-50000.0, $this->soldeCompte('419100'));
        // Journal : l'acompte complémentaire est tracé comme celui de la création.
        $this->assertSame([30000, 20000], $commande->activites()->where('action', 'acompte_recu')->reorder()->oldest()->orderBy('id')->get()
            ->map(fn ($a) => (int) $a->details['montant'])->all());
    }

    // ── Trop-perçu et remboursement ───────────────────────────────────────────

    public function test_trop_percu_bloque_la_cloture_jusqu_au_remboursement(): void
    {
        $commande = $this->precommande(10);
        $prix = $this->prix($commande);
        $this->post("/backoffice/factures/{$commande->facture->id}/encaissements", ['montant' => 10 * $prix, 'mode_paiement' => 'especes'])
            ->assertSessionHasNoErrors();
        $this->preparer($commande, 6);
        $this->retirer($commande, 6);

        $commande->refresh();
        $this->assertSame(4 * $prix, $commande->facture->tropPercu());
        $this->assertSame(StatutCommandeVente::FACTURATION, $commande->statut);

        $this->post("/backoffice/ventes/{$commande->id}/precommande/remboursement", ['montant' => 4 * $prix + 1, 'mode_paiement' => 'especes'])
            ->assertSessionHasErrors('montant');
        $this->post("/backoffice/ventes/{$commande->id}/precommande/remboursement", ['montant' => 4 * $prix, 'mode_paiement' => 'especes'])
            ->assertSessionHasNoErrors();

        $commande->refresh();
        $this->assertSame(0.0, $commande->facture->tropPercu());
        $this->assertSame(StatutCommandeVente::CLOTUREE, $commande->statut);
        $this->assertSame(1, RemboursementVente::where('motif', RemboursementVente::MOTIF_TROP_PERCU)->count());
        $this->assertSame(0.0, $this->soldeCompte('411000'));
    }

    // ── Annulation ────────────────────────────────────────────────────────────

    public function test_annulation_avant_preparation_rembourse_libere_le_stock_et_annule_la_facture(): void
    {
        $commande = $this->precommande(10, 40000);

        $this->post("/backoffice/ventes/{$commande->id}/precommande/annulation", [
            'motif' => 'Le client renonce à sa commande',
            'montant' => 40000,
            'mode_paiement' => 'especes',
        ])->assertSessionHasNoErrors();

        $commande->refresh();
        $this->assertSame(StatutCommandeVente::ANNULEE, $commande->statut);
        $this->assertSame(StatutFactureVente::ANNULEE, $commande->facture->statut_facture);
        $this->assertSame(0, (int) $this->stock()->qte_reservee);
        $this->assertSame(1, RemboursementVente::where('motif', RemboursementVente::MOTIF_ANNULATION)->count());
        $this->assertSame(0.0, $this->soldeCompte('419100'));
    }

    public function test_annulation_refuse_un_montant_de_remboursement_different(): void
    {
        $commande = $this->precommande(10, 40000);

        $this->post("/backoffice/ventes/{$commande->id}/precommande/annulation", [
            'motif' => 'Le client renonce à sa commande',
            'montant' => 30000,
            'mode_paiement' => 'especes',
        ])->assertSessionHasErrors('montant');

        $this->assertSame(StatutCommandeVente::RESERVEE, $commande->fresh()->statut);
        $this->assertSame(0, RemboursementVente::count());
    }

    public function test_annulation_apres_lancement_exige_la_permission_renforcee(): void
    {
        $commande = $this->precommande(10);
        $this->post("/backoffice/ventes/{$commande->id}/precommande/preparation/lancer");

        $simple = $this->makeUserWithPermissions($this->org, ['ventes.read', 'ventes.annuler_precommande']);
        $simple->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($simple)
            ->post("/backoffice/ventes/{$commande->id}/precommande/annulation", ['motif' => 'Le client ne viendra jamais'])
            ->assertForbidden();

        $this->assertSame(StatutCommandeVente::A_PREPARER, $commande->fresh()->statut);
    }

    public function test_annulation_renforcee_avec_code_email(): void
    {
        Parametre::setModeConfirmationAnnulationExceptionnelle($this->org->id, ModeConfirmationAnnulationExceptionnelle::EMAIL_CODE);
        $commande = $this->precommande(10);
        $this->post("/backoffice/ventes/{$commande->id}/precommande/preparation/lancer");

        $this->post("/backoffice/ventes/{$commande->id}/precommande/annulation", ['motif' => 'Le client ne viendra jamais', 'code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->postJson("/backoffice/ventes/{$commande->id}/precommande/annulation/code")->assertOk();
        Mail::assertSent(PrecommandeAnnulationCodeMail::class);

        $this->post("/backoffice/ventes/{$commande->id}/precommande/annulation", ['motif' => 'Le client ne viendra jamais', 'code' => '123456'])
            ->assertSessionHasNoErrors();
        $this->assertSame(StatutCommandeVente::ANNULEE, $commande->fresh()->statut);
    }

    public function test_annulation_exceptionnelle_refusee_pour_une_precommande(): void
    {
        $commande = $this->precommande(10, 40000);

        $this->assertNotNull(AnnulationExceptionnelleService::raisonStatutNonEligible($commande->fresh()));
    }

    // ── Autorisations et isolation ────────────────────────────────────────────

    public function test_actions_refusees_sans_permission_ou_hors_organisation(): void
    {
        $commande = $this->precommande(10);

        $sansDroit = $this->makeUserWithPermissions($this->org, ['ventes.read']);
        $sansDroit->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);
        $this->actingAs($sansDroit)->post("/backoffice/ventes/{$commande->id}/precommande/preparation/lancer")->assertForbidden();

        $autreOrg = Organization::factory()->create();
        $etranger = $this->makeUserWithPermissions($autreOrg, ['ventes.read', 'ventes.preparer']);
        $this->attachDefaultSite($autreOrg, $etranger);
        $this->actingAs($etranger)->post("/backoffice/ventes/{$commande->id}/precommande/preparation/lancer")->assertForbidden();

        $this->assertSame(StatutCommandeVente::RESERVEE, $commande->fresh()->statut);
    }
}
