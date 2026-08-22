<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\CategorieDepense;
use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionStrategieAncrageSite;
use App\Enums\CommissionUniteCalcul;
use App\Enums\PrestataireType;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutDepense;
use App\Enums\TypePeriodePaiement;
use App\Models\Categorie;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionConsultantAffectation;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\Depense;
use App\Models\DepenseType;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\PaiementFiche;
use App\Models\Personne;
use App\Models\Prestataire;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use App\Services\CommandeVenteService;
use App\Services\Commission\CommissionEnveloppeGenerator;
use App\Services\PeriodeCalculatorService;
use App\Services\PeriodePaiementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Écran "Commission consultants" (Comptabilité) — mirroring CommissionSiteTest, sur
 * CommissionEnveloppePart filtrée beneficiaire_type=prestataire + enveloppe.cible_type=consultant.
 * Le bénéficiaire de l'écran EST le prestataire désigné au moment de chaque commission — jamais
 * "Fello Consulting" codé en dur, jamais limité au consultant actuellement désigné (cf. mission
 * §3 : un ancien consultant remplacé ou désactivé reste visible tant qu'il a des commissions).
 */
class CommissionConsultantTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Site $site;

    private CommissionProcessus $processus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read', 'ventes.create', 'ventes.update', 'comptabilite.read', 'commissions.exporter']);

        $this->site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Dépôt Matoto',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => false]);

        $this->processus = CommissionProcessus::create([
            'organization_id' => $this->org->id,
            'code' => CommissionProcessus::CODE_VENTE,
            'libelle' => 'Vente',
            'declencheur' => 'facture_encaissee',
            'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
            'statut' => CommissionActivationStatut::ACTIF->value,
        ]);
    }

    private function creerConsultant(bool $actif = true, string $prenom = 'Abdoulaye'): Prestataire
    {
        $personne = Personne::create([
            'organization_id' => $this->org->id,
            'nom' => 'Diallo',
            'prenom' => $prenom,
        ]);

        return Prestataire::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'type' => PrestataireType::CONSULTANT->value,
            'is_active' => $actif,
        ]);
    }

    private function designerConsultant(Prestataire $prestataire): CommissionConsultantAffectation
    {
        $ancienne = CommissionConsultantAffectation::actifPour($this->org->id);
        if ($ancienne) {
            $ancienne->update(['statut' => 'remplacee', 'effective_to' => now()->toDateString()]);
        }

        return CommissionConsultantAffectation::create([
            'organization_id' => $this->org->id,
            'prestataire_id' => $prestataire->id,
            'effective_from' => now()->subDay()->toDateString(),
            'remplace_affectation_id' => $ancienne?->id,
            'statut' => 'active',
        ]);
    }

    /** @return array{vehicule: Vehicule} véhicule minimal requis par le moteur. */
    private function makeVehicule(): array
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'capacite_packs' => 100,
        ]);
        $equipe = EquipeLivraison::create(['organization_id' => $this->org->id, 'vehicule_id' => $vehicule->id, 'is_active' => true]);
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id]);
        EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $livreur->id, 'role' => 'chauffeur', 'ordre' => 0]);

        return ['vehicule' => $vehicule->fresh()];
    }

    private function genererCommissionPourConsultant(Prestataire $consultant, float $montantParUnite = 200, int $quantite = 5): CommandeVente
    {
        $categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachets '.uniqid(), 'statut' => 'actif']);
        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $this->processus->id,
            'libelle' => 'Consultant',
            'scope_type' => CommissionScopeType::CATEGORIE->value,
            'scope_id' => $categorie->id,
            'cible_type' => CommissionCibleType::CODE_CONSULTANT,
            'mode' => 'direct',
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => $montantParUnite,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);
        $this->designerConsultant($consultant);

        $produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Produit '.uniqid(), 'categorie_id' => $categorie->id],
            ['prix_vente' => 2000, 'prix_usine' => 1500],
        );

        ['vehicule' => $vehicule] = $this->makeVehicule();
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 4000,
        ]);
        $variante = $produit->variantePrincipale()->first();
        $ligne = $commande->lignes()->create([
            'variante_id' => $variante->id,
            'quantite_demandee' => $quantite,
            'prix_usine_snapshot' => (float) $variante->prix_usine,
            'prix_vente_snapshot' => (float) $variante->prix_vente,
            'total_ligne' => $quantite * (float) $variante->prix_vente,
        ]);

        $this->actingAs($this->user);
        CommandeVenteService::confirmer($commande);
        CommandeVenteService::demarrerChargement($commande);
        CommandeVenteService::validerChargement($commande, [
            ['id' => $ligne->id, 'quantite_chargee' => $quantite, 'type_ecart' => 'conforme'],
        ]);

        $commande = $commande->fresh();
        CommissionEnveloppeGenerator::genererPourCommandeVente($commande);

        return $commande;
    }

    /** @test */
    public function index_liste_le_consultant_avec_les_bons_kpis(): void
    {
        $consultant = $this->creerConsultant();
        $this->genererCommissionPourConsultant($consultant, 200, 5); // 1000 GNF

        $response = $this->actingAs($this->user)->get(route('comptabilite.commissions.consultants.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Comptabilite/CommissionConsultant/Index')
            ->where('kpis.commissions_generees', 1000)
            ->has('beneficiaires', 1)
            ->where('beneficiaires.0.beneficiaire_nom', $consultant->nom_complet)
            ->where('beneficiaires.0.beneficiaire_id', $consultant->id)
        );
    }

    /** @test */
    public function index_refuse_un_utilisateur_sans_permission_comptabilite_read(): void
    {
        $lecteur = $this->makeUserWithPermissions($this->org, ['ventes.read']);

        $this->actingAs($lecteur)->get(route('comptabilite.commissions.consultants.index'))->assertForbidden();
    }

    /** @test */
    public function index_filtre_par_consultant_id(): void
    {
        $c1 = $this->creerConsultant(prenom: 'Abdoulaye');
        $this->genererCommissionPourConsultant($c1);
        $c2 = $this->creerConsultant(prenom: 'Fatoumata');
        $this->genererCommissionPourConsultant($c2);

        $response = $this->actingAs($this->user)->get(route('comptabilite.commissions.consultants.index', ['consultant_id' => $c1->id]));

        $response->assertInertia(fn ($page) => $page
            ->has('beneficiaires', 1)
            ->where('beneficiaires.0.beneficiaire_id', $c1->id)
        );
    }

    /**
     * Un ancien consultant remplacé reste visible avec ses commissions historiques — la page
     * n'affiche jamais uniquement le consultant actuellement désigné (cf. mission §3).
     */
    /** @test */
    public function un_ancien_consultant_remplace_reste_visible_avec_son_historique(): void
    {
        $ancien = $this->creerConsultant(prenom: 'Abdoulaye');
        $this->genererCommissionPourConsultant($ancien, 200, 5); // 1000 GNF, désigne $ancien

        $nouveau = $this->creerConsultant(prenom: 'Fatoumata');
        $this->designerConsultant($nouveau); // remplace $ancien comme consultant courant

        $response = $this->actingAs($this->user)->get(route('comptabilite.commissions.consultants.index'));

        $response->assertInertia(fn ($page) => $page
            ->has('beneficiaires', 1)
            ->where('beneficiaires.0.beneficiaire_id', $ancien->id)
            ->where('beneficiaires.0.total_genere', 1000)
        );
    }

    /**
     * Un consultant courant (désigné) sans aucune commission générée ne doit jamais apparaître
     * comme ayant une commission — le paramétrage courant ne sert qu'à déterminer le bénéficiaire
     * des FUTURES commissions, pas à injecter une ligne synthétique (cf. mission §3).
     */
    /** @test */
    public function un_consultant_courant_sans_commission_napparait_pas_dans_la_liste(): void
    {
        $consultant = $this->creerConsultant();
        $this->designerConsultant($consultant);
        // Aucune vente générée : le consultant est désigné mais n'a encore aucune part.

        $response = $this->actingAs($this->user)->get(route('comptabilite.commissions.consultants.index'));

        $response->assertInertia(fn ($page) => $page->has('beneficiaires', 0));
    }

    /**
     * Un consultant désactivé (Prestataire.is_active=false, ex: fin de contrat) reste visible
     * avec son historique — la désactivation ne concerne que l'éligibilité à de FUTURES
     * commissions (CommissionConsultantAffectation::actifPour ignore un prestataire inactif),
     * jamais la visibilité comptable rétroactive (cf. mission §3).
     */
    /** @test */
    public function un_consultant_desactive_reste_visible_avec_son_historique(): void
    {
        $consultant = $this->creerConsultant();
        $this->genererCommissionPourConsultant($consultant, 200, 5); // 1000 GNF

        $consultant->update(['is_active' => false]);

        $response = $this->actingAs($this->user)->get(route('comptabilite.commissions.consultants.index'));

        $response->assertInertia(fn ($page) => $page
            ->has('beneficiaires', 1)
            ->where('beneficiaires.0.beneficiaire_id', $consultant->id)
            ->where('beneficiaires.0.total_genere', 1000)
        );
    }

    /** @test */
    public function index_nexpose_jamais_les_commissions_dune_autre_organisation(): void
    {
        $consultant = $this->creerConsultant();
        $this->genererCommissionPourConsultant($consultant);

        $autreOrg = Organization::factory()->create();
        $autreUser = $this->makeUserWithPermissions($autreOrg, ['comptabilite.read']);
        $siteAutreOrg = Site::factory()->create(['organization_id' => $autreOrg->id]);
        $autreUser->sites()->attach($siteAutreOrg->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($autreUser)
            ->get(route('comptabilite.commissions.consultants.index'))
            ->assertInertia(fn ($page) => $page->has('beneficiaires', 0));
    }

    /** @test */
    public function une_depense_imputee_au_consultant_est_deduite_du_net(): void
    {
        $consultant = $this->creerConsultant();
        $this->genererCommissionPourConsultant($consultant, 200, 5); // 1000 GNF

        $type = DepenseType::create([
            'organization_id' => $this->org->id,
            'code' => 'FRAIS_MISSION',
            'libelle' => 'Frais de mission',
            'categorie' => CategorieDepense::PRESTATAIRE->value,
            'is_active' => true,
        ]);
        Depense::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $type->id,
            'beneficiaire_type' => CommissionEnveloppePart::TYPE_PRESTATAIRE,
            'beneficiaire_id' => $consultant->id,
            'montant' => 150,
            'date_depense' => now()->toDateString(),
            'statut' => StatutDepense::VALIDE->value,
        ]);

        $response = $this->actingAs($this->user)->get(route('comptabilite.commissions.consultants.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('kpis.depenses', 150)
            ->where('beneficiaires.0.total_frais', 150)
        );
    }

    /** @test */
    public function export_excel_contient_les_colonnes_et_le_consultant(): void
    {
        $consultant = $this->creerConsultant();
        $this->genererCommissionPourConsultant($consultant, 200, 5);

        $response = $this->actingAs($this->user)->get(route('comptabilite.commissions.consultants.excel'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();
        foreach (['Consultant', 'Référence', 'Téléphone', 'Généré', 'Brut validé', 'Dépenses', 'Net validé', 'Déjà payé', 'Reste à payer', 'Statut'] as $colonne) {
            $this->assertStringContainsString($colonne, $content);
        }
        $this->assertStringContainsString($consultant->nom_complet, $content);
        $this->assertStringContainsString('1 000', $content);
    }

    /** @test */
    public function export_excel_necessite_la_permission_commissions_exporter(): void
    {
        $sansExport = $this->makeUserWithPermissions($this->org, ['comptabilite.read']);

        $this->actingAs($sansExport)->get(route('comptabilite.commissions.consultants.excel'))->assertForbidden();
    }

    /** @test */
    public function export_pdf_retourne_un_pdf(): void
    {
        $consultant = $this->creerConsultant();
        $this->genererCommissionPourConsultant($consultant);

        $response = $this->actingAs($this->user)->get(route('comptabilite.commissions.consultants.pdf'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    /** @test */
    public function export_pdf_necessite_permission(): void
    {
        $sansExport = $this->makeUserWithPermissions($this->org, ['comptabilite.read']);

        $this->actingAs($sansExport)->get(route('comptabilite.commissions.consultants.pdf'))->assertForbidden();
    }

    /**
     * Intégration complète dans le workflow période/validation/paiement existant — jamais un
     * circuit parallèle (cf. mission §7). PeriodeCalculatorService::calculerConsultants() doit
     * produire une PaiementFiche standard, comme pour les sites/propriétaires, avec le PRESTATAIRE
     * comme bénéficiaire direct de la fiche, et une périodicité MENSUELLE (jamais la quinzaine des
     * livreurs, cf. mission §10).
     */
    /** @test */
    public function la_commission_consultant_entre_dans_le_cycle_standard_periode_fiche(): void
    {
        $consultant = $this->creerConsultant();
        $this->genererCommissionPourConsultant($consultant, 200, 5); // 1000 GNF

        $this->assertSame('mensuelle', TypePeriodePaiement::CONSULTANT->periodicity());

        $periode = app(PeriodePaiementService::class)->getOrCreatePeriod(
            $this->org->id,
            TypePeriodePaiement::CONSULTANT,
            now(),
            $this->user->id,
        );
        $resultat = app(PeriodeCalculatorService::class)->calculer($periode);

        $this->assertSame(1, $resultat['nb_fiches']);
        $this->assertDatabaseHas('paiement_fiches', [
            'periode_id' => $periode->id,
            'beneficiaire_type' => CommissionEnveloppePart::TYPE_PRESTATAIRE,
            'beneficiaire_id' => $consultant->id,
        ]);

        $fiche = PaiementFiche::where('periode_id', $periode->id)->firstOrFail();
        $this->assertEqualsWithDelta(1000.0, (float) $fiche->montant_net, 0.01);
        $this->assertSame($consultant->nom_complet, $fiche->beneficiaire_nom);
        $this->assertNull($fiche->site_id);
    }

    /**
     * L'écran "Détail de période" (partagé avec livreur/propriétaire) doit afficher le
     * consultant comme ligne bénéficiaire directe — jamais le tableau "Véhicule/Immatriculation/
     * Membres" vide avec "Aucune commission trouvée" alors que les cartes de synthèse affichent
     * un montant non nul (incohérence UI signalée) : ce concept de véhicule n'existe pas pour une
     * commission consultant.
     */
    /** @test */
    public function le_detail_de_periode_affiche_le_consultant_comme_beneficiaire_pas_comme_vehicule(): void
    {
        $consultant = $this->creerConsultant();
        $this->genererCommissionPourConsultant($consultant, 200, 5); // 1000 GNF

        $periode = app(PeriodePaiementService::class)->getOrCreatePeriod(
            $this->org->id,
            TypePeriodePaiement::CONSULTANT,
            now(),
            $this->user->id,
        );

        $response = $this->actingAs($this->user)->get(route('comptabilite.periodes.show', $periode));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Comptabilite/Periodes/Show')
            ->where('vehicules', [])
            ->has('beneficiaires', 1)
            ->where('beneficiaires.0.beneficiaire_nom', $consultant->nom_complet)
            ->where('beneficiaires.0.montant_net', 1000)
            ->where('stats.total_net', 1000)
        );
    }
}
