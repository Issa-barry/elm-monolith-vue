<?php

namespace Tests\Feature;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionMode;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionStrategieAncrageSite;
use App\Enums\CommissionUniteCalcul;
use App\Enums\DeclencheurCommissionVente;
use App\Enums\PrestataireType;
use App\Enums\StatutCommandeVente;
use App\Models\Categorie;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionConsultantAffectation;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionGenerationAttempt;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Parametre;
use App\Models\Personne;
use App\Models\Prestataire;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use App\Services\CommandeVenteService;
use App\Services\Commission\CommissionEnveloppeGenerator;
use App\Services\Commission\CommissionProcessusDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Non-régression dédiée à l'incident CMD-230826-004 : une équipe de 5
 * livreurs partageant l'enveloppe Livreur à 100/100/150/150/450 (jamais un
 * pourcentage, cf. CommissionPartageLivraisonValidator) sur une commande de
 * 3 400 unités doit générer les 4 enveloppes attendues, sans tolérance et
 * sans double génération — reproduit fidèlement les montants réels de
 * l'incident (Livreur 3 230 000 / Propriétaire 2 720 000 / Site 680 000 /
 * Consultant 170 000, total 6 800 000 GNF).
 */
class CommissionCmd230826004NonRegressionTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Site $defaultSite;

    private CommissionProcessus $processus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read', 'ventes.create', 'ventes.update']);

        // Déclencheur explicite : le défaut organisation est FACTURE_ENCAISSEE depuis
        // le 18/08/2026 (cf. Parametre::getDeclencheurCommissionVente()), ce test
        // exerce le chemin CHARGEMENT_VALIDE (comme l'incident réel).
        Parametre::setDeclencheurCommissionVente($this->org->id, DeclencheurCommissionVente::CHARGEMENT_VALIDE);

        $this->defaultSite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Siège de Matoto',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->defaultSite->id, ['role' => 'employe', 'is_default' => true]);

        $this->processus = CommissionProcessus::create([
            'organization_id' => $this->org->id,
            'code' => CommissionProcessus::CODE_VENTE,
            'libelle' => 'Vente',
            'declencheur' => 'chargement_valide',
            'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
            'statut' => CommissionActivationStatut::ACTIF->value,
        ]);
    }

    private function creerRegle(string $cibleType, float $montant, ?string $consultantId = null): CommissionRegle
    {
        return CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $this->processus->id,
            'libelle' => "Règle {$cibleType}",
            'scope_type' => CommissionScopeType::GLOBAL->value,
            'cible_type' => $cibleType,
            'mode' => $cibleType === CommissionCibleType::CODE_EQUIPE_LIVRAISON
                ? CommissionMode::A_REPARTIR->value
                : CommissionMode::DIRECT->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => $montant,
            'consultant_id' => $consultantId,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);
    }

    private function designerConsultant(): void
    {
        $personne = Personne::create([
            'organization_id' => $this->org->id,
            'nom' => 'Diallo',
            'prenom' => 'Abdoulaye',
        ]);
        $consultant = Prestataire::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'type' => PrestataireType::CONSULTANT->value,
            'is_active' => true,
        ]);

        CommissionConsultantAffectation::create([
            'organization_id' => $this->org->id,
            'prestataire_id' => $consultant->id,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);
    }

    /** Équipe "Cousin" : 5 livreurs, montants fixes 100/100/150/150/450 = 950 GNF/unité. */
    private function makeVehiculeAvecEquipeCousin(Categorie $categorie): Vehicule
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'capacite_packs' => 5000,
        ]);

        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Cousin',
            'is_active' => true,
        ]);

        $montants = [100, 100, 150, 150, 450];
        foreach ($montants as $i => $montant) {
            $livreur = Livreur::factory()->create(['organization_id' => $this->org->id]);
            EquipeLivreur::create([
                'equipe_id' => $equipe->id,
                'livreur_id' => $livreur->id,
                'role' => $i === 0 ? 'chauffeur' : 'convoyeur',
                'ordre' => $i,
            ]);
            EquipeLivraisonPartageCategorie::create([
                'equipe_id' => $equipe->id,
                'categorie_id' => $categorie->id,
                'processus_id' => CommissionProcessusDefaults::resoudreOuCreer($this->org->id, CommissionProcessus::CODE_VENTE)->id,
                'livreur_id' => $livreur->id,
                'part_pourcentage' => 0,
                'montant_unitaire' => $montant,
                'effective_from' => now()->subDay(),
            ]);
        }

        return $vehicule->fresh();
    }

    private function makeProduit(Categorie $categorie): Produit
    {
        return $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => "Bouteille d'eau", 'categorie_id' => $categorie->id],
            ['prix_vente' => 5000, 'prix_usine' => 3500],
        );
    }

    /** @test */
    public function reproduit_lincident_cmd_230826_004_avec_les_montants_exacts(): void
    {
        $categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => "Bouteille d'eau", 'statut' => 'actif']);

        // Barèmes GNF/unité : Propriétaire 800 + Livreur 950 + Site 200 + Consultant 50
        // = 2000/unité × 3400 = 6 800 000 GNF, exactement le total de l'incident réel.
        $this->creerRegle(CommissionCibleType::CODE_PROPRIETAIRE, 800);
        $this->creerRegle(CommissionCibleType::CODE_EQUIPE_LIVRAISON, 950);
        $this->creerRegle(CommissionCibleType::CODE_SITE, 200);
        $this->designerConsultant();
        $this->creerRegle(CommissionCibleType::CODE_CONSULTANT, 50);

        $vehicule = $this->makeVehiculeAvecEquipeCousin($categorie);
        $produit = $this->makeProduit($categorie);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 3400 * 5000,
        ]);
        $variante = $produit->variantePrincipale()->first();
        $ligne = $commande->lignes()->create([
            'variante_id' => $variante->id,
            'quantite_demandee' => 3400,
            'prix_usine_snapshot' => (float) $variante->prix_usine,
            'prix_vente_snapshot' => (float) $variante->prix_vente,
            'total_ligne' => 3400 * (float) $variante->prix_vente,
        ]);

        $this->seedVarianteStockSuffisant($variante, $this->defaultSite);

        $this->actingAs($this->user);
        CommandeVenteService::confirmer($commande);
        CommandeVenteService::demarrerChargement($commande);
        CommandeVenteService::validerChargement($commande, [[
            'id' => $ligne->id,
            'quantite_chargee' => 3400,
            'type_ecart' => 'conforme',
        ]]);

        $commande = $commande->fresh();

        // Une seule tentative, en succès — jamais de double génération pour un seul
        // déclenchement (cf. incident : 2 tentatives ERREUR à 1s d'écart).
        $tentatives = CommissionGenerationAttempt::where('source_id', $commande->id)->get();
        $this->assertCount(1, $tentatives);
        $this->assertEquals('succes', $tentatives->first()->statut->value);

        $enveloppes = CommissionEnveloppe::where('source_id', $commande->id)->get()->keyBy('cible_type');
        $this->assertCount(4, $enveloppes, 'Les 4 cibles (Propriétaire, Équipe, Site, Consultant) doivent être générées.');

        $this->assertSame(2_720_000.0, (float) $enveloppes['proprietaire']->montant_total);
        $this->assertSame(3_230_000.0, (float) $enveloppes['equipe_livraison']->montant_total);
        $this->assertSame(680_000.0, (float) $enveloppes['site']->montant_total);
        $this->assertSame(170_000.0, (float) $enveloppes['consultant']->montant_total);

        $totalGeneral = (float) $enveloppes->sum('montant_total');
        $this->assertSame(6_800_000.0, $totalGeneral);

        // Les 5 parts Livreur somment exactement l'enveloppe équipe, aucune tolérance.
        $partsLivreur = CommissionEnveloppePart::where('enveloppe_id', $enveloppes['equipe_livraison']->id)->get();
        $this->assertCount(5, $partsLivreur);
        $this->assertSame(3_230_000.0, (float) $partsLivreur->sum('montant_brut'));

        // Idempotence : rejouer la génération (ex: relance manuelle après un cas
        // similaire) ne doit jamais dupliquer les enveloppes déjà créées.
        CommissionEnveloppeGenerator::genererPourCommandeVente($commande);
        $this->assertCount(4, CommissionEnveloppe::where('source_id', $commande->id)->get());
        $this->assertCount(1, CommissionGenerationAttempt::where('source_id', $commande->id)->get());
    }
}
