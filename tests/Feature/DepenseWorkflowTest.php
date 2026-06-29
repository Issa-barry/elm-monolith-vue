<?php

namespace Tests\Feature;

use App\Enums\StatutDepense;
use App\Models\Depense;
use App\Models\DepenseType;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Tests du cycle de vie d'une dépense :
 * - Transition de statut via update() (REJETE/BROUILLON → SOUMIS)
 * - Soumission directe via soumettre()
 * - Blocage des transitions invalides
 */
class DepenseWorkflowTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private DepenseType $type;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser([
            'depenses.read',
            'depenses.create',
            'depenses.update',
            'depenses.delete',
        ]);
        $this->actingAs($this->user);

        $this->site = Site::where('organization_id', $this->org->id)->firstOrFail();

        $this->type = DepenseType::factory()->interne()->create([
            'organization_id' => $this->org->id,
            'libelle' => 'Carburant test',
            'code' => 'carb-test',
        ]);
    }

    private function updatePayload(array $override = []): array
    {
        return array_merge([
            'depense_type_id' => $this->type->id,
            'site_id' => $this->site->id,
            'montant' => 10000,
            'date_depense' => now()->toDateString(),
            'commentaire' => null,
            'statut' => 'brouillon',
        ], $override);
    }

    // ── Transition via update (bouton "Soumettre pour validation" du form Edit) ─

    public function test_update_avec_statut_soumis_depuis_rejete_change_statut(): void
    {
        $depense = Depense::factory()->rejete()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->type->id,
            'site_id' => $this->site->id,
        ]);

        $this->put(route('depenses.update', $depense), $this->updatePayload(['statut' => 'soumis']))
            ->assertRedirect();

        $this->assertDatabaseHas('depenses', [
            'id' => $depense->id,
            'statut' => StatutDepense::SOUMIS->value,
        ]);
    }

    public function test_update_avec_statut_soumis_depuis_brouillon_change_statut(): void
    {
        $depense = Depense::factory()->brouillon()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->type->id,
            'site_id' => $this->site->id,
        ]);

        $this->put(route('depenses.update', $depense), $this->updatePayload(['statut' => 'soumis']))
            ->assertRedirect();

        $this->assertDatabaseHas('depenses', [
            'id' => $depense->id,
            'statut' => StatutDepense::SOUMIS->value,
        ]);
    }

    public function test_update_avec_statut_soumis_depuis_annule_change_statut(): void
    {
        $depense = Depense::factory()->annule()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->type->id,
            'site_id' => $this->site->id,
        ]);

        $this->put(route('depenses.update', $depense), $this->updatePayload(['statut' => 'soumis']))
            ->assertRedirect();

        $this->assertDatabaseHas('depenses', [
            'id' => $depense->id,
            'statut' => StatutDepense::SOUMIS->value,
        ]);
    }

    public function test_update_avec_statut_brouillon_reste_brouillon(): void
    {
        $depense = Depense::factory()->brouillon()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->type->id,
            'site_id' => $this->site->id,
        ]);

        $this->put(route('depenses.update', $depense), $this->updatePayload(['statut' => 'brouillon']))
            ->assertRedirect();

        $this->assertDatabaseHas('depenses', [
            'id' => $depense->id,
            'statut' => StatutDepense::BROUILLON->value,
        ]);
    }

    public function test_update_depuis_statut_soumis_retourne_403(): void
    {
        // Une dépense SOUMISE n'est pas modifiable (policy::update interdit SOUMIS)
        $depense = Depense::factory()->soumis()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->type->id,
            'site_id' => $this->site->id,
        ]);

        $this->put(route('depenses.update', $depense), $this->updatePayload())
            ->assertForbidden();
    }

    public function test_update_depuis_statut_valide_retourne_403(): void
    {
        $depense = Depense::factory()->valide()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->type->id,
            'site_id' => $this->site->id,
        ]);

        $this->put(route('depenses.update', $depense), $this->updatePayload())
            ->assertForbidden();
    }

    // ── Soumission directe (bouton "Soumettre" depuis l'Index / Show) ─────────

    public function test_soumettre_brouillon_change_statut_en_soumis(): void
    {
        $depense = Depense::factory()->brouillon()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->type->id,
            'site_id' => $this->site->id,
        ]);

        $this->patch(route('depenses.soumettre', $depense))
            ->assertRedirect();

        $this->assertDatabaseHas('depenses', [
            'id' => $depense->id,
            'statut' => StatutDepense::SOUMIS->value,
        ]);
    }

    public function test_soumettre_rejete_change_statut_en_soumis(): void
    {
        $depense = Depense::factory()->rejete()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->type->id,
            'site_id' => $this->site->id,
        ]);

        $this->patch(route('depenses.soumettre', $depense))
            ->assertRedirect();

        $this->assertDatabaseHas('depenses', [
            'id' => $depense->id,
            'statut' => StatutDepense::SOUMIS->value,
        ]);
    }

    public function test_soumettre_depense_deja_soumise_retourne_erreur(): void
    {
        $depense = Depense::factory()->soumis()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->type->id,
            'site_id' => $this->site->id,
        ]);

        $this->patch(route('depenses.soumettre', $depense))
            ->assertSessionHasErrors(['statut']);

        $this->assertDatabaseHas('depenses', [
            'id' => $depense->id,
            'statut' => StatutDepense::SOUMIS->value, // inchangé
        ]);
    }

    public function test_soumettre_depense_validee_retourne_erreur(): void
    {
        $depense = Depense::factory()->valide()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->type->id,
            'site_id' => $this->site->id,
        ]);

        $this->patch(route('depenses.soumettre', $depense))
            ->assertSessionHasErrors(['statut']);
    }

    // ── Données modifiées lors de la transition ───────────────────────────────

    public function test_update_soumis_conserve_nouveau_montant(): void
    {
        $depense = Depense::factory()->rejete()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->type->id,
            'site_id' => $this->site->id,
            'montant' => 5000,
        ]);

        $this->put(route('depenses.update', $depense), $this->updatePayload([
            'statut' => 'soumis',
            'montant' => 12345,
        ]))->assertRedirect();

        $this->assertDatabaseHas('depenses', [
            'id' => $depense->id,
            'montant' => 12345,
            'statut' => StatutDepense::SOUMIS->value,
        ]);
    }
}
