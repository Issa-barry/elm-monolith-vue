<?php

namespace Tests\Feature;

use App\Enums\StatutCommandeVente;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\EquipeLivraison;
use App\Models\Livreur;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class CommandeVenteIndexFilterTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read', 'ventes.create', 'ventes.update', 'ventes.delete']);

        $this->site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Agence Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);
    }

    private function makeCommande(array $overrides = []): CommandeVente
    {
        static $seq = 0;
        $seq++;

        return CommandeVente::create(array_merge([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 5000,
            'reference' => 'TST-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT).'-'.uniqid(),
            'numero' => $seq,
        ], $overrides));
    }

    private function makeVehicule(array $overrides = []): Vehicule
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        return Vehicule::factory()->create(array_merge([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
        ], $overrides));
    }

    private function assertIndexReturnsOnly(CommandeVente $expected, array $params): void
    {
        $this->actingAs($this->user)
            ->get(route('ventes.index', array_merge(['periode' => 'all'], $params)))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->has('commandes', 1)
                ->where('commandes.0.reference', $expected->reference),
            );
    }

    // ── Statut ────────────────────────────────────────────────────────────────

    public function test_filter_by_statut_returns_only_matching_commandes(): void
    {
        $livree = $this->makeCommande(['statut' => StatutCommandeVente::LIVREE]);
        $this->makeCommande(['statut' => StatutCommandeVente::BROUILLON]);

        $this->assertIndexReturnsOnly($livree, ['statuts' => ['livree']]);
    }

    // ── Site (admin) ──────────────────────────────────────────────────────────

    public function test_filter_by_site_id_returns_only_matching_commandes(): void
    {
        $autreAgence = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Autre Agence',
            'type' => 'depot',
            'localisation' => 'Kindia',
        ]);

        $cmdIci = $this->makeCommande(['site_id' => $this->site->id]);
        $this->makeCommande(['site_id' => $autreAgence->id]);

        $this->assertIndexReturnsOnly($cmdIci, ['site_ids' => [$this->site->id]]);
    }

    // ── Dates ─────────────────────────────────────────────────────────────────

    public function test_filter_by_date_debut_excludes_older_commandes(): void
    {
        $recente = $this->makeCommande();
        $recente->created_at = now();
        $recente->save();

        $ancienne = $this->makeCommande();
        $ancienne->created_at = now()->subDays(10);
        $ancienne->saveQuietly();

        $this->actingAs($this->user)
            ->get(route('ventes.index', ['date_debut' => now()->subDays(1)->toDateString()]))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->where('commandes.0.reference', $recente->reference)
                ->has('commandes', 1),
            );
    }

    public function test_filter_by_date_fin_excludes_newer_commandes(): void
    {
        $ancienne = $this->makeCommande();
        $ancienne->created_at = now()->subDays(5);
        $ancienne->saveQuietly();

        $recente = $this->makeCommande();
        $recente->created_at = now();
        $recente->saveQuietly();

        $this->actingAs($this->user)
            ->get(route('ventes.index', ['date_fin' => now()->subDays(2)->toDateString()]))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->where('commandes.0.reference', $ancienne->reference)
                ->has('commandes', 1),
            );
    }

    // ── Véhicule ──────────────────────────────────────────────────────────────

    public function test_filter_by_vehicule_nom(): void
    {
        $v1 = $this->makeVehicule(['nom_vehicule' => 'Camion Alpha']);
        $v2 = $this->makeVehicule(['nom_vehicule' => 'Minibus Bêta']);

        $match = $this->makeCommande(['vehicule_id' => $v1->id]);
        $this->makeCommande(['vehicule_id' => $v2->id]);

        $this->assertIndexReturnsOnly($match, ['vehicule_nom' => 'Alpha']);
    }

    public function test_filter_by_vehicule_immatriculation(): void
    {
        $v1 = $this->makeVehicule(['immatriculation' => 'RC-111-AA']);
        $v2 = $this->makeVehicule(['immatriculation' => 'RC-999-ZZ']);

        $match = $this->makeCommande(['vehicule_id' => $v1->id]);
        $this->makeCommande(['vehicule_id' => $v2->id]);

        $this->assertIndexReturnsOnly($match, ['vehicule_immatriculation' => '111']);
    }

    // ── Propriétaire ──────────────────────────────────────────────────────────

    public function test_filter_by_proprietaire_nom(): void
    {
        $p1 = Proprietaire::factory()->create(['organization_id' => $this->org->id, 'nom' => 'Diallo', 'prenom' => 'Alpha']);
        $p2 = Proprietaire::factory()->create(['organization_id' => $this->org->id, 'nom' => 'Camara', 'prenom' => 'Bêta']);
        $v1 = Vehicule::factory()->create(['organization_id' => $this->org->id, 'proprietaire_id' => $p1->id]);
        $v2 = Vehicule::factory()->create(['organization_id' => $this->org->id, 'proprietaire_id' => $p2->id]);

        $match = $this->makeCommande(['vehicule_id' => $v1->id]);
        $this->makeCommande(['vehicule_id' => $v2->id]);

        $this->assertIndexReturnsOnly($match, ['proprietaire_nom' => 'Diallo']);
    }

    public function test_filter_by_proprietaire_telephone(): void
    {
        $p1 = Proprietaire::factory()->create(['organization_id' => $this->org->id, 'telephone' => '+224620000001']);
        $p2 = Proprietaire::factory()->create(['organization_id' => $this->org->id, 'telephone' => '+224620000002']);
        $v1 = Vehicule::factory()->create(['organization_id' => $this->org->id, 'proprietaire_id' => $p1->id]);
        $v2 = Vehicule::factory()->create(['organization_id' => $this->org->id, 'proprietaire_id' => $p2->id]);

        $match = $this->makeCommande(['vehicule_id' => $v1->id]);
        $this->makeCommande(['vehicule_id' => $v2->id]);

        $this->assertIndexReturnsOnly($match, ['proprietaire_telephone' => '620000001']);
    }

    // ── Livreur ───────────────────────────────────────────────────────────────

    private function makeVehiculeWithLivreur(string $nom, string $prenom, string $telephone, string $role): array
    {
        $vehicule = $this->makeVehicule();
        $livreur = Livreur::factory()->create([
            'organization_id' => $this->org->id,
            'nom' => $nom,
            'prenom' => $prenom,
            'telephone' => $telephone,
        ]);
        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Équipe '.$vehicule->id,
            'is_active' => true,
            'taux_commission_proprietaire' => 0,
        ]);
        $equipe->membres()->create([
            'livreur_id' => $livreur->id,
            'role' => $role,
            'montant_par_pack' => 100,
            'taux_commission' => 5,
            'ordre' => 1,
        ]);

        return compact('vehicule', 'livreur', 'equipe');
    }

    public function test_filter_by_livreur_nom(): void
    {
        ['vehicule' => $v1] = $this->makeVehiculeWithLivreur('Baldé', 'Alpha', '+224620111111', 'chauffeur');
        ['vehicule' => $v2] = $this->makeVehiculeWithLivreur('Kouyaté', 'Bêta', '+224620222222', 'chauffeur');

        $match = $this->makeCommande(['vehicule_id' => $v1->id]);
        $this->makeCommande(['vehicule_id' => $v2->id]);

        $this->assertIndexReturnsOnly($match, ['livreur_nom' => 'Baldé']);
    }

    public function test_filter_by_livreur_prenom(): void
    {
        ['vehicule' => $v1] = $this->makeVehiculeWithLivreur('Traoré', 'Mamadou', '+224620333333', 'chauffeur');
        ['vehicule' => $v2] = $this->makeVehiculeWithLivreur('Traoré', 'Ibrahim', '+224620444444', 'chauffeur');

        $match = $this->makeCommande(['vehicule_id' => $v1->id]);
        $this->makeCommande(['vehicule_id' => $v2->id]);

        $this->assertIndexReturnsOnly($match, ['livreur_prenom' => 'Mamadou']);
    }

    public function test_filter_by_livreur_telephone(): void
    {
        ['vehicule' => $v1] = $this->makeVehiculeWithLivreur('Sylla', 'Alpha', '+224628555555', 'chauffeur');
        ['vehicule' => $v2] = $this->makeVehiculeWithLivreur('Barry', 'Bêta', '+224628666666', 'chauffeur');

        $match = $this->makeCommande(['vehicule_id' => $v1->id]);
        $this->makeCommande(['vehicule_id' => $v2->id]);

        $this->assertIndexReturnsOnly($match, ['livreur_telephone' => '555555']);
    }

    public function test_filter_by_livreur_role_chauffeur(): void
    {
        ['vehicule' => $v1] = $this->makeVehiculeWithLivreur('Sow', 'Alpha', '+224620777777', 'chauffeur');
        ['vehicule' => $v2] = $this->makeVehiculeWithLivreur('Conté', 'Bêta', '+224620888888', 'convoyeur');

        $match = $this->makeCommande(['vehicule_id' => $v1->id]);
        $this->makeCommande(['vehicule_id' => $v2->id]);

        $this->assertIndexReturnsOnly($match, ['livreur_role' => 'chauffeur']);
    }

    public function test_filter_by_livreur_role_convoyeur(): void
    {
        ['vehicule' => $v1] = $this->makeVehiculeWithLivreur('Bah', 'Alpha', '+224620901010', 'convoyeur');
        ['vehicule' => $v2] = $this->makeVehiculeWithLivreur('Touré', 'Bêta', '+224620902020', 'chauffeur');

        $match = $this->makeCommande(['vehicule_id' => $v1->id]);
        $this->makeCommande(['vehicule_id' => $v2->id]);

        $this->assertIndexReturnsOnly($match, ['livreur_role' => 'convoyeur']);
    }

    // ── Numéro de commande ────────────────────────────────────────────────────

    public function test_filter_by_numero_commande(): void
    {
        $match = $this->makeCommande(['reference' => 'CMD-SPECIAL-9999']);
        $this->makeCommande(['reference' => 'CMD-OTHER-0001']);

        $this->assertIndexReturnsOnly($match, ['numero_commande' => 'SPECIAL']);
    }

    // ── Client ────────────────────────────────────────────────────────────────

    public function test_filter_by_client_nom(): void
    {
        $c1 = Client::factory()->create(['organization_id' => $this->org->id, 'nom' => 'Diallo', 'prenom' => 'Fatoumata']);
        $c2 = Client::factory()->create(['organization_id' => $this->org->id, 'nom' => 'Bah', 'prenom' => 'Aissatou']);

        $match = $this->makeCommande(['client_id' => $c1->id]);
        $this->makeCommande(['client_id' => $c2->id]);

        $this->assertIndexReturnsOnly($match, ['client_nom' => 'Diallo']);
    }

    public function test_filter_by_client_telephone(): void
    {
        $c1 = Client::factory()->create(['organization_id' => $this->org->id, 'telephone' => '+224664111222']);
        $c2 = Client::factory()->create(['organization_id' => $this->org->id, 'telephone' => '+224664333444']);

        $match = $this->makeCommande(['client_id' => $c1->id]);
        $this->makeCommande(['client_id' => $c2->id]);

        $this->assertIndexReturnsOnly($match, ['client_telephone' => '111222']);
    }

    // ── Cumul de filtres ──────────────────────────────────────────────────────

    public function test_combined_filters_narrow_results(): void
    {
        $client = Client::factory()->create([
            'organization_id' => $this->org->id,
            'nom' => 'Condé',
            'telephone' => '+224600123456',
        ]);

        $match = $this->makeCommande([
            'client_id' => $client->id,
            'statut' => StatutCommandeVente::A_CHARGER,
        ]);
        $this->makeCommande([
            'client_id' => $client->id,
            'statut' => StatutCommandeVente::BROUILLON,
        ]);
        $this->makeCommande(['statut' => StatutCommandeVente::A_CHARGER]);

        $this->assertIndexReturnsOnly($match, [
            'client_nom' => 'Condé',
            'statuts' => ['a_charger'],
        ]);
    }
}
