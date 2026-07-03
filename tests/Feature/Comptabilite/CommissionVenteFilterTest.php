<?php

namespace Tests\Feature\Comptabilite;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class CommissionVenteFilterTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->initOrgAndUser(['comptabilite.read']);
        $this->actingAs($this->user);
    }

    // ── Régression : DataFilters envoie toujours les champs "select" sous
    // forme de tableau (ex: statut[]=impaye), même pour un choix unique.
    // Avant le fix, (string) $request->input('statut', '') plantait avec
    // "Array to string conversion" dès qu'un filtre était sélectionné
    // (cf. Sentry — même bug déjà corrigé sur Commission Logistique mais
    // jamais reporté ici). ────────────────────────────────────────────────────

    public function test_index_avec_statut_envoye_en_tableau_ne_plante_pas(): void
    {
        $this->get('/backoffice/comptabilite/commissions/vente?'.http_build_query(['statut' => ['impaye']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Comptabilite/CommissionVente/Index')
                ->where('filtre_statut', 'impaye')
            );
    }

    public function test_index_avec_periode_envoyee_en_tableau_ne_plante_pas(): void
    {
        $this->get('/backoffice/comptabilite/commissions/vente?'.http_build_query(['periode' => ['2026-06-P1']]))
            ->assertOk();
    }

    public function test_index_avec_site_ids_envoyes_ne_plante_pas(): void
    {
        $this->get('/backoffice/comptabilite/commissions/vente?'.http_build_query(['site_ids' => [$this->user->sites()->first()->id]]))
            ->assertOk();
    }

    public function test_export_excel_avec_filtres_en_tableau_ne_plante_pas(): void
    {
        $this->get('/backoffice/comptabilite/commissions/vente/export/excel?'.http_build_query([
            'statut' => ['impaye'],
            'periode' => ['2026-06-P1'],
        ]))->assertOk();
    }

    public function test_export_pdf_avec_filtres_en_tableau_ne_plante_pas(): void
    {
        $this->get('/backoffice/comptabilite/commissions/vente/export/pdf?'.http_build_query([
            'statut' => ['impaye'],
            'periode' => ['2026-06-P1'],
        ]))->assertOk();
    }
}
