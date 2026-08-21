<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\StatutCommission;
use App\Models\CommandeVente;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionProcessus;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\User;
use App\Models\Vehicule;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class CommissionProprietaireVehiculesTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private Site $sitePrincipal;

    private CommissionProcessus $processus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['comptabilite.read']);
        $this->sitePrincipal = $this->user->sites()->firstOrFail();
        $this->processus = $this->makeProcessus($this->org);
    }

    private function makeProcessus(Organization $organization): CommissionProcessus
    {
        return CommissionProcessus::create([
            'organization_id' => $organization->id,
            'code' => CommissionProcessus::CODE_VENTE,
            'libelle' => 'Vente',
            'declencheur' => 'chargement_valide',
            'strategie_ancrage_site' => 'operation',
            'statut' => 'actif',
        ]);
    }

    private function makeVehicule(
        Organization $organization,
        Proprietaire $proprietaire,
        Site $site,
        string $nom,
    ): Vehicule {
        return Vehicule::factory()->create([
            'organization_id' => $organization->id,
            'proprietaire_id' => $proprietaire->id,
            'site_id' => $site->id,
            'nom_vehicule' => $nom,
        ]);
    }

    private function addCommission(
        Organization $organization,
        CommissionProcessus $processus,
        Proprietaire $proprietaire,
        Vehicule $vehicule,
        Site $site,
        CarbonInterface $earnedAt,
        float $montant,
    ): void {
        $commande = CommandeVente::create([
            'organization_id' => $organization->id,
            'site_id' => $site->id,
            'vehicule_id' => $vehicule->id,
            'reference' => 'CMD-'.fake()->unique()->numerify('########'),
            'statut' => 'livree',
            'total_commande' => 100000,
        ]);

        $enveloppe = CommissionEnveloppe::create([
            'organization_id' => $organization->id,
            'source_type' => CommandeVente::class,
            'source_id' => $commande->id,
            'processus_id' => $processus->id,
            'cible_type' => 'proprietaire',
            'cible_id' => $proprietaire->id,
            'montant_total' => $montant,
            'earned_at' => $earnedAt->toDateString(),
            'statut' => StatutCommission::IMPAYE->value,
        ]);

        CommissionEnveloppePart::create([
            'enveloppe_id' => $enveloppe->id,
            'beneficiaire_type' => CommissionEnveloppePart::TYPE_PROPRIETAIRE,
            'beneficiaire_id' => $proprietaire->id,
            'montant_brut' => $montant,
            'montant_net' => $montant,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE->value,
        ]);
    }

    /** @return array<string, mixed> */
    private function rowFor(TestResponse $response, Proprietaire $proprietaire): array
    {
        $rows = collect($response->viewData('page')['props']['beneficiaires']);

        return $rows->firstWhere('beneficiaire_id', $proprietaire->id) ?? [];
    }

    public function test_compteur_liste_uniquement_les_vehicules_contributeurs_du_site_et_de_la_periode_filtres(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $autreSite = Site::factory()->create(['organization_id' => $this->org->id, 'nom' => 'Agence Kaloum']);

        $contributeur = $this->makeVehicule($this->org, $proprietaire, $this->sitePrincipal, 'Camion contributeur');
        $ancien = $this->makeVehicule($this->org, $proprietaire, $this->sitePrincipal, 'Camion ancienne periode');
        $autreAgence = $this->makeVehicule($this->org, $proprietaire, $autreSite, 'Camion autre agence');
        $sansVente = $this->makeVehicule($this->org, $proprietaire, $this->sitePrincipal, 'Camion sans vente');

        $this->addCommission($this->org, $this->processus, $proprietaire, $contributeur, $this->sitePrincipal, now(), 1000);
        $this->addCommission($this->org, $this->processus, $proprietaire, $contributeur, $this->sitePrincipal, now(), 2500);
        $this->addCommission($this->org, $this->processus, $proprietaire, $ancien, $this->sitePrincipal, now()->subMonths(2), 3000);
        $this->addCommission($this->org, $this->processus, $proprietaire, $autreAgence, $autreSite, now(), 4000);

        $response = $this->actingAs($this->user)->get(route('comptabilite.commissions.proprietaires.index', [
            'periode' => now()->format('Y-m').'-M',
            'site_ids' => [$this->sitePrincipal->id],
        ]))->assertOk();

        $vehicules = collect($this->rowFor($response, $proprietaire)['vehicules']);

        $this->assertCount(1, $vehicules);
        $this->assertSame($contributeur->id, $vehicules->first()['id']);
        $this->assertSame(3500.0, $vehicules->first()['commission_generee']);
        $this->assertSame([$this->sitePrincipal->nom], collect($vehicules->first()['sites'])->pluck('nom')->all());
        $this->assertNotContains($ancien->id, $vehicules->pluck('id'));
        $this->assertNotContains($autreAgence->id, $vehicules->pluck('id'));
        $this->assertNotContains($sansVente->id, $vehicules->pluck('id'));
    }

    public function test_liste_supporte_un_grand_nombre_de_vehicules_contributeurs(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        foreach (range(1, 36) as $index) {
            $vehicule = $this->makeVehicule(
                $this->org,
                $proprietaire,
                $this->sitePrincipal,
                sprintf('Camion %02d', $index),
            );
            $this->addCommission($this->org, $this->processus, $proprietaire, $vehicule, $this->sitePrincipal, now(), 1000);
        }

        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.proprietaires.index'))
            ->assertOk();

        $vehicules = $this->rowFor($response, $proprietaire)['vehicules'];

        $this->assertCount(36, $vehicules);
        $this->assertSame('Camion 01', $vehicules[0]['nom']);
        $this->assertSame('Camion 36', $vehicules[35]['nom']);
    }

    public function test_liste_isole_les_vehicules_par_organisation(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = $this->makeVehicule($this->org, $proprietaire, $this->sitePrincipal, 'Camion organisation courante');
        $this->addCommission($this->org, $this->processus, $proprietaire, $vehicule, $this->sitePrincipal, now(), 1000);

        $autreOrg = Organization::factory()->create();
        $autreSite = Site::factory()->create(['organization_id' => $autreOrg->id]);
        $autreProprietaire = Proprietaire::factory()->create(['organization_id' => $autreOrg->id]);
        $vehiculeExterne = $this->makeVehicule($autreOrg, $autreProprietaire, $autreSite, 'Camion autre organisation');
        $this->addCommission($autreOrg, $this->makeProcessus($autreOrg), $autreProprietaire, $vehiculeExterne, $autreSite, now(), 9000);

        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.proprietaires.index'))
            ->assertOk();

        $rows = collect($response->viewData('page')['props']['beneficiaires']);
        $this->assertCount(1, $rows);
        $this->assertSame([$vehicule->id], collect($rows->first()['vehicules'])->pluck('id')->all());
        $this->assertNotContains($vehiculeExterne->id, $rows->flatMap(fn (array $row) => collect($row['vehicules'])->pluck('id')));
    }

    public function test_non_admin_ne_voit_que_les_vehicules_de_ses_sites_meme_si_url_manipulee(): void
    {
        $autreSite = Site::factory()->create(['organization_id' => $this->org->id, 'nom' => 'Agence interdite']);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $autorise = $this->makeVehicule($this->org, $proprietaire, $this->sitePrincipal, 'Camion autorise');
        $interdit = $this->makeVehicule($this->org, $proprietaire, $autreSite, 'Camion interdit');
        $this->addCommission($this->org, $this->processus, $proprietaire, $autorise, $this->sitePrincipal, now(), 1000);
        $this->addCommission($this->org, $this->processus, $proprietaire, $interdit, $autreSite, now(), 9000);

        $lecteur = $this->makeSiteScopedUser($this->sitePrincipal, true);
        $response = $this->actingAs($lecteur)->get(route('comptabilite.commissions.proprietaires.index', [
            'site_ids' => [$autreSite->id],
        ]))->assertOk();

        $vehicules = collect($this->rowFor($response, $proprietaire)['vehicules']);
        $this->assertSame([$autorise->id], $vehicules->pluck('id')->all());
        $this->assertNotContains($interdit->id, $vehicules->pluck('id'));
    }

    public function test_liste_necessite_la_permission_comptabilite_read(): void
    {
        $sansPermission = $this->makeSiteScopedUser($this->sitePrincipal, false);

        $this->actingAs($sansPermission)
            ->get(route('comptabilite.commissions.proprietaires.index'))
            ->assertForbidden();
    }

    private function makeSiteScopedUser(Site $site, bool $withPermission): User
    {
        $role = Role::firstOrCreate(['name' => 'employe', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole($role);

        if ($withPermission) {
            $permission = Permission::firstOrCreate(['name' => 'comptabilite.read', 'guard_name' => 'web']);
            $user->givePermissionTo($permission);
        }

        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }
}
