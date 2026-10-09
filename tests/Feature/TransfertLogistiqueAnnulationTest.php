<?php

namespace Tests\Feature;

use App\Enums\StatutTransfert;
use App\Features\ModuleFeature;
use App\Models\Organization;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Annulation d'un transfert : interdite dÃ¨s TRANSIT, y compris pour le super admin que
 * Gate::before laisse passer avant la policy (incident formation 09/10/2026 : bouton
 * Â« Annuler Â» affichÃ© en livraison puis page d'erreur 422 brute au clic).
 */
class TransfertLogistiqueAnnulationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Site $source;

    private Site $destination;

    private Vehicule $vehicule;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->org = Organization::factory()->create();
        Feature::for($this->org)->activate(ModuleFeature::LOGISTIQUE);

        $this->source = $this->makeSite('Cba');
        $this->destination = $this->makeSite('Sonfonia');
        $this->vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'livraison_vente' => false,
            'livraison_logistique' => true,
            'is_active' => true,
        ]);
    }

    private function makeSite(string $nom): Site
    {
        return Site::create([
            'organization_id' => $this->org->id,
            'nom' => $nom,
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
    }

    private function makeUser(string $role): User
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        foreach (['logistique.read', 'logistique.update'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole($role);
        $user->givePermissionTo(['logistique.read', 'logistique.update']);
        $user->sites()->attach($this->source->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function makeTransfert(StatutTransfert $statut, User $auteur): TransfertLogistique
    {
        $this->actingAs($auteur);

        $transfert = TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'site_source_id' => $this->source->id,
            'site_destination_id' => $this->destination->id,
            'vehicule_id' => $this->vehicule->id,
        ]);
        $transfert->forceFill(['statut' => $statut->value])->save();

        return $transfert->fresh();
    }

    public function test_super_admin_ne_voit_pas_annuler_sur_un_transfert_en_livraison(): void
    {
        $superAdmin = $this->makeUser('super_admin');
        $transfert = $this->makeTransfert(StatutTransfert::TRANSIT, $superAdmin);

        $this->actingAs($superAdmin)
            ->get('/backoffice/logistique/'.$transfert->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Logistique/Show')
                ->where('can_annuler', false)
            );

        $this->actingAs($superAdmin)
            ->get('/backoffice/logistique/transferts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('transferts.0.id', $transfert->id)
                ->where('transferts.0.can_annuler', false)
            );
    }

    public function test_super_admin_voit_annuler_en_chargement(): void
    {
        $superAdmin = $this->makeUser('super_admin');
        $transfert = $this->makeTransfert(StatutTransfert::CHARGEMENT, $superAdmin);

        $this->actingAs($superAdmin)
            ->get('/backoffice/logistique/'.$transfert->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can_annuler', true));
    }

    public function test_annulation_en_livraison_renvoie_une_erreur_lisible_sans_changer_le_statut(): void
    {
        $superAdmin = $this->makeUser('super_admin');
        $transfert = $this->makeTransfert(StatutTransfert::TRANSIT, $superAdmin);

        $this->actingAs($superAdmin)
            ->from('/backoffice/logistique/'.$transfert->id)
            ->post('/backoffice/logistique/'.$transfert->id.'/statut/annuler')
            ->assertRedirect('/backoffice/logistique/'.$transfert->id)
            ->assertSessionHasErrors('statut');

        $this->assertSame(StatutTransfert::TRANSIT, $transfert->fresh()->statut);
    }

    public function test_annulation_en_chargement_passe_le_transfert_en_annule(): void
    {
        $superAdmin = $this->makeUser('super_admin');
        $transfert = $this->makeTransfert(StatutTransfert::CHARGEMENT, $superAdmin);

        $this->actingAs($superAdmin)
            ->post('/backoffice/logistique/'.$transfert->id.'/statut/annuler')
            ->assertRedirect(route('logistique.show', $transfert))
            ->assertSessionHasNoErrors();

        $this->assertSame(StatutTransfert::ANNULE, $transfert->fresh()->statut);
    }
}
