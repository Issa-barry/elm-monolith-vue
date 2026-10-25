<?php

namespace Tests\Feature\Ventes;

use App\Enums\StatutCommandeVente;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\EquipeLivraison;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Barre rapide de la liste Ventes : recherche `recherche` côté serveur, combinable avec les
 * filtres rapides (N° commande, Livreur, Véhicule) et ceux du panneau, sans élargir le périmètre.
 */
class VenteBarreRapideTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read', 'ventes.update']);

        $this->site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Agence Barre',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);
        $this->actingAs($this->user);
    }

    private function commande(string $reference, array $overrides = []): CommandeVente
    {
        return CommandeVente::create(array_merge([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 5000,
            'reference' => $reference,
            'numero' => random_int(1, 999999),
        ], $overrides));
    }

    private function vehicule(string $nom, string $immatriculation): Vehicule
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        return Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'nom_vehicule' => $nom,
            'immatriculation' => $immatriculation,
        ]);
    }

    private function vehiculeAvecLivreur(string $nom, string $prenom): Vehicule
    {
        $vehicule = $this->vehicule('Camion '.$nom, 'RC-'.random_int(1000, 9999));
        $livreur = Livreur::factory()->create([
            'organization_id' => $this->org->id,
            'nom' => $nom,
            'prenom' => $prenom,
            'telephone' => '+2246200'.random_int(10000, 99999),
        ]);
        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Équipe '.$nom,
            'is_active' => true,
            'taux_commission_proprietaire' => 0,
        ]);
        $equipe->membres()->create([
            'livreur_id' => $livreur->id,
            'role' => 'chauffeur',
            'montant_par_pack' => 100,
            'taux_commission' => 5,
            'ordre' => 1,
        ]);

        return $vehicule;
    }

    /** @param  list<string>  $references */
    private function assertReferences(array $params, array $references): void
    {
        $this->get(route('ventes.index', array_merge(['periode' => 'all'], $params)))
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($references) {
                $page->has('commandes', count($references));
                $trouvees = collect($page->toArray()['props']['commandes'])->pluck('reference')->sort()->values()->all();
                $this->assertSame(collect($references)->sort()->values()->all(), $trouvees);
            });
    }

    public function test_recherche_par_reference_de_la_vente(): void
    {
        $this->commande('VTE-2026-0042');
        $this->commande('VTE-2026-0099');

        $this->assertReferences(['recherche' => '0042'], ['VTE-2026-0042']);
    }

    public function test_recherche_par_client(): void
    {
        $client = Client::factory()->create(['organization_id' => $this->org->id, 'nom' => null, 'prenom' => null, 'nom_complet' => 'Mariama Soumah']);
        $autre = Client::factory()->create(['organization_id' => $this->org->id, 'nom' => null, 'prenom' => null, 'nom_complet' => 'Kadiatou Keita']);
        $this->commande('VTE-CLIENT-A', ['client_id' => $client->id]);
        $this->commande('VTE-CLIENT-B', ['client_id' => $autre->id]);

        $this->assertReferences(['recherche' => 'Soumah'], ['VTE-CLIENT-A']);
    }

    public function test_recherche_par_vehicule_et_immatriculation(): void
    {
        $vehicule = $this->vehicule('Tricycle Bleu', 'RC-4521-A');
        $autre = $this->vehicule('Camion Rouge', 'RC-7777-B');
        $this->commande('VTE-VEH-A', ['vehicule_id' => $vehicule->id]);
        $this->commande('VTE-VEH-B', ['vehicule_id' => $autre->id]);

        $this->assertReferences(['recherche' => 'Tricycle'], ['VTE-VEH-A']);
        $this->assertReferences(['recherche' => '4521'], ['VTE-VEH-A']);
    }

    public function test_recherche_par_livreur(): void
    {
        $vehicule = $this->vehiculeAvecLivreur('Kouyaté', 'Sékou');
        $autre = $this->vehiculeAvecLivreur('Camara', 'Fodé');
        $this->commande('VTE-LIV-A', ['vehicule_id' => $vehicule->id]);
        $this->commande('VTE-LIV-B', ['vehicule_id' => $autre->id]);

        $this->assertReferences(['recherche' => 'Kouyaté'], ['VTE-LIV-A']);
    }

    public function test_recherche_se_combine_aux_filtres_rapides_et_du_panneau(): void
    {
        $vehicule = $this->vehiculeAvecLivreur('Diallo', 'Alpha');
        $this->commande('VTE-COMBI-1', ['vehicule_id' => $vehicule->id, 'statut' => StatutCommandeVente::A_CHARGER]);
        $this->commande('VTE-COMBI-2', ['vehicule_id' => $vehicule->id]);
        $this->commande('VTE-COMBI-3', ['statut' => StatutCommandeVente::A_CHARGER]);
        $this->commande('VTE-AUTRE-4', ['vehicule_id' => $vehicule->id, 'statut' => StatutCommandeVente::A_CHARGER]);

        $this->assertReferences([
            'recherche' => 'COMBI',
            'livreur' => 'Diallo',
            'vehicule' => $vehicule->immatriculation,
            'statuts' => ['a_charger'],
        ], ['VTE-COMBI-1']);

        // Retirer un critère ne retire pas les autres.
        $this->assertReferences([
            'recherche' => 'COMBI',
            'statuts' => ['a_charger'],
        ], ['VTE-COMBI-1', 'VTE-COMBI-3']);
    }

    public function test_recherche_n_elargit_jamais_le_perimetre(): void
    {
        $this->commande('VTE-PERIM-ICI');

        $autreSite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Agence Ailleurs',
            'type' => 'depot',
            'localisation' => 'Kindia',
        ]);
        $this->commande('VTE-PERIM-AUTRE-SITE', ['site_id' => $autreSite->id]);

        $autreOrg = Organization::factory()->create();
        $siteAutreOrg = Site::create([
            'organization_id' => $autreOrg->id,
            'nom' => 'Agence Autre Org',
            'type' => 'depot',
            'localisation' => 'Labé',
        ]);
        $this->commande('VTE-PERIM-AUTRE-ORG', ['organization_id' => $autreOrg->id, 'site_id' => $siteAutreOrg->id]);

        $distribution = $this->commande('VTE-PERIM-DISTRIB');
        $distribution->update(['nature_operation' => 'distribution_client']);

        // Administrateur (toutes agences) : la recherche respecte le filtre Agence (site_ids[]).
        $this->assertReferences(['recherche' => 'PERIM'], ['VTE-PERIM-ICI', 'VTE-PERIM-AUTRE-SITE']);
        $this->assertReferences(['recherche' => 'PERIM', 'site_ids' => [$this->site->id]], ['VTE-PERIM-ICI']);

        // Agent limité à son agence : la recherche ne fait jamais apparaître une autre agence.
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $agent = User::factory()->create(['organization_id' => $this->org->id]);
        $agent->assignRole('manager');
        $agent->givePermissionTo('ventes.read');
        $agent->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);
        $this->assertFalse($agent->voitToutesLesAgences());

        $this->actingAs($agent);
        $this->assertReferences(['recherche' => 'PERIM'], ['VTE-PERIM-ICI']);
    }

    public function test_resultat_vide_et_valeur_renvoyee_pour_l_url(): void
    {
        $this->commande('VTE-EXISTE');

        $this->get(route('ventes.index', ['recherche' => '  INTROUVABLE  ']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('commandes', 0)
                ->where('filters.recherche', 'INTROUVABLE'));

        $this->get(route('ventes.index'))
            ->assertInertia(fn (Assert $page) => $page->where('filters.recherche', null));
    }

    public function test_recherche_trop_longue_refusee(): void
    {
        $this->get(route('ventes.index', ['recherche' => str_repeat('a', 256)]))
            ->assertSessionHasErrors('recherche');
    }

    public function test_une_vue_enregistree_conserve_la_recherche_rapide(): void
    {
        $this->commande('VTE-VUE-GARDEE');
        $this->commande('VTE-VUE-MASQUEE');

        $id = $this->postJson(route('saved-filters.store', 'ventes'), [
            'name' => 'Recherche gardée',
            'visibility' => 'personal',
            'filters' => ['recherche' => 'GARDEE'],
            'is_default' => false,
        ])->assertCreated()->json('id');

        $this->get('/backoffice/ventes?saved_view='.$id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('saved_view.id', $id)
                ->where('filters.recherche', 'GARDEE')
                ->has('commandes', 1)
                ->where('commandes.0.reference', 'VTE-VUE-GARDEE'));
    }
}
