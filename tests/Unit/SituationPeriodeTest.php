<?php

namespace Tests\Unit;

use App\Support\Vehicules\SituationPeriode;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Période unique de l'onglet Situation d'un véhicule (SituationPeriode) : périodes rapides
 * résolues côté serveur, période personnalisée date_from + date_to. Aucune base de données.
 */
class SituationPeriodeTest extends TestCase
{
    /** Mercredi 16 septembre 2026, en cours de journée. */
    private function maintenant(string $date = '2026-09-16 10:30:00'): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'UTC');
    }

    private function requete(array $query): Request
    {
        return Request::create('/backoffice/vehicules/V1', 'GET', $query);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function periodesRapides(): array
    {
        return [
            'aujourd_hui' => ['aujourd_hui', '2026-09-16 00:00:00', '2026-09-16 23:59:59'],
            'hier' => ['hier', '2026-09-15 00:00:00', '2026-09-15 23:59:59'],
            'cette_semaine (lundi → dimanche)' => ['cette_semaine', '2026-09-14 00:00:00', '2026-09-20 23:59:59'],
            'semaine_precedente' => ['semaine_precedente', '2026-09-07 00:00:00', '2026-09-13 23:59:59'],
            'ce_mois' => ['ce_mois', '2026-09-01 00:00:00', '2026-09-30 23:59:59'],
            'mois_precedent' => ['mois_precedent', '2026-08-01 00:00:00', '2026-08-31 23:59:59'],
            'cette_annee' => ['cette_annee', '2026-01-01 00:00:00', '2026-12-31 23:59:59'],
            'annee_precedente' => ['annee_precedente', '2025-01-01 00:00:00', '2025-12-31 23:59:59'],
        ];
    }

    #[DataProvider('periodesRapides')]
    public function test_resout_les_periodes_rapides_cote_serveur(string $cle, string $debut, string $fin): void
    {
        $periode = SituationPeriode::depuisRequete($this->requete(['situation_periode' => $cle]), $this->maintenant());

        $this->assertSame($cle, $periode->cle);
        $this->assertSame($debut, $periode->debut->toDateTimeString());
        $this->assertSame($fin, $periode->fin->toDateTimeString());
    }

    public function test_sans_parametre_ou_cle_inconnue_toute_la_periode(): void
    {
        foreach ([[], ['situation_periode' => 'tout'], ['situation_periode' => 'nimportequoi'], ['situation_periode' => 'month']] as $query) {
            $periode = SituationPeriode::depuisRequete($this->requete($query), $this->maintenant());

            $this->assertSame('tout', $periode->cle);
            $this->assertNull($periode->debut);
            $this->assertNull($periode->fin);
        }
    }

    public function test_mois_precedent_ne_deborde_pas_apres_un_31(): void
    {
        $periode = SituationPeriode::depuisRequete(
            $this->requete(['situation_periode' => 'mois_precedent']),
            $this->maintenant('2026-03-31 09:00:00'),
        );

        $this->assertSame('2026-02-01', $periode->debut->toDateString());
        $this->assertSame('2026-02-28', $periode->fin->toDateString());
    }

    public function test_periode_personnalisee_inclut_les_deux_bornes(): void
    {
        $periode = SituationPeriode::depuisRequete(
            $this->requete(['date_from' => '2026-09-01', 'date_to' => '2026-09-13']),
            $this->maintenant(),
        );

        $this->assertSame('personnalisee', $periode->cle);
        $this->assertSame('2026-09-01 00:00:00', $periode->debut->toDateTimeString());
        $this->assertSame('2026-09-13 23:59:59', $periode->fin->toDateTimeString());
    }

    public function test_les_dates_l_emportent_sur_une_periode_rapide(): void
    {
        $periode = SituationPeriode::depuisRequete(
            $this->requete(['situation_periode' => 'ce_mois', 'date_from' => '2026-08-10', 'date_to' => '2026-08-12']),
            $this->maintenant(),
        );

        $this->assertSame('personnalisee', $periode->cle);
        $this->assertSame('2026-08-10', $periode->debut->toDateString());
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function periodesPersonnaliseesInvalides(): array
    {
        return [
            'date de fin seule' => [['date_to' => '2026-09-13']],
            'date de début seule' => [['date_from' => '2026-09-01']],
            'début après la fin' => [['date_from' => '2026-09-13', 'date_to' => '2026-09-01']],
            'format invalide' => [['date_from' => '01/09/2026', 'date_to' => '13/09/2026']],
            'date impossible' => [['date_from' => '2026-02-31', 'date_to' => '2026-03-05']],
            'valeur tableau' => [['date_from' => ['2026-09-01'], 'date_to' => '2026-09-13']],
        ];
    }

    #[DataProvider('periodesPersonnaliseesInvalides')]
    public function test_periode_personnalisee_invalide_retombe_sur_toute_la_periode(array $query): void
    {
        $periode = SituationPeriode::depuisRequete($this->requete($query), $this->maintenant());

        $this->assertSame('tout', $periode->cle);
        $this->assertNull($periode->debut);
        $this->assertNull($periode->fin);
    }

    public function test_champs_de_dates_vides_ne_comptent_pas_comme_personnalises(): void
    {
        $periode = SituationPeriode::depuisRequete(
            $this->requete(['situation_periode' => 'ce_mois', 'date_from' => '', 'date_to' => '']),
            $this->maintenant(),
        );

        $this->assertSame('ce_mois', $periode->cle);
    }

    public function test_expose_les_bornes_et_la_liste_des_options_au_frontend(): void
    {
        $donnees = SituationPeriode::depuisRequete(
            $this->requete(['situation_periode' => 'ce_mois']),
            $this->maintenant(),
        )->pourFront();

        $this->assertSame('ce_mois', $donnees['cle']);
        $this->assertSame('2026-09-01', $donnees['date_debut']);
        $this->assertSame('2026-09-30', $donnees['date_fin']);
        $this->assertCount(10, $donnees['options']);
        $this->assertSame(['value' => 'tout', 'label' => 'Toute la période'], $donnees['options'][0]);
        $this->assertSame(['value' => 'personnalisee', 'label' => 'Période personnalisée'], $donnees['options'][9]);
    }
}
