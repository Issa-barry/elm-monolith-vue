<?php

namespace Tests\Feature\Livreurs;

use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Activation / désactivation d'un livreur depuis sa fiche — LivreurController::desactiver() et
 * approuver() (réutilisé pour la réactivation).
 */
class StatutLivreurTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['livreurs.read', 'livreurs.update']);
    }

    public function test_desactiver_depuis_la_fiche_redirige_et_conserve_l_equipe(): void
    {
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id, 'is_active' => true]);
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id]);
        $equipe = EquipeLivraison::create(['organization_id' => $this->org->id, 'vehicule_id' => $vehicule->id, 'is_active' => true]);
        EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $livreur->id, 'role' => 'chauffeur', 'ordre' => 1]);

        $this->actingAs($this->user)
            ->from(route('livreurs.show', $livreur))
            ->patch(route('livreurs.desactiver', $livreur), [], ['X-Inertia' => 'true'])
            ->assertRedirect(route('livreurs.show', $livreur))
            ->assertSessionHas('success', 'Livreur désactivé.');

        $this->assertFalse($livreur->fresh()->is_active);
        $this->assertNotSoftDeleted('livreurs', ['id' => $livreur->id]);
        $this->assertDatabaseHas('equipe_livreurs', ['equipe_id' => $equipe->id, 'livreur_id' => $livreur->id]);
    }

    public function test_desactiver_est_idempotent(): void
    {
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id, 'is_active' => false]);

        $this->actingAs($this->user)
            ->patchJson(route('livreurs.desactiver', $livreur))
            ->assertOk()
            ->assertJson(['is_active' => false]);

        $this->assertFalse($livreur->fresh()->is_active);
    }

    public function test_reactiver_depuis_la_fiche(): void
    {
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id, 'is_active' => false]);

        $this->actingAs($this->user)
            ->from(route('livreurs.show', $livreur))
            ->patch(route('livreurs.approuver', $livreur), [], ['X-Inertia' => 'true'])
            ->assertRedirect(route('livreurs.show', $livreur));

        $this->assertTrue($livreur->fresh()->is_active);
    }

    public function test_desactiver_403_sans_permission_update(): void
    {
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id, 'is_active' => true]);
        $lecteur = $this->makeUserWithPermissions($this->org, ['livreurs.read']);
        $lecteur->sites()->attach($this->user->sites()->first()->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($lecteur)
            ->patch(route('livreurs.desactiver', $livreur))
            ->assertForbidden();

        $this->assertTrue($livreur->fresh()->is_active);
    }

    public function test_desactiver_403_pour_une_autre_organisation(): void
    {
        $livreur = Livreur::factory()->create(['organization_id' => Organization::factory()->create()->id, 'is_active' => true]);

        $this->actingAs($this->user)
            ->patch(route('livreurs.desactiver', $livreur))
            ->assertForbidden();

        $this->assertTrue($livreur->fresh()->is_active);
    }
}
