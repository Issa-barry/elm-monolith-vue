<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Proprietaire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Organization::proprietaire_interne_id — relation explicite par organisation qui remplace
 * l'ancien numéro de téléphone codé en dur (+224622602693) de
 * Proprietaire::interneParDefautId(). Couvre : l'action de (re)configuration manuelle
 * (ProprietaireController::definirInterne) et l'isolation multi-tenant.
 */
class ProprietaireInterneTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['proprietaires.read', 'proprietaires.update']);
    }

    // ── ProprietaireController::definirInterne ───────────────────────────────

    public function test_definir_interne_rattache_le_proprietaire_a_lorganisation(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->post("/backoffice/proprietaires/{$proprietaire->id}/definir-interne")
            ->assertRedirect(route('proprietaires.show', $proprietaire));

        $this->assertSame($proprietaire->id, $this->org->fresh()->proprietaire_interne_id);
    }

    public function test_definir_interne_change_le_propriétaire_interne_precedent(): void
    {
        $ancien = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $this->org->forceFill(['proprietaire_interne_id' => $ancien->id])->save();

        $nouveau = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->post("/backoffice/proprietaires/{$nouveau->id}/definir-interne")
            ->assertRedirect(route('proprietaires.show', $nouveau));

        $this->assertSame($nouveau->id, $this->org->fresh()->proprietaire_interne_id);
    }

    public function test_definir_interne_refuse_pour_un_proprietaire_dune_autre_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        $proprietaireAutreOrg = Proprietaire::factory()->create(['organization_id' => $autreOrg->id]);

        $this->actingAs($this->user)
            ->post("/backoffice/proprietaires/{$proprietaireAutreOrg->id}/definir-interne")
            ->assertForbidden();

        $this->assertNull($autreOrg->fresh()->proprietaire_interne_id);
    }

    // ── Isolation multi-tenant ────────────────────────────────────────────────

    public function test_proprietaire_interne_dune_organisation_nest_jamais_utilise_par_une_autre(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $this->org->forceFill(['proprietaire_interne_id' => $proprietaire->id])->save();

        $autreOrg = Organization::factory()->create();

        $this->assertNull(Proprietaire::interneParDefautId($autreOrg->id));
        $this->assertSame($proprietaire->id, Proprietaire::interneParDefautId($this->org->id));
    }
}
