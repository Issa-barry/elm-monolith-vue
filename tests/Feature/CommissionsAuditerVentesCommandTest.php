<?php

namespace Tests\Feature;

use App\Enums\ClientType;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionUniteCalcul;
use App\Enums\NatureOperation;
use App\Enums\StatutCommandeVente;
use App\Models\Categorie;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\Site;
use App\Services\CommandeVenteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCommissionVenteFixtures;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Chantier 2A (05/09/2026, indépendance des cibles) : `commissions:auditer-ventes` ne filtre
 * plus sur `commission_eligible_snapshot = true` — une commande sans véhicule (Externe compris,
 * plus seulement Grossiste + Enlèvement) peut désormais avoir une vraie tentative de génération
 * (SITE/CONSULTANT) à auditer, cf. CommissionsAuditerVentesCommand.
 */
class CommissionsAuditerVentesCommandTest extends TestCase
{
    use HasAdminSetup, HasCommissionVenteFixtures, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Site $site;

    private Categorie $categorie;

    private CommissionProcessus $processus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read', 'ventes.create', 'ventes.update']);

        $this->site = $this->creerSiteDepotTest();
        $this->categorie = $this->creerCategorieBouteilleTest();
        $this->processus = $this->creerProcessusVenteTest('facture_encaissee');
    }

    private function creerCommandeSansVehicule(): CommandeVente
    {
        $client = Client::factory()->create(['organization_id' => $this->org->id, 'type' => ClientType::EXTERNE->value]);
        $produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack Bouteille', 'categorie_id' => $this->categorie->id],
            ['prix_vente' => 2000, 'prix_usine' => 1500],
        );
        $variante = $produit->variantePrincipale()->first();
        $this->seedVarianteStockSuffisant($variante, $this->site);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'vehicule_id' => null,
            'client_id' => $client->id,
            'nature_operation' => NatureOperation::VENTE_STANDARD->value,
            'commission_eligible_snapshot' => false,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 4000,
        ]);
        $commande->lignes()->create([
            'variante_id' => $variante->id,
            'quantite_demandee' => 2,
            'prix_usine_snapshot' => 1500,
            'prix_vente_snapshot' => 2000,
            'total_ligne' => 4000,
        ]);

        return $commande;
    }

    public function test_organisation_sans_aucune_commande_est_coherente(): void
    {
        $this->artisan('commissions:auditer-ventes', ['--organization' => [$this->org->id]])
            ->assertExitCode(0);
    }

    /**
     * Angle mort comblé par le chantier 2A : avant cette révision, une commande sans véhicule
     * était filtrée hors de l'audit (`commission_eligible_snapshot = true` uniquement) — un
     * consultant orphelin y restait donc invisible. Elle doit désormais être détectée comme
     * n'importe quelle autre commande éligible.
     */
    public function test_detecte_une_commande_sans_vehicule_dont_le_consultant_est_orphelin(): void
    {
        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $this->processus->id,
            'libelle' => 'Consultant — catégorie',
            'scope_type' => CommissionScopeType::CATEGORIE->value,
            'scope_id' => $this->categorie->id,
            'cible_type' => CommissionCibleType::CODE_CONSULTANT,
            'mode' => 'direct',
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => 200,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);
        // Aucune désignation créée : le barème consultant est actif mais orphelin.

        $commande = $this->creerCommandeSansVehicule();

        $this->actingAs($this->user);
        CommandeVenteService::creerFactureDirecte($commande);

        $this->artisan('commissions:auditer-ventes', ['--organization' => [$this->org->id]])
            ->assertExitCode(1);
    }

    /** Non-régression : une commande sans véhicule correctement configurée reste cohérente. */
    public function test_commande_sans_vehicule_correctement_configuree_reste_coherente(): void
    {
        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $this->processus->id,
            'libelle' => 'Consultant — catégorie',
            'scope_type' => CommissionScopeType::CATEGORIE->value,
            'scope_id' => $this->categorie->id,
            'cible_type' => CommissionCibleType::CODE_CONSULTANT,
            'mode' => 'direct',
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => 200,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);

        $this->creerConsultantDesigneTest();

        $commande = $this->creerCommandeSansVehicule();

        $this->actingAs($this->user);
        CommandeVenteService::creerFactureDirecte($commande);

        $this->artisan('commissions:auditer-ventes', ['--organization' => [$this->org->id]])
            ->assertExitCode(0);
    }
}
