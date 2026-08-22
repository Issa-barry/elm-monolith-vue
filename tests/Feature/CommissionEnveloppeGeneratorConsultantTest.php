<?php

namespace Tests\Feature;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionStrategieAncrageSite;
use App\Enums\CommissionUniteCalcul;
use App\Enums\PrestataireType;
use App\Enums\StatutCommandeVente;
use App\Models\Categorie;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionConsultantAffectation;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Personne;
use App\Models\Prestataire;
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
 * Cible directe "consultant" (CommissionCibleType::CODE_CONSULTANT) — bénéficiaire =
 * App\Models\Prestataire désigné par l'organisation via CommissionConsultantAffectation, JAMAIS
 * un prestataire codé en dur. Contrairement à Propriétaire/Site, cette cible ne dépend d'aucune
 * donnée de la commande : elle est toujours candidate (cf. CommissionEnveloppeGenerator), et un
 * barème configuré sans désignation active bloque toute la génération (décision AMOA #4 —
 * explicite et traçable, jamais silencieux).
 */
class CommissionEnveloppeGeneratorConsultantTest extends TestCase
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

    private function creerConsultant(bool $actif = true): Prestataire
    {
        $personne = Personne::create([
            'organization_id' => $this->org->id,
            'nom' => 'Diallo',
            'prenom' => 'Abdoulaye',
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
        return CommissionConsultantAffectation::create([
            'organization_id' => $this->org->id,
            'prestataire_id' => $prestataire->id,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);
    }

    /** @return array{vehicule: Vehicule} véhicule minimal requis par le moteur, sans lien avec la cible consultant. */
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
    public function une_vente_genere_lenveloppe_consultant_attribuee_au_prestataire_designe(): void
    {
        $categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachets', 'statut' => 'actif']);
        $this->creerRegle(CommissionCibleType::CODE_CONSULTANT, 200, CommissionScopeType::CATEGORIE, $categorie->id);
        $consultant = $this->creerConsultant();
        $this->designerConsultant($consultant);

        ['vehicule' => $vehicule] = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit($categorie->id);
        $commande = $this->creerCommandeAvecLignes($vehicule, $this->site, [[$produit, 5]]);

        CommissionEnveloppeGenerator::genererPourCommandeVente($commande);

        $enveloppe = CommissionEnveloppe::where('source_id', $commande->id)
            ->where('cible_type', CommissionCibleType::CODE_CONSULTANT)->firstOrFail();
        $this->assertSame(1000.0, (float) $enveloppe->montant_total); // 200 × 5
        $this->assertSame($consultant->id, $enveloppe->cible_id);

        $part = CommissionEnveloppePart::where('enveloppe_id', $enveloppe->id)->firstOrFail();
        $this->assertSame(CommissionEnveloppePart::TYPE_PRESTATAIRE, $part->beneficiaire_type);
        $this->assertSame($consultant->id, $part->beneficiaire_id);
        $this->assertEqualsWithDelta(1000.0, (float) $part->montant_brut, 0.01);
    }

    /** @test */
    public function aucun_bareme_consultant_configure_ne_genere_aucune_enveloppe_sans_erreur(): void
    {
        // Aucune CommissionRegle cible=consultant, aucune désignation — comportement par défaut
        // pour toute organisation existante : rien à faire, jamais une erreur (non régressif).
        $categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachets', 'statut' => 'actif']);
        $this->creerRegle(CommissionCibleType::CODE_PROPRIETAIRE, 600, CommissionScopeType::CATEGORIE, $categorie->id);

        ['vehicule' => $vehicule] = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit($categorie->id);
        $commande = $this->creerCommandeAvecLignes($vehicule, $this->site, [[$produit, 3]]);

        CommissionEnveloppeGenerator::genererPourCommandeVente($commande);

        $this->assertDatabaseHas('commission_enveloppes', [
            'source_id' => $commande->id, 'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
        ]);
        $this->assertDatabaseMissing('commission_enveloppes', [
            'source_id' => $commande->id, 'cible_type' => CommissionCibleType::CODE_CONSULTANT,
        ]);
    }

    /** @test */
    public function un_bareme_configure_sans_consultant_designe_bloque_toute_la_generation(): void
    {
        $categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachets', 'statut' => 'actif']);
        $this->creerRegle(CommissionCibleType::CODE_CONSULTANT, 200, CommissionScopeType::CATEGORIE, $categorie->id);
        $this->creerRegle(CommissionCibleType::CODE_PROPRIETAIRE, 600, CommissionScopeType::CATEGORIE, $categorie->id);
        // Aucune désignation créée : le barème consultant est actif mais orphelin.

        ['vehicule' => $vehicule] = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit($categorie->id);
        $commande = $this->creerCommandeAvecLignes($vehicule, $this->site, [[$produit, 3]]);

        CommissionEnveloppeGenerator::genererPourCommandeVente($commande);

        // Tout-ou-rien (décision AMOA #4, comme "véhicule sans propriétaire") : même le
        // propriétaire, pourtant correctement configuré, ne doit recevoir aucune enveloppe.
        $this->assertDatabaseMissing('commission_enveloppes', ['source_id' => $commande->id]);
        $this->assertDatabaseHas('commission_generation_attempts', [
            'source_id' => $commande->id,
            'statut' => 'erreur',
        ]);
    }

    /** @test */
    public function un_consultant_desactive_najamais_de_commission_meme_encore_designe(): void
    {
        $categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachets', 'statut' => 'actif']);
        $this->creerRegle(CommissionCibleType::CODE_CONSULTANT, 200, CommissionScopeType::CATEGORIE, $categorie->id);
        $consultant = $this->creerConsultant(actif: false);
        $this->designerConsultant($consultant);

        ['vehicule' => $vehicule] = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit($categorie->id);
        $commande = $this->creerCommandeAvecLignes($vehicule, $this->site, [[$produit, 3]]);

        CommissionEnveloppeGenerator::genererPourCommandeVente($commande);

        $this->assertDatabaseMissing('commission_enveloppes', ['source_id' => $commande->id]);
    }

    /** @test */
    public function remplacer_le_consultant_designe_naffecte_jamais_les_commissions_deja_generees(): void
    {
        $categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachets', 'statut' => 'actif']);
        $this->creerRegle(CommissionCibleType::CODE_CONSULTANT, 200, CommissionScopeType::CATEGORIE, $categorie->id);
        $ancienConsultant = $this->creerConsultant();
        $this->designerConsultant($ancienConsultant);

        ['vehicule' => $vehicule] = $this->makeVehiculeAvecEquipe();
        $produit = $this->makeProduit($categorie->id);
        $commande = $this->creerCommandeAvecLignes($vehicule, $this->site, [[$produit, 2]]);

        CommissionEnveloppeGenerator::genererPourCommandeVente($commande);
        $part = CommissionEnveloppePart::whereHas(
            'enveloppe', fn ($q) => $q->where('source_id', $commande->id)->where('cible_type', CommissionCibleType::CODE_CONSULTANT)
        )->firstOrFail();

        // Remplacement — mêmes conventions de versionnement que CommissionRegle.
        $nouveauConsultant = $this->creerConsultant();
        $ancienneAffectation = CommissionConsultantAffectation::actifPour($this->org->id);
        $ancienneAffectation->update(['statut' => 'remplacee', 'effective_to' => now()->toDateString()]);
        $this->designerConsultant($nouveauConsultant);

        $part->refresh();
        $this->assertSame($ancienConsultant->id, $part->beneficiaire_id);
        $this->assertNotEquals($nouveauConsultant->id, $part->beneficiaire_id);
    }
}
