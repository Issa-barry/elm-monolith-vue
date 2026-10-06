<?php

namespace Tests\Feature\Ventes;

use App\Enums\StatutCommandeVente;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\TestCase;

/**
 * Page Précommandes (ADR 0019) : compteurs du cycle (En cours / À préparer / En livraison /
 * En retard) à la place des montants, et filtre « En retard » aligné sur CommandeVente::isEnRetard().
 */
class PrecommandeListeIndicateursTest extends TestCase
{
    use HasAdminSetup, RefreshDatabase;

    private const URL = '/backoffice/precommandes';

    private Organization $org;

    private User $user;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->user = $this->makeUserWithPermissions($this->org, ['ventes.read']);
        $this->site = Site::create(['organization_id' => $this->org->id, 'nom' => 'Matoto', 'type' => 'depot', 'localisation' => 'Conakry']);
        $this->user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);
    }

    private function precommande(StatutCommandeVente $statut, array $attributs = []): CommandeVente
    {
        return CommandeVente::factory()->create(array_merge([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'reference' => null,
            'est_precommande' => true,
            'statut' => $statut,
            'date_remise_prevue' => today()->addDays(3),
        ], $attributs));
    }

    private function jeuDeDonnees(): void
    {
        $this->precommande(StatutCommandeVente::RESERVEE, ['date_remise_prevue' => today()->subDays(2)]);
        $this->precommande(StatutCommandeVente::A_PREPARER);
        $this->precommande(StatutCommandeVente::PREPAREE, ['date_remise_prevue' => today()->subDay()]);
        $this->precommande(StatutCommandeVente::LIVRAISON_EN_COURS, ['remise_at' => now()->subDays(3), 'date_remise_prevue' => today()->subDays(4)]);
        $this->precommande(StatutCommandeVente::CLOTUREE, ['remise_at' => now()->subDay()]);
        $this->precommande(StatutCommandeVente::ANNULEE, ['date_remise_prevue' => today()->subDays(5)]);
        // Vente ordinaire : jamais comptée sur la page Précommandes.
        CommandeVente::factory()->create(['organization_id' => $this->org->id, 'site_id' => $this->site->id, 'reference' => null, 'statut' => StatutCommandeVente::LIVRAISON_EN_COURS]);
    }

    public function test_la_page_affiche_les_compteurs_du_cycle_et_aucun_pour_les_ventes(): void
    {
        $this->jeuDeDonnees();

        $this->actingAs($this->user)->get(self::URL)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('commandes', 6)
                ->where('indicateurs_precommandes.en_cours.nombre', 4)
                ->where('indicateurs_precommandes.a_preparer.nombre', 2)
                ->where('indicateurs_precommandes.a_preparer.statuts', ['reservee', 'a_preparer'])
                ->where('indicateurs_precommandes.en_livraison.nombre', 1)
                // Livraison partie (remise faite) ou annulée : plus en retard, même date dépassée.
                ->where('indicateurs_precommandes.en_retard.nombre', 2));

        $this->actingAs($this->user)->get('/backoffice/ventes')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('indicateurs_precommandes', null));
    }

    public function test_les_compteurs_ignorent_le_filtre_statut_pour_rester_des_filtres_rapides(): void
    {
        $this->jeuDeDonnees();

        $this->actingAs($this->user)->get(self::URL.'?statuts[]=livraison_en_cours')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('commandes', 1)
                ->where('indicateurs_precommandes.en_cours.nombre', 4)
                ->where('indicateurs_precommandes.a_preparer.nombre', 2));
    }

    public function test_le_filtre_en_retard_suit_la_regle_du_modele(): void
    {
        $this->jeuDeDonnees();

        $this->actingAs($this->user)->get(self::URL.'?en_retard=1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('commandes', 2)
                ->where('commandes.0.en_retard', true)
                ->where('commandes.1.en_retard', true)
                ->where('filters.en_retard', '1'));
    }

    public function test_la_liste_donne_immatriculation_et_telephones_du_livreur_et_du_client(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id, 'proprietaire_id' => $proprietaire->id, 'nom_vehicule' => 'Abarry', 'immatriculation' => 'AI3462']);
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id, 'nom_complet' => 'Saa Fodé', 'telephone' => '+224613855281']);
        $equipe = EquipeLivraison::create(['organization_id' => $this->org->id, 'vehicule_id' => $vehicule->id, 'nom' => 'Equipe Abarry', 'is_active' => true]);
        EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $livreur->id, 'role' => 'chauffeur', 'ordre' => 0]);
        $client = Client::factory()->create(['organization_id' => $this->org->id, 'telephone' => '+224666177001']);
        $this->precommande(StatutCommandeVente::A_CHARGER, ['vehicule_id' => $vehicule->id, 'client_id' => $client->id]);

        $this->actingAs($this->user)->get(self::URL)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('commandes.0.vehicule_nom', 'Abarry')
                ->where('commandes.0.vehicule_immatriculation', 'AI3462')
                ->where('commandes.0.chauffeur_nom', 'Saa Fodé')
                ->where('commandes.0.chauffeur_telephone', '+224613855281')
                ->where('commandes.0.client_telephone', '+224666177001')
                ->where('commandes.0.precommande_livraison', true));
    }

    public function test_les_compteurs_restent_dans_l_organisation(): void
    {
        $this->jeuDeDonnees();
        $autre = Organization::factory()->create();
        CommandeVente::factory()->create(['organization_id' => $autre->id, 'reference' => null, 'est_precommande' => true, 'statut' => StatutCommandeVente::RESERVEE, 'date_remise_prevue' => today()->subDay()]);

        $this->actingAs($this->user)->get(self::URL)
            ->assertInertia(fn (Assert $page) => $page
                ->where('indicateurs_precommandes.en_cours.nombre', 4)
                ->where('indicateurs_precommandes.en_retard.nombre', 2));
    }
}
