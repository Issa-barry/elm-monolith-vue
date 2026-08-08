<?php

namespace Tests\Unit;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Génération du "trinôme" (code court identifiant l'organisation avant
 * connexion, cf. FortifyServiceProvider::configureLoginByCodeRoute()).
 */
class OrganizationCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_genere_les_initiales_des_mots_du_nom(): void
    {
        $this->assertSame('ELM', Organization::generateCode('Eau la maman'));
    }

    public function test_complete_avec_les_lettres_du_premier_mot_si_moins_de_trois_mots(): void
    {
        // "Fello" + "Demo" -> F, D, puis complète avec la 3e lettre de "Fello" (L)
        $this->assertSame('FDL', Organization::generateCode('Fello Demo'));
    }

    public function test_complete_avec_x_si_le_nom_ne_fournit_pas_assez_de_lettres(): void
    {
        $this->assertSame('AXX', Organization::generateCode('A'));
    }

    public function test_est_assigne_automatiquement_a_la_creation(): void
    {
        $org = Organization::factory()->create(['name' => 'Eau la maman', 'code' => null]);

        $this->assertNotEmpty($org->code);
        $this->assertSame('ELM', $org->code);
    }

    public function test_est_unique_meme_pour_des_noms_donnant_le_meme_trinome(): void
    {
        $orgA = Organization::factory()->create(['name' => 'Fello Demo']);
        $orgB = Organization::factory()->create(['name' => 'Fello Demo']);

        $this->assertNotSame($orgA->code, $orgB->code);
        $this->assertSame('FDL', $orgA->code);
        $this->assertSame('FDL1', $orgB->code);
    }

    public function test_ne_regenere_pas_le_code_si_deja_fourni(): void
    {
        $org = Organization::factory()->create(['name' => 'Fello Demo', 'code' => 'CUSTOM']);

        $this->assertSame('CUSTOM', $org->code);
    }
}
