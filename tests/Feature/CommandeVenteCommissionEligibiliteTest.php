<?php

namespace Tests\Feature;

use App\Models\CommandeVente;
use App\Models\CommissionVente;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use App\Services\CommissionGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * pris_en_charge_par_usine (mode de tarification) et commission_eligible
 * (génération de commission) sont deux notions indépendantes, portées par
 * deux colonnes distinctes sur Vehicule et figées séparément en snapshot sur
 * CommandeVente à sa création — voir VehiculeCommandeContextResolver et
 * CommissionGenerator. Ce test couvre les 4 combinaisons possibles et
 * l'immutabilité des snapshots.
 */
class CommandeVenteCommissionEligibiliteTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Site $defaultSite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read', 'ventes.create', 'ventes.update']);

        $this->defaultSite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Principal',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->defaultSite->id, ['role' => 'employe', 'is_default' => true]);
    }

    private function makeProduit(int $prixVente = 5000, int $prixUsine = 3500): Produit
    {
        return $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack Eau'],
            ['prix_vente' => $prixVente, 'prix_usine' => $prixUsine],
        );
    }

    private function makeVehicule(bool $prisEnChargeParUsine, bool $commissionEligible, int $capacite = 100): Vehicule
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        return Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'capacite_packs' => $capacite,
            'pris_en_charge_par_usine' => $prisEnChargeParUsine,
            'commission_eligible' => $commissionEligible,
        ]);
    }

    /** Équipe à taux 100% (chauffeur + convoyeur + propriétaire), nécessaire pour que CommissionGenerator ne rejette pas la commande. */
    private function attacherEquipe(Vehicule $vehicule, float $tauxChauffeur = 20.0, float $tauxConvoyeur = 10.0): void
    {
        $chauffeur = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $convoyeur = Livreur::factory()->create(['organization_id' => $this->org->id]);

        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Équipe Test',
            'is_active' => true,
            'taux_commission_proprietaire' => round(100 - $tauxChauffeur - $tauxConvoyeur, 2),
        ]);
        EquipeLivreur::create([
            'equipe_id' => $equipe->id,
            'livreur_id' => $chauffeur->id,
            'taux_commission' => $tauxChauffeur,
            'role' => 'chauffeur',
            'ordre' => 0,
        ]);
        EquipeLivreur::create([
            'equipe_id' => $equipe->id,
            'livreur_id' => $convoyeur->id,
            'taux_commission' => $tauxConvoyeur,
            'role' => 'convoyeur',
            'ordre' => 1,
        ]);
    }

    private function creerCommande(Vehicule $vehicule, Produit $produit): CommandeVente
    {
        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 100, 'prix_vente' => 5000],
                ],
            ])
            ->assertRedirect();

        return CommandeVente::where('vehicule_id', $vehicule->id)->latest()->first();
    }

    // ── Les 4 combinaisons ────────────────────────────────────────────────────
    // CommissionCalculator base son calcul sur prix_vente_snapshot -
    // prix_usine_snapshot (marge), indépendamment du mode de tarification
    // effectivement encaissé : (5000-3500) × 100 = 150 000.

    #[DataProvider('combinaisonsProvider')]
    public function test_les_4_combinaisons_pris_en_charge_et_commission_eligible(
        bool $prisEnChargeParUsine,
        bool $commissionEligible,
        string $modeTarificationAttendu,
    ): void {
        $produit = $this->makeProduit();
        $vehicule = $this->makeVehicule($prisEnChargeParUsine, $commissionEligible);
        $this->attacherEquipe($vehicule);

        $commande = $this->creerCommande($vehicule, $produit);

        $this->assertSame($modeTarificationAttendu, $commande->mode_tarification_snapshot->value);
        $this->assertSame($commissionEligible, (bool) $commande->commission_eligible_snapshot);

        if ($commissionEligible) {
            $this->assertDatabaseHas('commissions_ventes', [
                'commande_vente_id' => $commande->id,
                'montant_commission_totale' => 150_000,
            ]);
        } else {
            $this->assertDatabaseMissing('commissions_ventes', ['commande_vente_id' => $commande->id]);
        }
    }

    public static function combinaisonsProvider(): array
    {
        return [
            'pris en charge + éligible' => [true, true, 'prix_vente'],
            'pris en charge + non éligible' => [true, false, 'prix_vente'],
            'non pris en charge + éligible' => [false, true, 'prix_usine'],
            'non pris en charge + non éligible' => [false, false, 'prix_usine'],
        ];
    }

    // ── Immutabilité du snapshot ──────────────────────────────────────────────

    public function test_commission_eligible_snapshot_ne_change_pas_retroactivement_si_le_vehicule_change(): void
    {
        $produit = $this->makeProduit();
        $vehicule = $this->makeVehicule(prisEnChargeParUsine: true, commissionEligible: false);
        $this->attacherEquipe($vehicule);

        $commande = $this->creerCommande($vehicule, $produit);
        $this->assertFalse((bool) $commande->commission_eligible_snapshot);
        $this->assertDatabaseMissing('commissions_ventes', ['commande_vente_id' => $commande->id]);

        // Le véhicule devient éligible aux commissions après coup.
        $vehicule->update(['commission_eligible' => true]);

        // Une commande déjà créée ne doit jamais être recalculée à partir de la
        // valeur courante du véhicule — seul le snapshot fait foi.
        CommissionGenerator::generateForCommandeIfMissing($commande->fresh());

        $this->assertDatabaseMissing('commissions_ventes', ['commande_vente_id' => $commande->id]);
        $this->assertFalse((bool) $commande->fresh()->commission_eligible_snapshot);
    }

    public function test_commission_vente_non_generee_meme_si_vehicule_redevient_eligible_apres_coup(): void
    {
        $produit = $this->makeProduit();
        $vehicule = $this->makeVehicule(prisEnChargeParUsine: true, commissionEligible: true);
        $this->attacherEquipe($vehicule);

        $commande = $this->creerCommande($vehicule, $produit);
        $this->assertDatabaseHas('commissions_ventes', ['commande_vente_id' => $commande->id]);

        // Le véhicule devient inéligible après coup : la commission déjà
        // générée n'est jamais supprimée rétroactivement (aucun mécanisme ne
        // le fait, et ce n'est pas le rôle de CommissionGenerator, idempotent
        // par nature).
        $vehicule->update(['commission_eligible' => false]);
        CommissionGenerator::generateForCommandeIfMissing($commande->fresh());

        $this->assertEquals(1, CommissionVente::where('commande_vente_id', $commande->id)->count());
    }
}
