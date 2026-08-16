<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\EvenementComptable;
use App\Enums\ModePaiement;
use App\Enums\StatutPieceComptable;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\EcritureComptable;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Models\Organization;
use App\Models\PieceComptable;
use App\Models\TiersComptable;
use App\Services\Comptabilite\PlanComptableBootstrapService;
use App\Services\Comptabilite\VenteComptabilisationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Périmètre étendu de la comptabilité générale (vente/facture/encaissement),
 * absent de la V1 (dépenses + fiches uniquement) — cf. docs/data-dictionary-compta.md.
 * Ne comptabilise jamais la commission elle-même (CommissionVente) : reste
 * entièrement portée par FicheComptabilisationService, cf. DepenseComptabilisationServiceTest
 * pour le même principe côté dépenses.
 */
class VenteComptabilisationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(): Organization
    {
        $org = Organization::factory()->create();
        // Idempotent — déjà bootstrapé automatiquement par Organization::created(),
        // appelé explicitement ici pour ne pas dépendre de ce hook dans ce test.
        app(PlanComptableBootstrapService::class)->bootstrap($org->id);

        return $org;
    }

    private function service(): VenteComptabilisationService
    {
        return app(VenteComptabilisationService::class);
    }

    // ── Vente facturée ────────────────────────────────────────────────────────

    public function test_vente_facturee_comptabilise_client_debit_et_ventes_credit(): void
    {
        $org = $this->makeOrg();
        $facture = FactureVente::factory()->create([
            'organization_id' => $org->id,
            'montant_brut' => 3_000_000,
            'montant_net' => 3_000_000,
        ]);

        $piece = $this->service()->comptabiliserVenteFacturee($facture);

        $this->assertNotNull($piece);
        $this->assertSame(EvenementComptable::VENTE_FACTUREE->value, $piece->type_evenement);
        $this->assertSame(StatutPieceComptable::VALIDEE, $piece->statut);

        $lignes = $piece->lignes()->with('compte')->get();
        $ligneClient = $lignes->firstWhere('compte.numero', '411000');
        $ligneVente = $lignes->firstWhere('compte.numero', '701000');

        $this->assertNotNull($ligneClient);
        $this->assertEqualsWithDelta(3_000_000.0, (float) $ligneClient->debit, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $ligneClient->credit, 0.01);

        $this->assertNotNull($ligneVente);
        $this->assertEqualsWithDelta(3_000_000.0, (float) $ligneVente->credit, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $ligneVente->debit, 0.01);
    }

    public function test_vente_facturee_avec_client_cree_un_tiers_comptable(): void
    {
        $org = $this->makeOrg();
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $commande = CommandeVente::factory()->create(['organization_id' => $org->id, 'client_id' => $client->id]);
        $facture = FactureVente::factory()->create([
            'organization_id' => $org->id,
            'commande_vente_id' => $commande->id,
            'montant_net' => 50000,
        ]);

        $this->service()->comptabiliserVenteFacturee($facture);

        $tiers = TiersComptable::where('organization_id', $org->id)
            ->where('tiersable_type', $client->getMorphClass())
            ->where('tiersable_id', $client->id)
            ->first();

        $this->assertNotNull($tiers);
        $this->assertSame('client', $tiers->type);
    }

    public function test_vente_facturee_sans_client_comptabilise_quand_meme_sans_tiers(): void
    {
        $org = $this->makeOrg();
        $commande = CommandeVente::factory()->create(['organization_id' => $org->id, 'client_id' => null]);
        $facture = FactureVente::factory()->create([
            'organization_id' => $org->id,
            'commande_vente_id' => $commande->id,
            'montant_net' => 15000,
        ]);

        $piece = $this->service()->comptabiliserVenteFacturee($facture);

        $this->assertNotNull($piece);
        $this->assertSame(0, TiersComptable::where('organization_id', $org->id)->count());
    }

    public function test_vente_facturee_montant_nul_ne_comptabilise_rien(): void
    {
        $org = $this->makeOrg();
        $facture = FactureVente::factory()->create([
            'organization_id' => $org->id,
            'montant_brut' => 0,
            'montant_net' => 0,
        ]);

        $piece = $this->service()->comptabiliserVenteFacturee($facture);

        $this->assertNull($piece);
        $this->assertSame(0, PieceComptable::where('source_id', $facture->id)->count());
    }

    public function test_vente_facturee_est_idempotente(): void
    {
        $org = $this->makeOrg();
        $facture = FactureVente::factory()->create(['organization_id' => $org->id, 'montant_net' => 20000]);

        $piece1 = $this->service()->comptabiliserVenteFacturee($facture);
        $piece2 = $this->service()->comptabiliserVenteFacturee($facture);

        $this->assertSame($piece1->id, $piece2->id);
        $this->assertSame(1, PieceComptable::where('source_type', $facture->getMorphClass())->where('source_id', $facture->id)->count());
    }

    // ── Encaissement ──────────────────────────────────────────────────────────

    public function test_encaissement_comptabilise_tresorerie_debit_et_client_credit(): void
    {
        $org = $this->makeOrg();
        $facture = FactureVente::factory()->create(['organization_id' => $org->id, 'montant_net' => 100000]);
        $encaissement = EncaissementVente::create([
            'facture_vente_id' => $facture->id,
            'montant' => 100000,
            'date_encaissement' => '2026-08-14',
            'mode_paiement' => ModePaiement::ESPECES->value,
        ]);

        $piece = PieceComptable::query()
            ->where('source_type', $encaissement->getMorphClass())
            ->where('source_id', $encaissement->id)
            ->where('type_evenement', EvenementComptable::ENCAISSEMENT_VENTE_RECU->value)
            ->first();

        $this->assertNotNull($piece, 'Le trigger EncaissementVente::created doit comptabiliser automatiquement.');

        $lignes = $piece->lignes()->with('compte')->get();
        $ligneCaisse = $lignes->firstWhere('compte.numero', '571000');
        $ligneClient = $lignes->firstWhere('compte.numero', '411000');

        $this->assertNotNull($ligneCaisse);
        $this->assertEqualsWithDelta(100000.0, (float) $ligneCaisse->debit, 0.01);
        $this->assertNotNull($ligneClient);
        $this->assertEqualsWithDelta(100000.0, (float) $ligneClient->credit, 0.01);
    }

    public function test_encaissement_resout_le_compte_selon_le_moyen_de_paiement(): void
    {
        $org = $this->makeOrg();

        $cas = [
            ModePaiement::ESPECES->value => '571000',
            ModePaiement::VIREMENT->value => '521000',
            ModePaiement::CHEQUE->value => '521000',
            ModePaiement::MOBILE_MONEY->value => '561000',
        ];

        foreach ($cas as $modePaiement => $compteAttendu) {
            $facture = FactureVente::factory()->create(['organization_id' => $org->id, 'montant_net' => 10000]);
            $encaissement = EncaissementVente::create([
                'facture_vente_id' => $facture->id,
                'montant' => 10000,
                'date_encaissement' => '2026-08-14',
                'mode_paiement' => $modePaiement,
            ]);

            $piece = PieceComptable::query()
                ->where('source_type', $encaissement->getMorphClass())
                ->where('source_id', $encaissement->id)
                ->first();

            $numeros = $piece->lignes()->with('compte')->get()->pluck('compte.numero')->all();
            $this->assertContains($compteAttendu, $numeros, "moyen de paiement testé : {$modePaiement}");
        }
    }

    public function test_encaissement_montant_nul_ne_comptabilise_rien(): void
    {
        $org = $this->makeOrg();
        $facture = FactureVente::factory()->create(['organization_id' => $org->id, 'montant_net' => 10000]);

        // Instance non persistée (le retour précoce sur montant nul intervient avant
        // toute lecture de la clé primaire) : isole le service sans passer par les
        // validations métier normales du formulaire d'encaissement.
        $encaissementFactice = new EncaissementVente([
            'facture_vente_id' => $facture->id,
            'montant' => 0,
            'date_encaissement' => '2026-08-14',
            'mode_paiement' => ModePaiement::ESPECES->value,
        ]);

        $piece = $this->service()->comptabiliserEncaissementVente($encaissementFactice);

        $this->assertNull($piece);
    }

    // ── Contrepassation ───────────────────────────────────────────────────────

    public function test_contrepasse_une_vente_facturee_deja_comptabilisee(): void
    {
        $org = $this->makeOrg();
        $facture = FactureVente::factory()->create(['organization_id' => $org->id, 'montant_net' => 30000]);
        $piece = $this->service()->comptabiliserVenteFacturee($facture);

        $extourne = $this->service()->contrepasserVenteFactureeSiExistante($facture, 'Facture annulée — test');

        $this->assertNotNull($extourne);
        $this->assertSame($piece->id, $extourne->piece_origine_id);

        $piece->refresh();
        $this->assertSame(StatutPieceComptable::CONTREPASSEE, $piece->statut);

        // Écritures inversées : le compte 411 doit être net à zéro (débit initial +
        // crédit de la contrepassation).
        $soldeClient = EcritureComptable::query()
            ->whereHas('compte', fn ($q) => $q->where('numero', '411000'))
            ->whereIn('piece_comptable_id', [$piece->id, $extourne->id])
            ->selectRaw('COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) as solde')
            ->value('solde');

        $this->assertEqualsWithDelta(0.0, (float) $soldeClient, 0.01);
    }

    public function test_contrepassation_sans_effet_si_jamais_comptabilisee(): void
    {
        $org = $this->makeOrg();
        // Montant nul : jamais comptabilisée par construction.
        $facture = FactureVente::factory()->create(['organization_id' => $org->id, 'montant_net' => 0]);

        $extourne = $this->service()->contrepasserVenteFactureeSiExistante($facture, 'motif');

        $this->assertNull($extourne);
    }
}
