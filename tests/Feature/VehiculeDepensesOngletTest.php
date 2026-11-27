<?php

namespace Tests\Feature;

use App\Features\ModuleFeature;
use App\Models\Depense;
use App\Models\DepenseType;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\User;
use App\Models\Vehicule;
use App\Support\Depenses\DepensePerimetre;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Onglet Dépenses de la fiche véhicule (27/11/2026) : `vehicules.read` ouvre la fiche, jamais les
 * dépenses. Exige `depenses.read` et le module Dépenses, et reprend le périmètre de l'écran
 * Dépenses (DepensePerimetre) — cf. docs/vehicule-situation-ventes.md, « Droits et périmètre ».
 */
class VehiculeDepensesOngletTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private Site $agenceA;

    private Site $agenceB;

    private Vehicule $vehicule;

    private DepenseType $type;

    protected function setUp(): void
    {
        parent::setUp();
        // Administrateur : toutes les agences.
        $this->initOrgAndUser(['vehicules.read', 'depenses.read']);
        $this->agenceA = $this->user->sites()->firstOrFail();
        $this->agenceB = Site::create(['organization_id' => $this->org->id, 'nom' => 'Agence B', 'type' => 'depot', 'localisation' => 'Kindia']);
        $this->vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => Proprietaire::factory()->create(['organization_id' => $this->org->id])->id,
        ]);
        $this->type = DepenseType::factory()->create(['organization_id' => $this->org->id, 'categorie' => 'vehicule', 'libelle' => 'Carburant']);
    }

    private function makeDepense(?Site $site, int $montant, array $overrides = []): Depense
    {
        return Depense::factory()->brouillon()->create(array_merge([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->type->id,
            'beneficiaire_type' => 'vehicule',
            'beneficiaire_id' => $this->vehicule->id,
            'site_id' => $site?->id,
            'montant' => $montant,
        ], $overrides));
    }

    /** Agent non administrateur, rattaché aux seules agences données. */
    private function makeAgent(array $permissions, array $sites): User
    {
        Role::firstOrCreate(['name' => 'agent_depenses_test', 'guard_name' => 'web']);
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $agent = User::factory()->create(['organization_id' => $this->org->id]);
        $agent->assignRole('agent_depenses_test');
        $agent->givePermissionTo($permissions);
        foreach ($sites as $index => $site) {
            $agent->sites()->attach($site->id, ['role' => 'employe', 'is_default' => $index === 0]);
        }

        return $agent;
    }

    /** @return list<float> montants de l'onglet, dans l'ordre reçu */
    private function montantsVus(User $user): array
    {
        $depenses = $this->actingAs($user)
            ->get(route('vehicules.show', $this->vehicule))
            ->assertOk()
            ->viewData('page')['props']['depenses'];

        $montants = array_map(fn (array $d) => (float) $d['montant'], $depenses);
        sort($montants);

        return $montants;
    }

    public function test_sans_depenses_read_les_depenses_ne_sont_ni_lues_ni_envoyees(): void
    {
        $this->makeDepense($this->agenceA, 15000);
        $agent = $this->makeAgent(['vehicules.read'], [$this->agenceA]);

        $this->actingAs($agent)
            ->get(route('vehicules.show', $this->vehicule))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Vehicules/Show')
                ->where('depenses', null)
            );
    }

    public function test_module_depenses_inactif_les_depenses_ne_sont_pas_envoyees(): void
    {
        $this->makeDepense($this->agenceA, 15000);
        Feature::for($this->org)->deactivate(ModuleFeature::DEPENSES);

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $this->vehicule))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('depenses', null));
    }

    /** Périmètre de l'écran Dépenses : ses agences, plus ses propres saisies. */
    public function test_agent_ne_voit_que_les_depenses_de_son_perimetre(): void
    {
        $agent = $this->makeAgent(['vehicules.read', 'depenses.read'], [$this->agenceA]);

        $this->makeDepense($this->agenceA, 1000);
        $this->makeDepense($this->agenceB, 2000);
        $this->makeDepense(null, 3000);
        $this->makeDepense($this->agenceB, 4000, ['user_id' => $agent->id]);
        // Dépense d'un autre véhicule : jamais dans l'onglet.
        $this->makeDepense($this->agenceA, 5000, ['beneficiaire_id' => Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $this->vehicule->proprietaire_id,
        ])->id]);

        $this->assertSame([1000.0, 4000.0], $this->montantsVus($agent));
    }

    public function test_vision_toutes_agences_des_depenses_voit_toutes_les_depenses_du_vehicule(): void
    {
        $agent = $this->makeAgent(['vehicules.read', 'depenses.read', DepensePerimetre::PERMISSION_TOUTES_AGENCES], [$this->agenceA]);

        $this->makeDepense($this->agenceA, 1000);
        $this->makeDepense($this->agenceB, 2000);
        $this->makeDepense(null, 3000);

        $this->assertSame([1000.0, 2000.0, 3000.0], $this->montantsVus($agent));
    }
}
