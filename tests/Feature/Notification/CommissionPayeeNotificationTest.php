<?php

namespace Tests\Feature\Notification;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionMode;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionStrategieAncrageSite;
use App\Enums\CommissionUniteCalcul;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutPeriodePaiement;
use App\Enums\TypePeriodePaiement;
use App\Models\Categorie;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionLogistique;
use App\Models\CommissionLogistiquePart;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\EquipeLivreur;
use App\Models\PaiementFiche;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\Vehicule;
use App\Notifications\CommissionPayeeNotification;
use App\Services\CommandeVenteService;
use App\Services\Commission\CommissionEnveloppeGenerator;
use App\Services\CommissionAdjustmentService;
use App\Services\CommissionLogistiqueService;
use App\Services\CommissionPaymentService;
use App\Services\PeriodeCalculatorService;
use App\Services\PeriodePaiementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\Feature\Concerns\MakesClientProfiles;
use Tests\TestCase;

/**
 * Phase 1 archi notifications (2026-08-27, cf. rapport) : CommissionPayeeNotification
 * était définie mais jamais dispatchée avant ce chantier (constat audit
 * 26/08/2026). Couvre les 3 chemins réels de paiement d'une commission :
 * paiement direct logistique, versement legacy logistique, paiement de fiche
 * (vente et logistique confondues, un seul hook au niveau du contrôleur).
 */
class CommissionPayeeNotificationTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, MakesClientProfiles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read', 'ventes.create', 'ventes.update', 'comptabilite.read', 'comptabilite.payer']);
    }

    private function makePartPayable(float $montant, $livreur): CommissionLogistiquePart
    {
        $site = $this->user->sites()->first();
        $autreSite = Site::create(['organization_id' => $this->org->id, 'nom' => 'Autre', 'type' => 'depot', 'localisation' => 'Conakry']);
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id, 'site_id' => $site->id]);
        $transfert = TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'site_source_id' => $site->id,
            'site_destination_id' => $autreSite->id,
            'created_by' => $this->user->id,
        ]);
        $commission = CommissionLogistique::create([
            'organization_id' => $this->org->id,
            'transfert_logistique_id' => $transfert->id,
            'vehicule_id' => $vehicule->id,
            'base_calcul' => 'forfait',
            'valeur_base' => $montant,
            'montant_total' => $montant,
            'montant_verse' => 0,
            'statut' => 'impaye',
        ]);

        // La période couvrant earned_at doit être validée pour que le paiement direct
        // soit autorisé (PeriodePayabilityChecker::assertPartsPayable).
        [$debut] = PeriodePaiementService::dateRangeFor(2026, 8, PeriodePaiementService::P1);
        $periode = app(PeriodePaiementService::class)->getOrCreatePeriod($this->org->id, TypePeriodePaiement::LIVREUR, $debut);
        app(PeriodeCalculatorService::class)->calculerSiNecessaire($periode);
        $periode->update(['statut' => StatutPeriodePaiement::VALIDEE->value]);

        return CommissionLogistiquePart::create([
            'commission_logistique_id' => $commission->id,
            'type_beneficiaire' => 'livreur',
            'livreur_id' => $livreur->id,
            'beneficiaire_nom' => $livreur->libelleAffichage(),
            'taux_commission' => 100,
            'montant_brut' => $montant,
            'frais_supplementaires' => 0,
            'montant_net' => $montant,
            'montant_verse' => 0,
            'statut' => 'impaye',
            'earned_at' => '2026-08-05',
        ]);
    }

    public function test_paiement_direct_notifie_le_livreur_beneficiaire(): void
    {
        Notification::fake();
        $this->actingAs($this->user);

        $livreurUser = $this->makeLivreurUser($this->org);
        $part = $this->makePartPayable(150_000, $livreurUser->livreur);

        CommissionPaymentService::payerLivreur(
            livreurId: $part->livreur_id,
            orgId: $this->org->id,
            montant: 150_000,
            modePaiement: 'especes',
            paidAt: now()->toDateString(),
        );

        Notification::assertSentTo($livreurUser, CommissionPayeeNotification::class);
    }

    public function test_versement_legacy_notifie_le_livreur_beneficiaire(): void
    {
        Notification::fake();
        $this->actingAs($this->user);

        $livreurUser = $this->makeLivreurUser($this->org);
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id]);
        $transfert = TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'site_source_id' => $this->user->sites()->first()->id,
            'site_destination_id' => $this->user->sites()->first()->id,
            'vehicule_id' => $vehicule->id,
            'created_by' => $this->user->id,
        ]);
        $commission = CommissionLogistique::create([
            'organization_id' => $this->org->id,
            'transfert_logistique_id' => $transfert->id,
            'vehicule_id' => $vehicule->id,
            'base_calcul' => 'forfait',
            'valeur_base' => 50_000,
            'montant_total' => 50_000,
            'montant_verse' => 0,
            'statut' => 'impaye',
        ]);
        $part = CommissionLogistiquePart::create([
            'commission_logistique_id' => $commission->id,
            'type_beneficiaire' => 'livreur',
            'livreur_id' => $livreurUser->livreur->id,
            'beneficiaire_nom' => $livreurUser->livreur->libelleAffichage(),
            'taux_commission' => 100,
            'montant_brut' => 50_000,
            'frais_supplementaires' => 0,
            'montant_net' => 50_000,
            'montant_verse' => 0,
            'statut' => 'impaye',
            'earned_at' => now()->toDateString(),
        ]);

        CommissionLogistiqueService::verser($part, 50_000, now()->toDateString(), 'especes');

        Notification::assertSentTo($livreurUser, CommissionPayeeNotification::class);
    }

    public function test_paiement_de_fiche_notifie_le_beneficiaire(): void
    {
        Notification::fake();

        $processus = CommissionProcessus::create([
            'organization_id' => $this->org->id,
            'code' => CommissionProcessus::CODE_VENTE,
            'libelle' => 'Vente',
            'declencheur' => 'facture_encaissee',
            'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
            'statut' => CommissionActivationStatut::ACTIF->value,
        ]);

        $defaultSite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($defaultSite->id, ['role' => 'employe', 'is_default' => true]);

        $livreurUser = $this->makeLivreurUser($this->org);

        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id, 'capacite_packs' => 100]);
        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Équipe Test',
            'is_active' => true,
        ]);
        EquipeLivreur::create([
            'equipe_id' => $equipe->id,
            'livreur_id' => $livreurUser->livreur->id,
            'role' => 'chauffeur',
            'ordre' => 0,
        ]);

        $categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachets', 'statut' => 'actif']);
        EquipeLivraisonPartageCategorie::create([
            'equipe_id' => $equipe->id,
            'categorie_id' => $categorie->id,
            'livreur_id' => $livreurUser->livreur->id,
            'part_pourcentage' => 0,
            'montant_unitaire' => 1000,
            'effective_from' => now()->subDay(),
        ]);
        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $processus->id,
            'libelle' => 'Règle équipe livraison',
            'scope_type' => CommissionScopeType::CATEGORIE->value,
            'scope_id' => $categorie->id,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'mode' => CommissionMode::A_REPARTIR->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => 1000,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);

        $produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Produit '.uniqid(), 'categorie_id' => $categorie->id],
            ['prix_vente' => 2000, 'prix_usine' => 1500],
        );
        $variante = $produit->variantePrincipale()->first();
        $this->seedVarianteStockSuffisant($variante, $defaultSite);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $defaultSite->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 20000,
        ]);
        $ligne = $commande->lignes()->create([
            'variante_id' => $variante->id,
            'quantite_demandee' => 10,
            'prix_usine_snapshot' => (float) $variante->prix_usine,
            'prix_vente_snapshot' => (float) $variante->prix_vente,
            'total_ligne' => 10 * (float) $variante->prix_vente,
        ]);

        $this->actingAs($this->user);
        CommandeVenteService::confirmer($commande);
        CommandeVenteService::demarrerChargement($commande);
        CommandeVenteService::validerChargement($commande, [
            ['id' => $ligne->id, 'quantite_chargee' => 10, 'type_ecart' => 'conforme'],
        ]);

        CommissionEnveloppeGenerator::genererPourCommandeVente($commande->fresh());

        $periode = app(PeriodePaiementService::class)->getOrCreatePeriod(
            $this->org->id, TypePeriodePaiement::LIVREUR, now(), $this->user->id,
        );
        app(PeriodeCalculatorService::class)->calculer($periode);

        $parts = CommissionAdjustmentService::partsPourPeriode($periode);
        CommissionAdjustmentService::validerLot($parts, $this->user);

        $this->actingAs($this->user)
            ->post(route('comptabilite.periodes.valider', $periode))
            ->assertSessionHas('success');

        $fiche = PaiementFiche::where('periode_id', $periode->id)
            ->where('beneficiaire_id', $livreurUser->livreur->id)
            ->firstOrFail();

        $this->actingAs($this->user)
            ->post(route('comptabilite.fiches.paiements.store', $fiche), [
                'montant' => 10000,
                'mode_paiement' => 'especes',
                'date_paiement' => now()->toDateString(),
            ])
            ->assertRedirect();

        Notification::assertSentTo($livreurUser, CommissionPayeeNotification::class);
    }
}
