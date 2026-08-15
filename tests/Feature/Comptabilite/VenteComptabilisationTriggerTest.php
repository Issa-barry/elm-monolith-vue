<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\EvenementComptable;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutPieceComptable;
use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use App\Models\EcritureComptable;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Models\Organization;
use App\Models\PieceComptable;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use App\Services\CommandeVenteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Branchement réel (pas seulement le service en isolation, cf.
 * VenteComptabilisationServiceTest) : CommandeVenteService → FactureVente →
 * EncaissementVente → compta_pieces/compta_ecritures, à travers le vrai workflow
 * de vente (même gabarit que CommissionTriggerVenteTest).
 */
class VenteComptabilisationTriggerTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Site $defaultSite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read', 'ventes.create', 'ventes.update']);

        $this->defaultSite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->defaultSite->id, ['role' => 'employe', 'is_default' => true]);
    }

    // ── Helpers (identiques à CommissionTriggerVenteTest) ───────────────────────

    private function makeVehiculeSansEquipe(): Vehicule
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        return Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'capacite_packs' => 10,
        ]);
    }

    private function makeProduit(): Produit
    {
        return $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Produit Test'],
            ['prix_vente' => 2000, 'prix_usine' => 1500],
        );
    }

    /** @return array{commande: CommandeVente, ligne: CommandeVenteLigne} */
    private function creerCommandeAvecLigne(Vehicule $vehicule, Produit $produit): array
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 4000,
        ]);

        $ligne = $commande->lignes()->create([
            'variante_id' => $produit->variantePrincipale()->first()->id,
            'quantite_demandee' => 2,
            'prix_usine_snapshot' => 1500.0,
            'prix_vente_snapshot' => 2000.0,
            'total_ligne' => 4000.0,
        ]);

        return compact('commande', 'ligne');
    }

    private function pieceVenteFacturee(string $factureId): ?PieceComptable
    {
        return PieceComptable::query()
            ->where('source_type', FactureVente::class)
            ->where('source_id', $factureId)
            ->where('type_evenement', EvenementComptable::VENTE_FACTUREE->value)
            ->first();
    }

    // ── Cycle complet ────────────────────────────────────────────────────────

    public function test_facture_creee_en_brouillon_nest_pas_encore_comptabilisee(): void
    {
        $vehicule = $this->makeVehiculeSansEquipe();
        $produit = $this->makeProduit();
        ['commande' => $commande] = $this->creerCommandeAvecLigne($vehicule, $produit);

        $this->actingAs($this->user);
        CommandeVenteService::confirmer($commande);

        $facture = $commande->fresh('facture')->facture;
        $this->assertNotNull($facture);
        $this->assertTrue($facture->isCreee());
        $this->assertNull($this->pieceVenteFacturee($facture->id), 'Une facture encore CREEE (montant estimatif) ne doit jamais être comptabilisée.');
    }

    public function test_validation_du_chargement_comptabilise_la_vente_au_montant_definitif(): void
    {
        $vehicule = $this->makeVehiculeSansEquipe();
        $produit = $this->makeProduit();
        ['commande' => $commande, 'ligne' => $ligne] = $this->creerCommandeAvecLigne($vehicule, $produit);

        $this->actingAs($this->user);
        CommandeVenteService::confirmer($commande);
        CommandeVenteService::demarrerChargement($commande);
        CommandeVenteService::validerChargement($commande, [[
            'id' => $ligne->id,
            'quantite_chargee' => 3, // écart : 3 au lieu de 2 demandées
            'type_ecart' => 'surplus',
        ]]);

        $facture = $commande->fresh('facture')->facture;
        $this->assertFalse($facture->isCreee(), 'La facture doit être sortie de CREEE après validation du chargement.');
        $piece = $this->pieceVenteFacturee($facture->id);

        $this->assertNotNull($piece);
        // Montant définitif (3 x 2000 = 6000), pas l'estimation initiale (4000).
        $ligneVente = $piece->lignes()->with('compte')->get()->firstWhere('compte.numero', '701000');
        $this->assertEqualsWithDelta(6000.0, (float) $ligneVente->credit, 0.01);
    }

    public function test_encaissement_integral_comptabilise_lencaissement(): void
    {
        $vehicule = $this->makeVehiculeSansEquipe();
        $produit = $this->makeProduit();
        ['commande' => $commande, 'ligne' => $ligne] = $this->creerCommandeAvecLigne($vehicule, $produit);

        $this->actingAs($this->user);
        CommandeVenteService::confirmer($commande);
        CommandeVenteService::demarrerChargement($commande);
        CommandeVenteService::validerChargement($commande, [[
            'id' => $ligne->id,
            'quantite_chargee' => $ligne->quantite_demandee,
            'type_ecart' => 'conforme',
        ]]);

        $facture = $commande->fresh('facture')->facture;
        $this->post(route('encaissements.store', $facture), [
            'montant' => $facture->montant_restant,
            'mode_paiement' => 'especes',
        ])->assertRedirect();

        $encaissement = $facture->fresh()->encaissements()->first();
        $piece = PieceComptable::query()
            ->where('source_type', EncaissementVente::class)
            ->where('source_id', $encaissement->id)
            ->first();

        $this->assertNotNull($piece);
    }

    public function test_cycle_complet_vente_et_encaissement_reste_equilibre(): void
    {
        $vehicule = $this->makeVehiculeSansEquipe();
        $produit = $this->makeProduit();
        ['commande' => $commande, 'ligne' => $ligne] = $this->creerCommandeAvecLigne($vehicule, $produit);

        $this->actingAs($this->user);
        CommandeVenteService::confirmer($commande);
        CommandeVenteService::demarrerChargement($commande);
        CommandeVenteService::validerChargement($commande, [[
            'id' => $ligne->id,
            'quantite_chargee' => $ligne->quantite_demandee,
            'type_ecart' => 'conforme',
        ]]);

        $facture = $commande->fresh('facture')->facture;
        $this->post(route('encaissements.store', $facture), [
            'montant' => $facture->montant_restant,
            'mode_paiement' => 'especes',
        ])->assertRedirect();

        $totalDebit = EcritureComptable::query()
            ->whereHas('piece', fn ($q) => $q->where('organization_id', $this->org->id))
            ->sum('debit');
        $totalCredit = EcritureComptable::query()
            ->whereHas('piece', fn ($q) => $q->where('organization_id', $this->org->id))
            ->sum('credit');

        $this->assertEqualsWithDelta((float) $totalDebit, (float) $totalCredit, 0.01);
        $this->assertGreaterThan(0, (float) $totalDebit);
    }

    public function test_annulation_dune_vente_directe_contrepasse_la_pieces(): void
    {
        $produit = $this->makeProduit();
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'vehicule_id' => null,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 2000,
        ]);
        $commande->lignes()->create([
            'variante_id' => $produit->variantePrincipale()->first()->id,
            'quantite_demandee' => 1,
            'prix_usine_snapshot' => 1500.0,
            'prix_vente_snapshot' => 2000.0,
            'total_ligne' => 2000.0,
        ]);

        $this->actingAs($this->user);
        CommandeVenteService::creerFactureDirecte($commande);

        $facture = $commande->fresh('facture')->facture;
        $piece = $this->pieceVenteFacturee($facture->id);
        $this->assertNotNull($piece, 'La vente directe doit être comptabilisée dès sa création (statut_facture déjà IMPAYEE).');

        CommandeVenteService::annuler($commande->fresh(), 'test annulation');

        $piece->refresh();
        $this->assertSame(StatutPieceComptable::CONTREPASSEE, $piece->statut);

        $extourne = PieceComptable::where('piece_origine_id', $piece->id)->first();
        $this->assertNotNull($extourne);
    }

    // ── Isolation multi-tenant ────────────────────────────────────────────────

    public function test_isolation_entre_organisations(): void
    {
        $vehicule = $this->makeVehiculeSansEquipe();
        $produit = $this->makeProduit();
        ['commande' => $commande, 'ligne' => $ligne] = $this->creerCommandeAvecLigne($vehicule, $produit);

        $this->actingAs($this->user);
        CommandeVenteService::confirmer($commande);
        CommandeVenteService::demarrerChargement($commande);
        CommandeVenteService::validerChargement($commande, [[
            'id' => $ligne->id,
            'quantite_chargee' => $ligne->quantite_demandee,
            'type_ecart' => 'conforme',
        ]]);

        $orgB = Organization::factory()->create();

        $this->assertSame(0, PieceComptable::where('organization_id', $orgB->id)->count());
        $this->assertGreaterThan(0, PieceComptable::where('organization_id', $this->org->id)->count());
    }
}
