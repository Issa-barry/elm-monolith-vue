<?php

namespace Tests\Feature\Comptabilite;

use App\Models\Depense;
use App\Models\DepenseType;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contrôle de cohérence métier ↔ comptabilité (Phase 7). Lecture seule : ne
 * modifie jamais rien, contrairement à ComptabiliteRattrapageCommand.
 */
class ComptabiliteAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
    }

    public function test_organisation_sans_aucune_donnee_est_coherente(): void
    {
        $this->artisan('comptabilite:auditer', ['--organization' => [$this->org->id]])
            ->assertExitCode(0);
    }

    public function test_detecte_une_depense_eligible_non_comptabilisee(): void
    {
        $type = DepenseType::factory()->interne()->create(['organization_id' => $this->org->id]);
        // Créée en un seul INSERT (factory) : jamais comptabilisée (DepenseObserver
        // n'écoute que `updated`), donc "éligible mais non comptabilisée".
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'depense_type_id' => $type->id,
            'statut' => 'valide',
            'montant' => 15000,
        ]);

        $this->artisan('comptabilite:auditer', ['--organization' => [$this->org->id]])
            ->assertExitCode(1);
    }

    public function test_devient_coherent_apres_rattrapage(): void
    {
        $type = DepenseType::factory()->interne()->create(['organization_id' => $this->org->id]);
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'depense_type_id' => $type->id,
            'statut' => 'valide',
            'montant' => 15000,
        ]);

        $this->artisan('comptabilite:auditer', ['--organization' => [$this->org->id]])->assertExitCode(1);

        $this->artisan('comptabilite:rattraper', ['--organization' => [$this->org->id]])->assertExitCode(0);

        $this->artisan('comptabilite:auditer', ['--organization' => [$this->org->id]])->assertExitCode(0);
    }

    public function test_depense_validee_normalement_via_update_est_deja_coherente(): void
    {
        $type = DepenseType::factory()->interne()->create(['organization_id' => $this->org->id]);
        $depense = Depense::factory()->brouillon()->create([
            'organization_id' => $this->org->id,
            'depense_type_id' => $type->id,
            'montant' => 15000,
        ]);
        // Transition réelle (update), déclenche DepenseObserver comme en production.
        $depense->update(['statut' => 'valide']);

        $this->artisan('comptabilite:auditer', ['--organization' => [$this->org->id]])
            ->assertExitCode(0);
    }

    public function test_organisation_introuvable_echoue_proprement(): void
    {
        $this->artisan('comptabilite:auditer', ['--organization' => ['org-inexistante']])
            ->assertExitCode(1);
    }

    public function test_isolation_entre_organisations(): void
    {
        $orgB = Organization::factory()->create();
        $type = DepenseType::factory()->interne()->create(['organization_id' => $orgB->id]);
        // Dépense non comptabilisée sur orgB uniquement — ne doit jamais affecter
        // le résultat de l'audit scopé sur $this->org.
        Depense::factory()->create([
            'organization_id' => $orgB->id,
            'depense_type_id' => $type->id,
            'statut' => 'valide',
            'montant' => 15000,
        ]);

        $this->artisan('comptabilite:auditer', ['--organization' => [$this->org->id]])
            ->assertExitCode(0);
    }
}
