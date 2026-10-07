<?php

namespace Tests\Feature\Ventes;

use App\Enums\NatureOperation;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutFactureVente;
use App\Features\ModuleFeature;
use App\Models\CashbackTransaction;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\CompteComptable;
use App\Models\EcritureComptable;
use App\Models\Organization;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\Site;
use App\Models\User;
use App\Models\VarianteStock;
use App\Models\Vehicule;
use App\Services\CommandeVenteService;
use App\Services\Ventes\PrecommandeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\TestCase;

/**
 * Précommande en livraison (ADR 0019, lot 3) : le chargement ne vaut pas livraison (D13), retour et
 * écart de réception malgré les acomptes (C4, C5), trop-perçu remboursable — tout l'encaissé net
 * après un retour total — et cashback différé à la livraison définitive, sur la quantité effective
 * (D14).
 */
class PrecommandeLivraisonTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasProduitVariante, RefreshDatabase;

    private const PRIX = 20000;

    private const CASHBACK_PAR_PACK = 300;

    private Organization $org;

    private User $user;

    private Site $site;

    private Produit $produit;

    private Client $client;

    private Vehicule $vehicule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        Feature::for($this->org)->activate(ModuleFeature::CASHBACK);

        $this->user = $this->makeUserWithPermissions($this->org, [
            'ventes.read', 'ventes.preparer', 'ventes.rembourser', 'factures.encaisser',
            'ventes.valider_reception', 'ventes.enregistrer_retour',
        ]);
        $this->site = Site::create(['organization_id' => $this->org->id, 'nom' => 'Matoto', 'type' => 'depot', 'localisation' => 'Conakry']);
        $this->user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        $this->produit = $this->makeProduitAvecVariante($this->org, ['nom' => 'Pack 1500ml', 'type' => 'fabricable'], ['prix_vente' => self::PRIX, 'prix_usine' => 15000]);
        $this->client = Client::factory()->create([
            'organization_id' => $this->org->id,
            'type' => 'revendeur',
            'cashback_eligible' => true,
            'cashback_montant_par_pack' => self::CASHBACK_PAR_PACK,
        ]);
        $this->vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id, 'capacite_packs' => 100]);

        VarianteStock::updateOrCreate(
            ['produit_variante_id' => $this->varianteId(), 'site_id' => $this->site->id],
            ['organization_id' => $this->org->id, 'qte_stock' => 100],
        );
        $this->creerCaisseActivePour($this->user, $this->site->id);
        Parametre::setPrecommandeAcompte($this->org->id, false, 0);
    }

    private function varianteId(): string
    {
        return $this->produit->variantePrincipale()->first()->id;
    }

    /**
     * Précommande en livraison, acompte versé, préparée et chargée en totalité : statut
     * LIVRAISON_EN_COURS, facture activée. Commission hors sujet (aucun véhicule éligible).
     */
    private function precommandeChargee(int $qte = 10, float $acompte = 0, ?NatureOperation $nature = null): CommandeVente
    {
        $this->actingAs($this->user);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'client_id' => $this->client->id,
            'vehicule_id' => $this->vehicule->id,
            'est_precommande' => true,
            'date_remise_prevue' => today()->addDay(),
            'statut' => StatutCommandeVente::BROUILLON,
            'commission_eligible_snapshot' => false,
            'total_commande' => $qte * self::PRIX,
            ...($nature ? ['nature_operation' => $nature] : []),
        ]);
        $commande->lignes()->create([
            'variante_id' => $this->varianteId(),
            'quantite_demandee' => $qte,
            'prix_usine_snapshot' => 15000.0,
            'prix_vente_snapshot' => (float) self::PRIX,
            'total_ligne' => $qte * self::PRIX,
            'libelle_snapshot' => 'Pack 1500ml',
        ]);
        CommandeVenteService::enregistrerPrecommande($commande->fresh());

        if ($acompte > 0) {
            $this->post("/backoffice/factures/{$commande->fresh()->facture->id}/encaissements", ['montant' => $acompte, 'mode_paiement' => 'especes'])
                ->assertSessionHasNoErrors();
        }

        $service = app(PrecommandeService::class);
        $service->lancerPreparation($commande->fresh());
        $service->validerPreparation($commande->fresh(), $this->quantites($commande, $qte));

        CommandeVenteService::demarrerChargement($commande->fresh());
        CommandeVenteService::validerChargement($commande->fresh(), $commande->lignes->map(fn ($l) => [
            'id' => $l->id,
            'quantite_chargee' => $qte,
            'type_ecart' => 'conforme',
        ])->all());

        return $commande->fresh();
    }

    /** @return array<string, int> */
    private function quantites(CommandeVente $commande, int $quantite): array
    {
        return $commande->lignes()->pluck('id')->mapWithKeys(fn ($id) => [$id => $quantite])->all();
    }

    private function retourner(CommandeVente $commande, int $quantite)
    {
        return $this->post(route('ventes.retour.store', $commande), [
            'motif' => 'client_absent',
            'lignes' => [['id' => $commande->lignes()->first()->id, 'quantite' => $quantite]],
        ]);
    }

    private function confirmerLivraison(CommandeVente $commande)
    {
        return $this->post(route('precommandes.livraison.confirmer', $commande));
    }

    private function rembourser(CommandeVente $commande, float $montant)
    {
        return $this->post("/backoffice/ventes/{$commande->id}/precommande/remboursement", ['montant' => $montant, 'mode_paiement' => 'especes']);
    }

    private function cashback(CommandeVente $commande): ?CashbackTransaction
    {
        return CashbackTransaction::where('vente_id', $commande->id)->where('type', CashbackTransaction::TYPE_GAIN)->first();
    }

    private function soldeCompte(string $numero): float
    {
        $compte = CompteComptable::where('organization_id', $this->org->id)->where('numero', $numero)->firstOrFail();
        $e = EcritureComptable::where('compte_comptable_id', $compte->id);

        return round((float) (clone $e)->sum('debit') - (float) (clone $e)->sum('credit'), 2);
    }

    private function stock(): int
    {
        return (int) VarianteStock::where('produit_variante_id', $this->varianteId())->where('site_id', $this->site->id)->value('qte_stock');
    }

    // ── Frise de la fiche ─────────────────────────────────────────────────────

    public function test_la_frise_avance_avec_le_chargement_au_lieu_de_rester_sur_a_charger(): void
    {
        $commande = $this->precommandeChargee(10);
        $commande->forceFill(['statut' => StatutCommandeVente::CHARGEMENT_EN_COURS])->saveQuietly();

        $this->get("/backoffice/ventes/{$commande->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('commande.statut_affichage.label', 'Chargement en cours')
                ->where('precommande.etape_courante', 'chargement_en_cours')
                ->where('precommande.etapes.3', ['cle' => 'chargement_en_cours', 'libelle' => 'Chargement en cours'])
                ->where('precommande.etapes.6', ['cle' => 'cloturee', 'libelle' => 'Clôturée']));

        $commande->forceFill(['statut' => StatutCommandeVente::LIVRAISON_EN_COURS])->saveQuietly();
        $this->get("/backoffice/ventes/{$commande->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('commande.statut_affichage.label', 'Livraison en cours')
                ->where('precommande.etape_courante', 'livraison_en_cours'));
    }

    // ── Chargement ≠ livraison (D13) ──────────────────────────────────────────

    public function test_livraison_soldee_reste_en_livraison_apres_le_chargement_sans_cashback(): void
    {
        $commande = $this->precommandeChargee(10, 10 * self::PRIX);

        $this->assertSame(StatutCommandeVente::LIVRAISON_EN_COURS, $commande->statut);
        $this->assertSame(StatutFactureVente::PAYEE, $commande->facture->statut_facture);
        $this->assertNotNull($commande->remise_at);
        $this->assertNull($this->cashback($commande));
        $this->assertTrue($commande->isRetournable());
    }

    public function test_confirmer_la_livraison_passe_livree_declenche_le_cashback_et_cloture(): void
    {
        $commande = $this->precommandeChargee(10, 10 * self::PRIX);

        $this->confirmerLivraison($commande)->assertSessionHasNoErrors();

        $commande->refresh();
        $this->assertSame(StatutCommandeVente::CLOTUREE, $commande->statut);
        $this->assertNotNull($commande->livree_at);
        $this->assertSame(10 * self::CASHBACK_PAR_PACK, (int) $this->cashback($commande)->montant);
        $this->assertNotNull($commande->activites()->where('action', 'livree')->first());

        // Journal : la clôture suit la confirmation, attribuée à l'auteur de l'action qui l'a déclenchée.
        $dernieres = $commande->activites()->take(2)->get();
        $this->assertSame(['cloturee', 'livree'], $dernieres->pluck('action')->all());
        $this->assertSame($this->user->id, $dernieres->first()->user_id);
    }

    public function test_confirmer_la_livraison_d_une_precommande_non_soldee_laisse_un_reste_a_encaisser(): void
    {
        $commande = $this->precommandeChargee(10, 50000);

        $this->confirmerLivraison($commande)->assertSessionHasNoErrors();

        $commande->refresh();
        $this->assertSame(StatutCommandeVente::LIVREE, $commande->statut);
        $this->assertSame(StatutFactureVente::PARTIEL, $commande->facture->statut_facture);
        $this->assertSame(10 * self::PRIX - 50000.0, $commande->facture->montant_restant);
        $this->assertNull($this->cashback($commande));
    }

    public function test_confirmer_la_livraison_exige_la_permission_l_etat_et_la_meme_organisation(): void
    {
        $commande = $this->precommandeChargee(10, 10 * self::PRIX);

        $sansDroit = $this->makeUserWithPermissions($this->org, ['ventes.read']);
        $sansDroit->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);
        $this->actingAs($sansDroit)->post(route('precommandes.livraison.confirmer', $commande))->assertForbidden();

        $autreOrg = Organization::factory()->create();
        $etranger = $this->makeUserWithPermissions($autreOrg, ['ventes.read', 'ventes.valider_reception']);
        $this->attachDefaultSite($autreOrg, $etranger);
        $this->actingAs($etranger)->post(route('precommandes.livraison.confirmer', $commande))->assertForbidden();

        $this->assertSame(StatutCommandeVente::LIVRAISON_EN_COURS, $commande->fresh()->statut);

        $this->actingAs($this->user);
        $this->confirmerLivraison($commande)->assertSessionHasNoErrors();
        // Déjà livrée (et clôturée) : la confirmation ne se rejoue pas.
        $this->confirmerLivraison($commande)->assertStatus(422);
    }

    public function test_confirmer_la_livraison_refusee_pour_une_reception_explicite(): void
    {
        $commande = $this->precommandeChargee(10, 0, NatureOperation::DISTRIBUTION_CLIENT);

        $this->confirmerLivraison($commande)->assertStatus(422);
        $this->assertSame(StatutCommandeVente::LIVRAISON_EN_COURS, $commande->fresh()->statut);
    }

    // ── Retour malgré les acomptes (C4) ───────────────────────────────────────

    public function test_retour_partiel_d_une_livraison_soldee_cree_un_trop_percu_remboursable(): void
    {
        $commande = $this->precommandeChargee(10, 10 * self::PRIX);

        $this->retourner($commande, 3)->assertSessionHasNoErrors();

        $commande->refresh();
        $facture = $commande->facture;
        $this->assertSame(StatutCommandeVente::LIVRAISON_EN_COURS, $commande->statut);
        $this->assertSame((float) (7 * self::PRIX), (float) $facture->montant_net);
        $this->assertSame(StatutFactureVente::PAYEE, $facture->statut_facture);
        $this->assertSame((float) (3 * self::PRIX), $facture->tropPercu());
        $this->assertSame(93, $this->stock());

        $this->rembourser($commande, 3 * self::PRIX)->assertSessionHasNoErrors();
        $this->confirmerLivraison($commande)->assertSessionHasNoErrors();

        $commande->refresh();
        $this->assertSame(StatutCommandeVente::CLOTUREE, $commande->statut);
        $this->assertSame(0.0, $this->soldeCompte('411000'));
        $this->assertSame(0.0, $this->soldeCompte('419100'));
        // Cashback sur la quantité réellement livrée (7), jamais sur la quantité commandée.
        $this->assertSame(7 * self::CASHBACK_PAR_PACK, (int) $this->cashback($commande)->montant);
    }

    public function test_retour_partiel_recalcule_le_statut_d_une_facture_partiellement_payee(): void
    {
        $commande = $this->precommandeChargee(10, 150000);
        $this->assertSame(StatutFactureVente::PARTIEL, $commande->facture->statut_facture);

        $this->retourner($commande, 3)->assertSessionHasNoErrors();

        $facture = $commande->fresh()->facture;
        $this->assertSame(StatutFactureVente::PAYEE, $facture->statut_facture);
        $this->assertSame((float) (150000 - 7 * self::PRIX), $facture->tropPercu());
        $this->assertSame(0.0, $facture->montant_restant);
    }

    public function test_retour_total_rend_tout_l_encaisse_net_remboursable(): void
    {
        $commande = $this->precommandeChargee(10, 120000);

        $this->retourner($commande, 10)->assertSessionHasNoErrors();

        $commande->refresh();
        $this->assertSame(StatutCommandeVente::RETOURNEE, $commande->statut);
        $this->assertSame(StatutFactureVente::ANNULEE, $commande->facture->statut_facture);
        $this->assertSame(120000.0, $commande->facture->tropPercu());
        $this->assertSame(100, $this->stock());
        $this->assertSame(-120000.0, $this->soldeCompte('411000'));

        $this->rembourser($commande, 120000.01)->assertSessionHasErrors('montant');
        $this->rembourser($commande, 120000)->assertSessionHasNoErrors();

        $commande->refresh();
        $this->assertSame(0.0, $commande->facture->tropPercu());
        $this->assertSame(StatutCommandeVente::RETOURNEE, $commande->statut);
        $this->assertSame(0.0, $this->soldeCompte('411000'));
        $this->assertSame(0.0, $this->soldeCompte('419100'));
        $this->assertNull($this->cashback($commande));
    }

    public function test_encaissement_du_solde_confirme_la_livraison_et_ferme_la_fenetre_de_retour(): void
    {
        $commande = $this->precommandeChargee(10, 50000);

        $this->post("/backoffice/factures/{$commande->facture->id}/encaissements", ['montant' => 10000, 'mode_paiement' => 'especes'])
            ->assertSessionHasNoErrors();

        $commande->refresh();
        $this->assertSame(StatutCommandeVente::LIVREE, $commande->statut);
        $this->assertFalse($commande->isRetournable());
        $this->retourner($commande, 1)->assertForbidden();
    }

    // ── Écart de réception (C5) ───────────────────────────────────────────────

    public function test_ecart_de_reception_inferieur_aux_acomptes_est_accepte_en_trop_percu(): void
    {
        $commande = $this->precommandeChargee(10, 10 * self::PRIX, NatureOperation::DISTRIBUTION_CLIENT);
        $this->assertNull($this->cashback($commande));

        CommandeVenteService::validerReception($commande, [[
            'id' => $commande->lignes->first()->id,
            'quantite_livree' => 6,
            'type_ecart_reception' => 'manquant',
        ]]);

        $commande->refresh();
        $facture = $commande->facture;
        $this->assertSame(StatutCommandeVente::LIVREE, $commande->statut);
        $this->assertSame((float) (6 * self::PRIX), (float) $facture->montant_net);
        $this->assertSame(StatutFactureVente::PAYEE, $facture->statut_facture);
        $this->assertSame((float) (4 * self::PRIX), $facture->tropPercu());
        // Règle du 30/08/2026 inchangée : un écart de réception ne réintègre pas le stock.
        $this->assertSame(90, $this->stock());
        // Réception validée = livraison définitive : cashback sur le réceptionné.
        $this->assertSame(6 * self::CASHBACK_PAR_PACK, (int) $this->cashback($commande)->montant);

        $this->rembourser($commande, 4 * self::PRIX)->assertSessionHasNoErrors();
        $this->assertSame(StatutCommandeVente::CLOTUREE, $commande->fresh()->statut);
    }

    public function test_ecart_de_reception_d_une_vente_ordinaire_reste_refuse_au_dela_de_l_encaisse(): void
    {
        $commande = $this->precommandeChargee(10, 0, NatureOperation::DISTRIBUTION_CLIENT);
        $commande->update(['est_precommande' => false]);
        $this->post("/backoffice/factures/{$commande->facture->id}/encaissements", ['montant' => 10 * self::PRIX, 'mode_paiement' => 'especes'])
            ->assertSessionHasNoErrors();

        $this->expectException(ValidationException::class);
        CommandeVenteService::validerReception($commande->fresh(), [[
            'id' => $commande->lignes->first()->id,
            'quantite_livree' => 6,
            'type_ecart_reception' => 'manquant',
        ]]);
    }
}
