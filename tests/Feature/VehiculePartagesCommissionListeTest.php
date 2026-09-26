<?php

namespace Tests\Feature;

use App\Enums\CommissionMode;
use App\Enums\CommissionRegleStatut;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionUniteCalcul;
use App\Models\Categorie;
use App\Models\CommissionCibleType;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Proprietaire;
use App\Models\Vehicule;
use App\Services\Commission\CommissionProcessusDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Colonne « Partages » de la liste des véhicules : état du partage Livreur par processus
 * (PartageConformiteVehiculesService), même juge que le blocage des commandes (COMM-015).
 */
class VehiculePartagesCommissionListeTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private Categorie $categorie;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['vehicules.read']);
        $this->categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Bouteille', 'statut' => 'actif']);
    }

    private function baremeLivreur(string $processusCode, int $montant): CommissionProcessus
    {
        $processus = CommissionProcessusDefaults::resoudreOuCreer($this->org->id, $processusCode);
        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $processus->id,
            'libelle' => 'Livreur',
            'scope_type' => CommissionScopeType::CATEGORIE->value,
            'scope_id' => $this->categorie->id,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'mode' => CommissionMode::A_REPARTIR->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => $montant,
            'effective_from' => now()->subMonth()->toDateString(),
            'statut' => CommissionRegleStatut::ACTIVE->value,
        ]);

        return $processus;
    }

    private function vehicule(bool $vente, bool $logistique, ?array $partages = null): Vehicule
    {
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => Proprietaire::factory()->create(['organization_id' => $this->org->id])->id,
            'livraison_vente' => $vente,
            'livraison_logistique' => $logistique,
        ]);

        if ($partages !== null) {
            $equipe = EquipeLivraison::create(['organization_id' => $this->org->id, 'vehicule_id' => $vehicule->id, 'is_active' => true]);
            $livreur = Livreur::factory()->create(['organization_id' => $this->org->id, 'is_active' => true]);
            EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $livreur->id, 'role' => 'chauffeur', 'ordre' => 0]);
            foreach ($partages as [$processus, $montant]) {
                EquipeLivraisonPartageCategorie::create([
                    'equipe_id' => $equipe->id,
                    'processus_id' => $processus->id,
                    'categorie_id' => $this->categorie->id,
                    'livreur_id' => $livreur->id,
                    'part_pourcentage' => 0,
                    'montant_unitaire' => $montant,
                    'effective_from' => now()->subMonth()->toDateString(),
                ]);
            }
        }

        return $vehicule;
    }

    public function test_la_liste_expose_letat_de_chaque_partage(): void
    {
        $vente = $this->baremeLivreur(CommissionProcessus::CODE_VENTE, 800);
        $logistique = $this->baremeLivreur(CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT, 500);
        // Transfert grossiste : processus existant mais aucun barème Livreur positif.
        $this->baremeLivreur(CommissionProcessus::CODE_TRANSFERT_GROSSISTE, 0);

        $complet = $this->vehicule(true, true, [[$vente, 800], [$logistique, 500]]);
        $aFaire = $this->vehicule(true, true, [[$vente, 700], [$logistique, 500]]);
        $sansEquipe = $this->vehicule(true, false);

        $this->actingAs($this->user)->get(route('vehicules.index'))
            ->assertInertia(function (AssertableInertia $page) use ($complet, $aFaire, $sansEquipe) {
                $partages = collect($page->toArray()['props']['vehicules'])->pluck('partages_commission', 'id');

                $this->assertSame(['vente' => 'fait', 'logistique_transfert' => 'fait', 'transfert_grossiste' => 'non_requis'], $partages[$complet->id]);
                $this->assertSame(['vente' => 'a_faire', 'logistique_transfert' => 'fait', 'transfert_grossiste' => 'non_requis'], $partages[$aFaire->id]);
                $this->assertSame(['vente' => 'sans_equipe', 'logistique_transfert' => 'non_applicable', 'transfert_grossiste' => 'non_applicable'], $partages[$sansEquipe->id]);
            });
    }
}
