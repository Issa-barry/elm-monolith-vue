<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\StatutPeriodePaiement;
use App\Enums\TypePeriodePaiement;
use App\Models\Organization;
use App\Models\PaiementPeriode;
use App\Services\PeriodePaiementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PeriodePaiementServiceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private PeriodePaiementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        $this->service = app(PeriodePaiementService::class);
    }

    public function test_get_or_create_period_cree_une_periode_brouillon(): void
    {
        $periode = $this->service->getOrCreatePeriod(
            $this->org->id,
            TypePeriodePaiement::LIVREUR,
            Carbon::create(2026, 7, 10),
        );

        $this->assertSame('PAY-202607-P1-LIV', $periode->reference);
        $this->assertSame('2026-07-01', $periode->date_debut->toDateString());
        $this->assertSame('2026-07-15', $periode->date_fin->toDateString());
        $this->assertSame(StatutPeriodePaiement::BROUILLON, $periode->statut);
        $this->assertDatabaseCount('paiement_periodes', 1);
    }

    public function test_get_or_create_period_est_idempotent(): void
    {
        $premiere = $this->service->getOrCreatePeriod($this->org->id, TypePeriodePaiement::LIVREUR, Carbon::create(2026, 7, 10));
        $seconde = $this->service->getOrCreatePeriod($this->org->id, TypePeriodePaiement::LIVREUR, Carbon::create(2026, 7, 12));

        $this->assertSame($premiere->id, $seconde->id);
        $this->assertDatabaseCount('paiement_periodes', 1);
    }

    public function test_get_current_period_utilise_la_date_du_jour(): void
    {
        $this->travelTo('2026-07-20 12:00:00');

        $periode = $this->service->getCurrentPeriod($this->org->id, TypePeriodePaiement::PROPRIETAIRE);

        $this->assertSame('PAY-202607-P2-PRO', $periode->reference);
    }

    public function test_get_next_period_renvoie_la_quinzaine_suivante(): void
    {
        $this->travelTo('2026-07-10 12:00:00');

        $suivante = $this->service->getNextPeriod($this->org->id, TypePeriodePaiement::LIVREUR);

        $this->assertSame('PAY-202607-P2-LIV', $suivante->reference);
    }

    public function test_get_period_by_date_ne_cree_rien_si_absente(): void
    {
        $periode = $this->service->getPeriodByDate($this->org->id, TypePeriodePaiement::SALARIE, Carbon::create(2026, 7, 10));

        $this->assertNull($periode);
        $this->assertDatabaseCount('paiement_periodes', 0);
    }

    public function test_generate_periods_for_year_cree_72_periodes(): void
    {
        $periodes = $this->service->generatePeriodsForYear($this->org->id, 2026);

        // 3 types × 12 mois × 2 quinzaines
        $this->assertCount(72, $periodes);
        $this->assertDatabaseCount('paiement_periodes', 72);
    }

    public function test_generate_periods_for_year_est_idempotent(): void
    {
        $this->service->generatePeriodsForYear($this->org->id, 2026);
        $this->service->generatePeriodsForYear($this->org->id, 2026);

        $this->assertDatabaseCount('paiement_periodes', 72);
    }

    public function test_ne_casse_pas_les_periodes_existantes_avec_lancien_format(): void
    {
        PaiementPeriode::create([
            'organization_id' => $this->org->id,
            'reference' => 'PAY-202607-0001',
            'type' => TypePeriodePaiement::LIVREUR->value,
            'date_debut' => '2026-07-01',
            'date_fin' => '2026-07-15',
            'statut' => StatutPeriodePaiement::CALCULEE->value,
        ]);

        // Le service ne connaît que le nouveau format de référence : il crée donc une
        // nouvelle période à côté de l'ancienne plutôt que de la modifier ou la supprimer.
        $nouvelle = $this->service->getOrCreatePeriod($this->org->id, TypePeriodePaiement::LIVREUR, Carbon::create(2026, 7, 1));

        $this->assertDatabaseHas('paiement_periodes', ['reference' => 'PAY-202607-0001']);
        $this->assertSame('PAY-202607-P1-LIV', $nouvelle->reference);
    }
}
