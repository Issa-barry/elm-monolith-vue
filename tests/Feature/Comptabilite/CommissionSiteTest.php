<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionStrategieAncrageSite;
use App\Enums\CommissionUniteCalcul;
use App\Enums\StatutCommandeVente;
use App\Enums\TypePeriodePaiement;
use App\Models\Categorie;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\PaiementFiche;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use App\Services\CommandeVenteService;
use App\Services\Commission\CommissionEnveloppeGenerator;
use App\Services\PeriodeCalculatorService;
use App\Services\PeriodePaiementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Écran "Commission sites" (Comptabilité) — mirroring CommissionExportVenteTest, sur
 * CommissionEnveloppePart filtrée beneficiaire_type=site + enveloppe.cible_type=site. Le
 * bénéficiaire de l'écran EST le site — aucun gérant, employé, fonction ou rôle n'intervient.
 */
class CommissionSiteTest extends TestCase
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

    private function genererCommissionPourSite(Site $site, float $montantParUnite = 200, int $quantite = 5): CommandeVente
    {
        $categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachets '.uniqid(), 'statut' => 'actif']);
        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $this->processus->id,
            'libelle' => 'Site',
            'scope_type' => CommissionScopeType::CATEGORIE->value,
            'scope_id' => $categorie->id,
            'cible_type' => CommissionCibleType::CODE_SITE,
            'mode' => 'direct',
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => $montantParUnite,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);

        $produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Produit '.uniqid(), 'categorie_id' => $categorie->id],
            ['prix_vente' => 2000, 'prix_usine' => 1500],
        );

        ['vehicule' => $vehicule] = $this->makeVehicule();
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
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

        $this->seedVarianteStockSuffisant($variante, $site);

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
    public function index_liste_le_site_avec_les_bons_kpis(): void
    {
        $this->genererCommissionPourSite($this->site, 200, 5); // 1000 GNF

        $response = $this->actingAs($this->user)->get(route('comptabilite.commissions.sites.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Comptabilite/CommissionSite/Index')
            ->where('kpis.commissions_generees', 1000)
            ->has('beneficiaires', 1)
            ->where('beneficiaires.0.beneficiaire_nom', $this->site->nom)
            ->where('beneficiaires.0.beneficiaire_id', $this->site->id)
        );
    }

    /** @test */
    public function index_refuse_un_utilisateur_sans_permission_comptabilite_read(): void
    {
        $lecteur = $this->makeUserWithPermissions($this->org, ['ventes.read']);

        $this->actingAs($lecteur)->get(route('comptabilite.commissions.sites.index'))->assertForbidden();
    }

    /** @test */
    public function index_filtre_par_site_ids(): void
    {
        $autreSite = Site::create(['organization_id' => $this->org->id, 'nom' => 'Dépôt Kaloum', 'type' => 'depot']);
        $this->user->sites()->attach($autreSite->id, ['role' => 'employe', 'is_default' => false]);

        $this->genererCommissionPourSite($this->site);
        $this->genererCommissionPourSite($autreSite);

        $response = $this->actingAs($this->user)->get(route('comptabilite.commissions.sites.index', ['site_ids' => [$this->site->id]]));

        $response->assertInertia(fn ($page) => $page
            ->has('beneficiaires', 1)
            ->where('beneficiaires.0.beneficiaire_id', $this->site->id)
        );
    }

    /** @test */
    public function index_filtre_par_categorie_id(): void
    {
        $commande = $this->genererCommissionPourSite($this->site);
        $categorieId = $commande->lignes->first()->variante->produit->categorie_id;
        $autreCategorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Autre', 'statut' => 'actif']);

        $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.sites.index', ['categorie_id' => $categorieId]))
            ->assertInertia(fn ($page) => $page->has('beneficiaires', 1));

        $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.sites.index', ['categorie_id' => $autreCategorie->id]))
            ->assertInertia(fn ($page) => $page->has('beneficiaires', 0));
    }

    /** @test */
    public function index_nexpose_jamais_les_commissions_dune_autre_organisation(): void
    {
        $this->genererCommissionPourSite($this->site);

        $autreOrg = Organization::factory()->create();
        $autreUser = $this->makeUserWithPermissions($autreOrg, ['comptabilite.read']);
        $siteAutreOrg = Site::factory()->create(['organization_id' => $autreOrg->id]);
        $autreUser->sites()->attach($siteAutreOrg->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($autreUser)
            ->get(route('comptabilite.commissions.sites.index'))
            ->assertInertia(fn ($page) => $page->has('beneficiaires', 0));
    }

    /** @test */
    public function export_excel_contient_les_colonnes_et_le_site(): void
    {
        $this->genererCommissionPourSite($this->site, 200, 5);

        $response = $this->actingAs($this->user)->get(route('comptabilite.commissions.sites.excel'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();
        foreach (['Site', 'Code', 'Type', 'Catégories', 'Généré', 'Brut validé', 'Dépenses', 'Net validé', 'Déjà payé', 'Reste à payer', 'Statut'] as $colonne) {
            $this->assertStringContainsString($colonne, $content);
        }
        $this->assertStringContainsString($this->site->nom, $content);
        $this->assertStringContainsString('1 000', $content);
    }

    /** @test */
    public function export_excel_necessite_la_permission_commissions_exporter(): void
    {
        $sansExport = $this->makeUserWithPermissions($this->org, ['comptabilite.read']);

        $this->actingAs($sansExport)->get(route('comptabilite.commissions.sites.excel'))->assertForbidden();
    }

    /** @test */
    public function export_pdf_retourne_un_pdf(): void
    {
        $this->genererCommissionPourSite($this->site);

        $response = $this->actingAs($this->user)->get(route('comptabilite.commissions.sites.pdf'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    /** @test */
    public function export_pdf_necessite_permission(): void
    {
        $sansExport = $this->makeUserWithPermissions($this->org, ['comptabilite.read']);

        $this->actingAs($sansExport)->get(route('comptabilite.commissions.sites.pdf'))->assertForbidden();
    }

    /**
     * Intégration complète dans le workflow période/validation/paiement existant — jamais un
     * circuit parallèle (cf. mission §6/§9). PeriodeCalculatorService::calculerSites() doit
     * produire une PaiementFiche standard, comme pour les livreurs/propriétaires, avec le SITE
     * comme bénéficiaire direct de la fiche.
     */
    /** @test */
    public function la_commission_site_entre_dans_le_cycle_standard_periode_fiche(): void
    {
        $this->genererCommissionPourSite($this->site, 200, 5);

        $periode = app(PeriodePaiementService::class)->getOrCreatePeriod(
            $this->org->id,
            TypePeriodePaiement::SITE,
            now(),
            $this->user->id,
        );
        $resultat = app(PeriodeCalculatorService::class)->calculer($periode);

        $this->assertSame(1, $resultat['nb_fiches']);
        $this->assertDatabaseHas('paiement_fiches', [
            'periode_id' => $periode->id,
            'beneficiaire_type' => CommissionEnveloppePart::TYPE_SITE,
            'beneficiaire_id' => $this->site->id,
        ]);

        $fiche = PaiementFiche::where('periode_id', $periode->id)->firstOrFail();
        $this->assertEqualsWithDelta(1000.0, (float) $fiche->montant_net, 0.01);
        $this->assertSame($this->site->nom, $fiche->beneficiaire_nom);
    }
}
