<?php

namespace Tests\Feature\Livreurs;

use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Modification d'un livreur depuis sa fiche — cf. App\Http\Controllers\Livreurs\UpdateLivreurController.
 */
class UpdateLivreurTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['livreurs.read', 'livreurs.update']);
    }

    private function makeLivreur(?string $telephone = '+224620000001', ?string $role = null): Livreur
    {
        $personne = Personne::resoudreOuCreer($this->org->id, ['telephone' => $telephone]);
        $livreur = Livreur::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'nom_complet' => 'Ancien nom',
            'is_active' => true,
        ]);

        if ($role !== null) {
            $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id]);
            $equipe = EquipeLivraison::create(['organization_id' => $this->org->id, 'vehicule_id' => $vehicule->id, 'is_active' => true]);
            EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $livreur->id, 'role' => $role, 'ordre' => 1]);
        }

        return $livreur;
    }

    public function test_modifie_le_nom_et_le_telephone(): void
    {
        $livreur = $this->makeLivreur();

        $this->actingAs($this->user)
            ->put(route('livreurs.update', $livreur), [
                'nom_complet' => '  Nouveau nom  ',
                'telephone' => '+224 611 06 18 33',
            ])
            ->assertRedirect(route('livreurs.show', $livreur))
            ->assertSessionHas('success');

        $livreur->refresh();
        $this->assertSame('Nouveau nom', $livreur->nom_complet);
        $this->assertSame('+224611061833', $livreur->personne->telephone);
        $this->assertSame('224611061833', $livreur->personne->telephone_normalise);
    }

    public function test_refuse_un_telephone_deja_porte_par_une_autre_personne(): void
    {
        $livreur = $this->makeLivreur();
        Personne::resoudreOuCreer($this->org->id, ['telephone' => '+224620000099']);

        $this->actingAs($this->user)
            ->put(route('livreurs.update', $livreur), [
                'nom_complet' => 'Nom',
                'telephone' => '+224620000099',
            ])
            ->assertSessionHasErrors('telephone');

        $this->assertSame('+224620000001', $livreur->personne->fresh()->telephone);
    }

    public function test_garder_son_propre_numero_est_accepte(): void
    {
        $livreur = $this->makeLivreur();

        $this->actingAs($this->user)
            ->put(route('livreurs.update', $livreur), [
                'nom_complet' => 'Nom',
                'telephone' => '+224620000001',
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_telephone_obligatoire_pour_un_chauffeur(): void
    {
        $livreur = $this->makeLivreur(role: 'chauffeur');

        $this->actingAs($this->user)
            ->put(route('livreurs.update', $livreur), ['nom_complet' => 'Nom', 'telephone' => ''])
            ->assertSessionHasErrors('telephone');
    }

    public function test_telephone_facultatif_pour_un_convoyeur(): void
    {
        $livreur = $this->makeLivreur(role: 'convoyeur');

        $this->actingAs($this->user)
            ->put(route('livreurs.update', $livreur), ['nom_complet' => 'Nom', 'telephone' => ''])
            ->assertSessionHasNoErrors();

        $this->assertNull($livreur->personne->fresh()->telephone);
        $this->assertNull($livreur->personne->fresh()->telephone_normalise);
    }

    public function test_format_telephone_invalide(): void
    {
        $livreur = $this->makeLivreur();

        $this->actingAs($this->user)
            ->put(route('livreurs.update', $livreur), ['nom_complet' => 'Nom', 'telephone' => '+22412345'])
            ->assertSessionHasErrors('telephone');
    }

    public function test_nom_obligatoire(): void
    {
        $livreur = $this->makeLivreur();

        $this->actingAs($this->user)
            ->put(route('livreurs.update', $livreur), ['nom_complet' => '', 'telephone' => '+224620000001'])
            ->assertSessionHasErrors('nom_complet');
    }

    public function test_403_sans_permission_update(): void
    {
        $livreur = $this->makeLivreur();
        $lecteur = $this->makeUserWithPermissions($this->org, ['livreurs.read']);
        $lecteur->sites()->attach($this->user->sites()->first()->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($lecteur)
            ->put(route('livreurs.update', $livreur), ['nom_complet' => 'Nom', 'telephone' => '+224620000001'])
            ->assertForbidden();

        $this->assertSame('Ancien nom', $livreur->fresh()->nom_complet);
    }

    public function test_403_pour_un_livreur_d_une_autre_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        $livreur = Livreur::factory()->create(['organization_id' => $autreOrg->id, 'nom_complet' => 'Externe']);

        $this->actingAs($this->user)
            ->put(route('livreurs.update', $livreur), ['nom_complet' => 'Piraté', 'telephone' => '+224620000055'])
            ->assertForbidden();

        $this->assertSame('Externe', $livreur->fresh()->nom_complet);
    }
}
