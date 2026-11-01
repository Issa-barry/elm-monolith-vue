<?php

namespace Tests\Feature;

use App\Models\Contrat;
use App\Models\Employe;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\DesigneUnPdg;
use Tests\TestCase;

/**
 * Contrats successifs d'un salarié (docs/rh-depart-salarie.md §2) : la date d'embauche vient du
 * premier contrat quand elle est vide, et deux contrats ne peuvent pas couvrir les mêmes jours.
 */
class ContratsSuccessifsTest extends TestCase
{
    use DesigneUnPdg;
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->org = Organization::factory()->create();
        $this->designerPdg($this->org);
        $site = Site::create(['organization_id' => $this->org->id, 'nom' => 'Matoto', 'type' => 'depot']);

        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $permissions = ['rh-contrats.read', 'rh-contrats.create', 'rh-contrats.update', 'rh-remunerations.read'];
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $this->user = User::factory()->create(['organization_id' => $this->org->id]);
        $this->user->assignRole('admin_entreprise');
        $this->user->givePermissionTo($permissions);
        $this->user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);
    }

    private function employe(array $attributs = []): Employe
    {
        $personne = Personne::create(['organization_id' => $this->org->id, 'nom' => 'CAMARA', 'prenom' => 'Aïssatou',
            'telephone' => '+22462'.fake()->unique()->numerify('#######')]);

        return Employe::create([
            'organization_id' => $this->org->id, 'personne_id' => $personne->id, 'matricule' => fake()->unique()->numerify('######'),
            'type_employe' => 'interne', 'statut' => 'actif', ...$attributs,
        ]);
    }

    private function creer(Employe $employe, array $donnees)
    {
        return $this->actingAs($this->user)->post(route('contrats.store'), [...$this->conditionsContrat(), 'employe_id' => $employe->id, 'salaire_base' => 600000, ...$donnees]);
    }

    public function test_le_premier_contrat_renseigne_la_date_d_embauche(): void
    {
        $employe = $this->employe();

        $this->creer($employe, ['type_contrat' => 'cdd', 'date_debut' => '2026-03-01', 'date_fin' => '2026-08-31'])->assertSessionHasNoErrors();

        $this->assertSame('2026-03-01', $employe->fresh()->date_embauche->toDateString());
    }

    public function test_une_date_d_embauche_deja_connue_n_est_jamais_modifiee(): void
    {
        $employe = $this->employe(['date_embauche' => '2019-06-01']);

        $this->creer($employe, ['type_contrat' => 'cdi', 'date_debut' => '2026-03-01'])->assertSessionHasNoErrors();

        $this->assertSame('2019-06-01', $employe->fresh()->date_embauche->toDateString());
    }

    public function test_un_cdi_peut_suivre_un_cdd_termine_sans_chevauchement(): void
    {
        $employe = $this->employe();
        $this->creer($employe, ['type_contrat' => 'cdd', 'date_debut' => '2026-03-01', 'date_fin' => '2026-08-31'])->assertSessionHasNoErrors();
        Contrat::sole()->update(['statut_contrat' => 'termine']);

        $this->creer($employe, ['type_contrat' => 'cdi', 'date_debut' => '2026-08-15'])
            ->assertSessionHasErrors(['date_debut' => 'Ces dates chevauchent un autre contrat de cet employé (CDD du 01/03/2026 au 31/08/2026).']);

        $this->creer($employe, ['type_contrat' => 'cdi', 'date_debut' => '2026-09-01'])->assertSessionHasNoErrors();
        $this->assertSame(2, Contrat::where('employe_id', $employe->id)->count());
        $this->assertSame('2026-03-01', $employe->fresh()->date_embauche->toDateString());
    }

    public function test_un_contrat_termine_sans_date_de_fin_ne_bloque_pas_la_saisie(): void
    {
        $employe = $this->employe();
        Contrat::create(['organization_id' => $this->org->id, 'employe_id' => $employe->id, 'type_contrat' => 'cdi',
            'date_debut' => '2024-01-01', 'statut_contrat' => 'termine']);

        $this->creer($employe, ['type_contrat' => 'cdi', 'date_debut' => '2026-01-01'])->assertSessionHasNoErrors();
    }

    public function test_modifier_un_contrat_pour_chevaucher_un_autre_est_refuse(): void
    {
        $employe = $this->employe();
        $ancien = Contrat::create(['organization_id' => $this->org->id, 'employe_id' => $employe->id, 'type_contrat' => 'cdd',
            'date_debut' => '2025-01-01', 'date_fin' => '2025-12-31', 'statut_contrat' => 'termine']);
        $this->creer($employe, ['type_contrat' => 'cdi', 'date_debut' => '2026-01-01'])->assertSessionHasNoErrors();
        $courant = Contrat::whereKeyNot($ancien->id)->sole();

        $this->actingAs($this->user)->put(route('contrats.update', $courant), [
            'employe_id' => $employe->id, 'type_contrat' => 'cdi', 'date_debut' => '2025-12-01', 'salaire_base' => 600000,
        ])->assertSessionHasErrors('date_debut');

        $this->assertSame('2026-01-01', $courant->fresh()->date_debut->toDateString());
    }
}
