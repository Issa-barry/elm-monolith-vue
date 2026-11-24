<?php

namespace Tests\Unit\Periodes;

use App\Support\Periodes\Periode;
use App\Support\Periodes\TranchesPeriode;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Découpage du graphique d'évolution des tableaux de bord (Stat-Ventes, Stat-Commissions) : une
 * barre par jour jusqu'à 31 jours, par mois au-delà, jamais après aujourd'hui.
 */
class TranchesPeriodeTest extends TestCase
{
    /** Lundi 23 novembre 2026. */
    private function maintenant(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-11-23 11:57:00');
    }

    private function periode(array $query): Periode
    {
        return Periode::depuisRequete(Request::create('/backoffice/dashboard', 'GET', $query), $this->maintenant());
    }

    public function test_un_mois_en_cours_est_jour_par_jour_jusqu_a_aujourd_hui(): void
    {
        $periode = $this->periode(['periode' => 'ce_mois']);
        $decoupage = TranchesPeriode::pour($periode);
        $tranches = $decoupage->tranches($periode, [], $this->maintenant());

        $this->assertSame('jour', $decoupage->granularite());
        $this->assertCount(23, $tranches);
        $this->assertSame(['cle' => '2026-11-01', 'label' => '01/11'], $tranches[0]);
        $this->assertSame(['cle' => '2026-11-23', 'label' => '23/11'], $tranches[22]);
    }

    public function test_31_jours_restent_jour_par_jour_32_jours_passent_au_mois(): void
    {
        $trenteEtUn = TranchesPeriode::pour($this->periode(['date_debut' => '2026-10-01', 'date_fin' => '2026-10-31']));
        $trenteDeux = TranchesPeriode::pour($this->periode(['date_debut' => '2026-10-01', 'date_fin' => '2026-11-01']));

        $this->assertSame('jour', $trenteEtUn->granularite());
        $this->assertSame('mois', $trenteDeux->granularite());
    }

    public function test_dates_libres_bornes_incluses(): void
    {
        $periode = $this->periode(['date_debut' => '2026-10-30', 'date_fin' => '2026-11-02']);

        $this->assertSame(
            ['30/10', '31/10', '01/11', '02/11'],
            array_column(TranchesPeriode::pour($periode)->tranches($periode, [], $this->maintenant()), 'label'),
        );
    }

    public function test_une_periode_sur_deux_annees_precise_l_annee_des_mois(): void
    {
        $periode = $this->periode(['date_debut' => '2025-11-15', 'date_fin' => '2026-02-10']);

        $this->assertSame(
            ['Nov 25', 'Déc 25', 'Jan 26', 'Fév 26'],
            array_column(TranchesPeriode::pour($periode)->tranches($periode, [], $this->maintenant()), 'label'),
        );
    }

    public function test_toutes_les_periodes_vont_du_premier_au_dernier_mois_avec_donnees(): void
    {
        $periode = $this->periode(['periode' => 'tout']);
        $decoupage = TranchesPeriode::pour($periode);

        $this->assertSame('2026-08', $decoupage->cle('2026-08-15'));
        $this->assertSame(
            ['2026-08', '2026-09', '2026-10'],
            array_column($decoupage->tranches($periode, ['2026-10', '2026-08'], $this->maintenant()), 'cle'),
        );
        $this->assertSame([], $decoupage->tranches($periode, [], $this->maintenant()));
    }

    public function test_une_periode_future_n_a_aucune_tranche(): void
    {
        $periode = $this->periode(['date_debut' => '2026-12-01', 'date_fin' => '2026-12-05']);

        $this->assertSame([], TranchesPeriode::pour($periode)->tranches($periode, [], $this->maintenant()));
    }
}
