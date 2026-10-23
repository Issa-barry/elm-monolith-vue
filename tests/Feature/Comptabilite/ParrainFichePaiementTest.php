<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\CommissionMode;
use App\Enums\CommissionRegleStatut;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionUniteCalcul;
use App\Enums\DeclencheurCommissionVente;
use App\Enums\EvenementComptable;
use App\Enums\NatureOperation;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutPeriodePaiement;
use App\Enums\TypePeriodePaiement;
use App\Models\Categorie;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\PaiementFiche;
use App\Models\PaiementFichePaiement;
use App\Models\PaiementPeriode;
use App\Models\Parametre;
use App\Models\Parrain;
use App\Models\PieceComptable;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use App\Services\CommandeVenteService;
use App\Services\Commission\CommissionProcessusDefaults;
use App\Services\Commission\PartageLivraisonVersionService;
use App\Services\Commission\PartageParrainService;
use App\Services\Comptabilite\PlanComptableBootstrapService;
use App\Services\PeriodeCalculatorService;
use App\Services\PeriodePaiementService;
use App\Services\PeriodeValidationService;
use App\Services\Tresorerie\ObligationsAgenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Paiement de la part du parrain (ADR 0035, lot 3) : fiche « parrain » à la quinzaine, payée par
 * l'agence du véhicule ; écritures 622500 / 467210 à la validation, 467210 / trésorerie au
 * paiement ; fiche complémentaire après un paiement ; colonnes Parrains P1/P2 de la prévision de
 * trésorerie. Barème 1 000 GNF/pack : chauffeur 500, convoyeur 300, parrain 200.
 */
class ParrainFichePaiementTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Site $site;

    private Categorie $categorie;

    private Produit $produit;

    private Vehicule $vehicule;

    private EquipeLivraison $equipe;

    private Livreur $chauffeur;

    private Livreur $convoyeur;

    private Parrain $parrain;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-10 10:00:00');

        $this->initOrgAndUser([
            'ventes.read', 'ventes.create', 'ventes.update',
            'comptabilite.read', 'equipes-livraison.update', 'vehicules.update',
        ]);
        app(PlanComptableBootstrapService::class)->bootstrap($this->org->id);
        Parametre::setDeclencheurCommissionVente($this->org->id, DeclencheurCommissionVente::CHARGEMENT_VALIDE);

        $this->site = $this->user->sites()->firstOrFail();
        $this->categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachet d\'eau', 'statut' => 'actif']);
        $this->produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack de 25 Sachets', 'categorie_id' => $this->categorie->id],
            ['prix_vente' => 2000, 'prix_usine' => 1500],
        );

        $this->vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'proprietaire_id' => Proprietaire::factory()->create(['organization_id' => $this->org->id])->id,
            'livraison_vente' => true,
            'livraison_logistique' => false,
            'is_active' => true,
        ]);
        $this->equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $this->vehicule->id,
            'nom' => 'Équipe',
            'is_active' => true,
        ]);
        foreach (['chauffeur', 'convoyeur'] as $ordre => $role) {
            $livreur = Livreur::factory()->create(['organization_id' => $this->org->id, 'is_active' => true]);
            EquipeLivreur::create(['equipe_id' => $this->equipe->id, 'livreur_id' => $livreur->id, 'role' => $role, 'ordre' => $ordre]);
            $this->{$role} = $livreur;
        }

        $vente = CommissionProcessusDefaults::resoudreOuCreer($this->org->id, CommissionProcessus::CODE_VENTE);
        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $vente->id,
            'libelle' => 'Livreur — Sachet',
            'scope_type' => CommissionScopeType::CATEGORIE->value,
            'scope_id' => $this->categorie->id,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'mode' => CommissionMode::A_REPARTIR->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => 1000,
            'effective_from' => '2026-10-01',
            'statut' => CommissionRegleStatut::ACTIVE->value,
        ]);
        foreach ([$this->chauffeur->id => 600, $this->convoyeur->id => 400] as $livreurId => $montant) {
            EquipeLivraisonPartageCategorie::create([
                'equipe_id' => $this->equipe->id,
                'processus_id' => $vente->id,
                'categorie_id' => $this->categorie->id,
                'livreur_id' => $livreurId,
                'part_pourcentage' => 0,
                'montant_unitaire' => $montant,
                'effective_from' => '2026-10-01',
            ]);
        }

        $this->parrain = Parrain::factory()->create(['organization_id' => $this->org->id]);
        DB::transaction(function () {
            $this->vehicule->update(['parrain_id' => $this->parrain->id]);
            PartageParrainService::appliquerChangementDeParrain($this->vehicule, null);
        });
        PartageLivraisonVersionService::versionner(
            $this->equipe->id, $vente->id, $this->categorie->id,
            [$this->chauffeur->id => 500, $this->convoyeur->id => 300], Carbon::today(), 200,
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function vendre(int $qte): CommandeVente
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'vehicule_id' => $this->vehicule->id,
            'nature_operation' => NatureOperation::VENTE_STANDARD->value,
            'statut' => StatutCommandeVente::BROUILLON,
            'commission_eligible_snapshot' => true,
            'total_commande' => $qte * 2000,
        ]);
        $variante = $this->produit->variantePrincipale()->first();
        $ligne = $commande->lignes()->create([
            'variante_id' => $variante->id,
            'quantite_demandee' => $qte,
            'prix_usine_snapshot' => 1500,
            'prix_vente_snapshot' => 2000,
            'total_ligne' => $qte * 2000,
        ]);
        $this->seedVarianteStockSuffisant($variante, $this->site);

        $this->actingAs($this->user);
        CommandeVenteService::confirmer($commande);
        CommandeVenteService::demarrerChargement($commande->fresh());
        CommandeVenteService::validerChargement($commande->fresh(), [
            ['id' => $ligne->id, 'quantite_chargee' => $qte, 'type_ecart' => 'conforme'],
        ]);

        return $commande->fresh();
    }

    private function periode(TypePeriodePaiement $type): PaiementPeriode
    {
        $periode = app(PeriodePaiementService::class)->getOrCreatePeriod($this->org->id, $type, Carbon::today());
        app(PeriodeCalculatorService::class)->calculerSiNecessaire($periode);

        return $periode->fresh();
    }

    /** @return Collection<int, PaiementFiche> */
    private function fichesParrain(PaiementPeriode $periode)
    {
        return PaiementFiche::where('periode_id', $periode->id)
            ->where('beneficiaire_type', 'parrain')
            ->orderBy('rang')
            ->get();
    }

    public function test_la_fiche_parrain_reprend_sa_part_en_premiere_quinzaine_sans_toucher_aux_livreurs(): void
    {
        $this->vendre(100);

        $periodeParrain = $this->periode(TypePeriodePaiement::PARRAIN);
        $fiche = $this->fichesParrain($periodeParrain)->sole();

        $this->assertStringContainsString('P1-PAR', $periodeParrain->reference);
        $this->assertSame($this->parrain->id, $fiche->beneficiaire_id);
        $this->assertEquals(20000.0, (float) $fiche->montant_net);
        $this->assertSame($this->site->id, $fiche->site_id, 'Payée par l\'agence du véhicule.');

        $periodeLivreurs = $this->periode(TypePeriodePaiement::LIVREUR);
        $this->assertEquals(80000.0, (float) PaiementFiche::where('periode_id', $periodeLivreurs->id)->sum('montant_net'));
        $this->assertEquals(
            100000.0,
            (float) CommissionEnveloppePart::whereIn('beneficiaire_type', ['livreur', 'parrain'])->sum('montant_net'),
            'Aucune commission en plus : livreurs + parrain = enveloppe.',
        );
    }

    public function test_la_fiche_parrain_suit_la_seconde_quinzaine(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->vendre(10);

        $periode = $this->periode(TypePeriodePaiement::PARRAIN);

        $this->assertStringContainsString('P2-PAR', $periode->reference);
        $this->assertEquals(2000.0, (float) $this->fichesParrain($periode)->sole()->montant_net);
    }

    public function test_validation_et_paiement_partiel_passent_en_comptabilite_puis_fiche_complementaire(): void
    {
        $this->vendre(100);
        $periode = $this->periode(TypePeriodePaiement::PARRAIN);

        // Part validée d'office : la période se valide sans intervention.
        app(PeriodeValidationService::class)->validerSiComplete($periode);
        $this->assertSame(StatutPeriodePaiement::VALIDEE, $periode->fresh()->statut);

        $fiche = $this->fichesParrain($periode)->sole();
        $engagement = PieceComptable::where('source_type', $fiche->getMorphClass())
            ->where('source_id', $fiche->id)
            ->where('type_evenement', EvenementComptable::FICHE_PARRAIN_VALIDEE->value)
            ->firstOrFail();
        $lignes = $engagement->lignes()->with('compte')->get()->keyBy(fn ($l) => $l->compte->numero);
        $this->assertEquals(20000.0, (float) $lignes['622500']->debit);
        $this->assertEquals(20000.0, (float) $lignes['467210']->credit);

        $paiement = PaiementFichePaiement::create([
            'fiche_id' => $fiche->id,
            'organization_id' => $this->org->id,
            'montant' => 5000,
            'mode_paiement' => 'especes',
            'date_paiement' => '2026-10-16',
        ]);
        $reglement = PieceComptable::where('source_type', $paiement->getMorphClass())
            ->where('source_id', $paiement->id)
            ->where('type_evenement', EvenementComptable::PAIEMENT_PARRAIN->value)
            ->firstOrFail();
        $lignesReglement = $reglement->lignes()->with('compte')->get()->keyBy(fn ($l) => $l->compte->numero);
        $this->assertEquals(5000.0, (float) $lignesReglement['467210']->debit);
        $this->assertEquals(5000.0, (float) $lignesReglement['571000']->credit);
        $this->assertArrayNotHasKey('622500', $lignesReglement->all(), 'Aucune charge recréée au paiement.');

        // Nouvelle vente dans la même quinzaine : la fiche payée reste intacte, la part
        // tardive va sur une fiche complémentaire.
        $this->vendre(10);
        $fiches = $this->fichesParrain($periode->fresh());

        $this->assertCount(2, $fiches);
        $this->assertEquals(20000.0, (float) $fiches->first()->montant_net);
        $this->assertEquals(5000.0, (float) $fiches->first()->fresh()->montant_paye);
        $this->assertSame(2, $fiches->last()->rang);
        $this->assertSame($fiche->id, $fiches->last()->fiche_origine_id);
        $this->assertEquals(2000.0, (float) $fiches->last()->montant_net);
    }

    public function test_la_prevision_de_tresorerie_porte_les_parrains_en_p1(): void
    {
        $this->vendre(100);

        $rows = collect(app(ObligationsAgenceService::class)->calculerPourMois($this->org->id, 2026, 10));
        $agence = $rows->firstWhere('site_id', $this->site->id);

        $this->assertEquals(20000.0, (float) $agence['parrains_p1']);
        $this->assertEquals(0.0, (float) $agence['parrains_p2']);
        $this->assertEquals(80000.0, (float) $agence['livreurs_p1'], 'La colonne livreurs ne compte que les livreurs.');
    }

    public function test_la_page_fiches_parrains_liste_la_fiche(): void
    {
        $this->vendre(100);
        $this->periode(TypePeriodePaiement::PARRAIN);

        $this->actingAs($this->user)
            ->get(route('comptabilite.fiches.parrains'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Comptabilite/Fiches/Index')
                ->where('type', 'parrain')
                ->where('fiches.data.0.beneficiaire_type', 'parrain')
            );
    }
}
