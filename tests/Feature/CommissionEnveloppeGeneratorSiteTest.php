<?php

namespace Tests\Feature;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionStrategieAncrageSite;
use App\Enums\CommissionUniteCalcul;
use App\Enums\StatutCommandeVente;
use App\Models\Categorie;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use App\Services\CommandeVenteService;
use App\Services\Commission\CommissionEnveloppeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Cible directe "site" (CommissionCibleType::CODE_SITE) — bénéficiaire = App\Models\Site
 * directement, jamais un gérant/employé/équipe. Remplace définitivement l'ancienne cible
 * collective "gérants dépôt" (décision produit 2026-08-21) : aucune notion d'éligibilité RH,
 * de fonction, de rôle ou d'affectation n'intervient. Mode DIRECT, mirroring exact des
 * scénarios propriétaire de CommissionEnveloppeGeneratorReglesTest — un seul bénéficiaire
 * déterministe, jamais de répartition à calculer.
 */
class CommissionEnveloppeGeneratorSiteTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Site $site;

    private CommissionProcessus $processus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read', 'ventes.create', 'ventes.update']);

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

    private function creerRegle(string $cibleType, float $montant, CommissionScopeType $scope = CommissionScopeType::GLOBAL, ?string $scopeId = null): CommissionRegle
    {
        return CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $this->processus->id,
            'libelle' => "Règle {$cibleType}",
            'scope_type' => $scope->value,
            'scope_id' => $scopeId,
            'cible_type' => $cibleType,
            'mode' => 'direct',
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => $montant,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);
    }

    /** @return array{vehicule: Vehicule} véhicule minimal requis par le moteur, sans lien avec le site testé. */
    private function makeVehiculeAvecEquipe(): array
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'capacite_packs' => 100,
        ]);
        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Équipe Test',
            'is_active' => true,
        ]);
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id]);
        EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $livreur->id, 'role' => 'chauffeur', 'ordre' => 0]);

        return ['vehicule' => $vehicule->fresh()];
    }

    private function makeProduit(?string $categorieId = null): Produit
    {
        return $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Produit '.uniqid(), 'categorie_id' => $categorieId],
            ['prix_vente' => 2000, 'prix_usine' => 1500],
        );
    }

    private function creerCommandeAvecLignes(Vehicule $vehicule, Site $site, array $produitsEtQuantites): CommandeVente
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $vehicule->organization_id,
            'site_id' => $site->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 4000,
        ]);

        $lignesData = [];
        foreach ($produitsEtQuantites as [$produit, $quantite]) {
            $variante = $produit->variantePrincipale()->first();
            $ligne = $commande->lignes()->create([
                'variante_id' => $variante->id,
                'quantite_demandee' => $quantite,
                'prix_usine_snapshot' => (float) $variante->prix_usine,
                'prix_vente_snapshot' => (float) $variante->prix_vente,
                'total_ligne' => $quantite * (float) $variante->prix_vente,
            ]);
            $lignesData[] = ['id' => $ligne->id, 'quantite_chargee' => $quantite, 'type_ecart' => 'conforme'];
        }

        $this->actingAs($this->user);
        CommandeVenteService::confirmer($commande);
        CommandeVenteService::demarrerChargement($commande);
        CommandeVenteService::validerChargement($commande, $lignesData);

        return $commande->fresh();
    }

    /** @test */
    public function une_vente_genere_lenveloppe_site_attribuee_directement_au_site(): void
    {
        $categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachets', 'statut' => 'actif']);
        $this->creerRegle(CommissionCibleType::CODE_SITE, 200, CommissionScopeType::CATEGORIE, $categorie->id);

        ['vehicule' => $vehicule] = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit($categorie->id);
        $commande = $this->creerCommandeAvecLignes($vehicule, $this->site, [[$produit, 5]]);

        CommissionEnveloppeGenerator::genererPourCommandeVente($commande);

        $enveloppe = CommissionEnveloppe::where('source_id', $commande->id)
            ->where('cible_type', CommissionCibleType::CODE_SITE)->firstOrFail();
        $this->assertSame(1000.0, (float) $enveloppe->montant_total); // 200 × 5
        $this->assertSame($this->site->id, $enveloppe->cible_id);

        $parts = CommissionEnveloppePart::where('enveloppe_id', $enveloppe->id)->get();
        $this->assertCount(1, $parts);
        $part = $parts->first();
        $this->assertSame(CommissionEnveloppePart::TYPE_SITE, $part->beneficiaire_type);
        $this->assertSame($this->site->id, $part->beneficiaire_id);
        $this->assertEqualsWithDelta(1000.0, (float) $part->montant_brut, 0.01);
    }

    /** @test */
    public function fonctionne_sur_tout_type_de_site_pas_seulement_un_depot(): void
    {
        $categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachets', 'statut' => 'actif']);
        $this->creerRegle(CommissionCibleType::CODE_SITE, 200, CommissionScopeType::CATEGORIE, $categorie->id);

        $siege = Site::create(['organization_id' => $this->org->id, 'nom' => 'Siège', 'type' => 'siege', 'localisation' => 'Conakry']);
        $this->user->sites()->attach($siege->id, ['role' => 'employe', 'is_default' => false]);

        ['vehicule' => $vehicule] = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit($categorie->id);
        $commande = $this->creerCommandeAvecLignes($vehicule, $siege, [[$produit, 5]]);

        CommissionEnveloppeGenerator::genererPourCommandeVente($commande);

        $enveloppe = CommissionEnveloppe::where('source_id', $commande->id)
            ->where('cible_type', CommissionCibleType::CODE_SITE)->first();
        $this->assertNotNull($enveloppe, 'un site non-dépôt doit lui aussi recevoir la commission site');
        $this->assertSame($siege->id, $enveloppe->cible_id);
    }

    /** @test */
    public function labsence_de_gerant_ou_demploye_ne_bloque_jamais_la_generation(): void
    {
        // Aucun employé, aucune affectation, aucun compte utilisateur créé nulle part dans ce
        // test — la génération doit réussir intégralement quand même (décision produit
        // 2026-08-21 : la commission site est totalement indépendante des employés/fonctions/rôles).
        $categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachets', 'statut' => 'actif']);
        $this->creerRegle(CommissionCibleType::CODE_SITE, 200, CommissionScopeType::CATEGORIE, $categorie->id);
        $this->creerRegle(CommissionCibleType::CODE_PROPRIETAIRE, 600, CommissionScopeType::CATEGORIE, $categorie->id);

        ['vehicule' => $vehicule] = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit($categorie->id);
        $commande = $this->creerCommandeAvecLignes($vehicule, $this->site, [[$produit, 3]]);

        CommissionEnveloppeGenerator::genererPourCommandeVente($commande);

        $this->assertDatabaseHas('commission_enveloppes', [
            'source_id' => $commande->id, 'cible_type' => CommissionCibleType::CODE_SITE,
        ]);
        $this->assertDatabaseHas('commission_enveloppes', [
            'source_id' => $commande->id, 'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
        ]);
    }

    /** @test */
    public function bareme_site_a_zero_ne_genere_aucune_enveloppe_sans_erreur(): void
    {
        $categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachets', 'statut' => 'actif']);
        $this->creerRegle(CommissionCibleType::CODE_SITE, 0, CommissionScopeType::CATEGORIE, $categorie->id);

        ['vehicule' => $vehicule] = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit($categorie->id);
        $commande = $this->creerCommandeAvecLignes($vehicule, $this->site, [[$produit, 3]]);

        CommissionEnveloppeGenerator::genererPourCommandeVente($commande);

        $enveloppe = CommissionEnveloppe::where('source_id', $commande->id)
            ->where('cible_type', CommissionCibleType::CODE_SITE)->first();
        // Comportement DIRECT (comme propriétaire) : une enveloppe à 0 GNF est créée quand même,
        // jamais une erreur — le barème à 0 est une valeur métier valide.
        $this->assertNotNull($enveloppe);
        $this->assertSame(0.0, (float) $enveloppe->montant_total);
    }

    /** @test */
    public function deux_operations_sur_deux_sites_distincts_attribuent_chacune_au_bon_site(): void
    {
        $categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachets', 'statut' => 'actif']);
        $this->creerRegle(CommissionCibleType::CODE_SITE, 200, CommissionScopeType::CATEGORIE, $categorie->id);

        $autreSite = Site::create(['organization_id' => $this->org->id, 'nom' => 'Dépôt Kaloum', 'type' => 'depot', 'localisation' => 'Conakry']);
        $this->user->sites()->attach($autreSite->id, ['role' => 'employe', 'is_default' => false]);

        ['vehicule' => $v1] = $this->makeVehiculeAvecEquipe();
        ['vehicule' => $v2] = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit($categorie->id);

        $commande1 = $this->creerCommandeAvecLignes($v1, $this->site, [[$produit, 2]]);
        $commande2 = $this->creerCommandeAvecLignes($v2, $autreSite, [[$produit, 3]]);

        CommissionEnveloppeGenerator::genererPourCommandeVente($commande1);
        CommissionEnveloppeGenerator::genererPourCommandeVente($commande2);

        $part1 = CommissionEnveloppePart::whereHas('enveloppe', fn ($q) => $q->where('source_id', $commande1->id))->firstOrFail();
        $part2 = CommissionEnveloppePart::whereHas('enveloppe', fn ($q) => $q->where('source_id', $commande2->id))->firstOrFail();

        $this->assertSame($this->site->id, $part1->beneficiaire_id);
        $this->assertEqualsWithDelta(400.0, (float) $part1->montant_brut, 0.01);
        $this->assertSame($autreSite->id, $part2->beneficiaire_id);
        $this->assertEqualsWithDelta(600.0, (float) $part2->montant_brut, 0.01);
    }

    /** @test */
    public function un_site_dune_autre_organisation_najamais_de_part(): void
    {
        $autreOrg = Organization::factory()->create();
        $siteAutreOrg = Site::factory()->create(['organization_id' => $autreOrg->id]);

        $categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachets', 'statut' => 'actif']);
        $this->creerRegle(CommissionCibleType::CODE_SITE, 200, CommissionScopeType::CATEGORIE, $categorie->id);

        ['vehicule' => $vehicule] = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit($categorie->id);
        $commande = $this->creerCommandeAvecLignes($vehicule, $this->site, [[$produit, 2]]);

        CommissionEnveloppeGenerator::genererPourCommandeVente($commande);

        $part = CommissionEnveloppePart::whereHas('enveloppe', fn ($q) => $q->where('source_id', $commande->id))->firstOrFail();
        $this->assertNotEquals($siteAutreOrg->id, $part->beneficiaire_id);
        $this->assertSame($this->site->id, $part->beneficiaire_id);
    }

    /** @test */
    public function operation_multi_categories_agrege_les_contributions_sur_une_seule_enveloppe(): void
    {
        $cat1 = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachets', 'statut' => 'actif']);
        $cat2 = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Bidons', 'statut' => 'actif']);
        $this->creerRegle(CommissionCibleType::CODE_SITE, 200, CommissionScopeType::CATEGORIE, $cat1->id);
        $this->creerRegle(CommissionCibleType::CODE_SITE, 300, CommissionScopeType::CATEGORIE, $cat2->id);

        ['vehicule' => $vehicule] = $this->makeVehiculeAvecEquipe();
        $produit1 = $this->makeProduit($cat1->id);
        $produit2 = $this->makeProduit($cat2->id);
        $commande = $this->creerCommandeAvecLignes($vehicule, $this->site, [[$produit1, 2], [$produit2, 3]]);

        CommissionEnveloppeGenerator::genererPourCommandeVente($commande);

        // Une seule enveloppe cible=site pour l'opération entière, jamais une par catégorie.
        $this->assertEquals(1, CommissionEnveloppe::where('source_id', $commande->id)
            ->where('cible_type', CommissionCibleType::CODE_SITE)->count());

        $enveloppe = CommissionEnveloppe::where('source_id', $commande->id)
            ->where('cible_type', CommissionCibleType::CODE_SITE)->firstOrFail();
        // 200×2 + 300×3 = 1300
        $this->assertEqualsWithDelta(1300.0, (float) $enveloppe->montant_total, 0.01);
        $this->assertEquals(2, $enveloppe->lignes()->count());

        $parts = CommissionEnveloppePart::where('enveloppe_id', $enveloppe->id)->get();
        $this->assertCount(1, $parts, 'un seul bénéficiaire : le site, jamais une part par catégorie');
    }

    /** @test */
    public function la_generation_est_idempotente_et_ne_duplique_jamais_lenveloppe(): void
    {
        $categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachets', 'statut' => 'actif']);
        $this->creerRegle(CommissionCibleType::CODE_SITE, 200, CommissionScopeType::CATEGORIE, $categorie->id);

        ['vehicule' => $vehicule] = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit($categorie->id);
        $commande = $this->creerCommandeAvecLignes($vehicule, $this->site, [[$produit, 2]]);

        CommissionEnveloppeGenerator::genererPourCommandeVente($commande);
        CommissionEnveloppeGenerator::genererPourCommandeVente($commande);

        $this->assertEquals(1, CommissionEnveloppe::where('source_id', $commande->id)
            ->where('cible_type', CommissionCibleType::CODE_SITE)->count());
    }

    /** @test */
    public function le_changement_ulterieur_du_site_ne_reecrit_jamais_les_commissions_deja_generees(): void
    {
        $categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachets', 'statut' => 'actif']);
        $this->creerRegle(CommissionCibleType::CODE_SITE, 200, CommissionScopeType::CATEGORIE, $categorie->id);

        ['vehicule' => $vehicule] = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit($categorie->id);
        $commande = $this->creerCommandeAvecLignes($vehicule, $this->site, [[$produit, 2]]);

        CommissionEnveloppeGenerator::genererPourCommandeVente($commande);
        $part = CommissionEnveloppePart::whereHas('enveloppe', fn ($q) => $q->where('source_id', $commande->id))->firstOrFail();
        $montantAvant = (float) $part->montant_brut;

        $this->site->update(['nom' => 'Dépôt Renommé']);

        $part->refresh();
        $this->assertEqualsWithDelta($montantAvant, (float) $part->montant_brut, 0.01);
        $this->assertSame($this->site->id, $part->beneficiaire_id);
    }
}
