<?php

namespace Tests\Unit\Periodes;

use App\Support\Periodes\Periode;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Seul calculateur des périodes calendaires (ADR 0057) : bornes, alias historiques, validation
 * commune et filtrage SQL en bornes semi-ouvertes. Aucune base de données.
 */
class PeriodeTest extends TestCase
{
    /** Mercredi 16 septembre 2026, en cours de journée. */
    private function maintenant(string $date = '2026-09-16 10:30:00'): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'UTC');
    }

    private function requete(array $query): Request
    {
        return Request::create('/backoffice/liste', 'GET', $query);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
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
            't1' => ['t1', '2026-01-01 00:00:00', '2026-03-31 23:59:59'],
            't2' => ['t2', '2026-04-01 00:00:00', '2026-06-30 23:59:59'],
            't3' => ['t3', '2026-07-01 00:00:00', '2026-09-30 23:59:59'],
            't4' => ['t4', '2026-10-01 00:00:00', '2026-12-31 23:59:59'],
            's1' => ['s1', '2026-01-01 00:00:00', '2026-06-30 23:59:59'],
            's2' => ['s2', '2026-07-01 00:00:00', '2026-12-31 23:59:59'],
        ];
    }

    #[DataProvider('periodesRapides')]
    public function test_resout_les_periodes_cote_serveur(string $cle, string $debut, string $fin): void
    {
        $periode = Periode::depuisRequete($this->requete(['periode' => $cle]), $this->maintenant());

        $this->assertSame($cle, $periode->cle);
        $this->assertSame($debut, $periode->debut->toDateTimeString());
        $this->assertSame($fin, $periode->fin->toDateTimeString());
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string}> */
    public static function changementsDeCalendrier(): array
    {
        return [
            'mois dernier après un 31 (pas de débordement)' => ['2026-10-31 12:00:00', 'mois_precedent', '2026-09-01', '2026-09-30'],
            'mois dernier en janvier = décembre de l\'année précédente' => ['2027-01-15 08:00:00', 'mois_precedent', '2026-12-01', '2026-12-31'],
            'semaine dernière à cheval sur deux années' => ['2027-01-06 08:00:00', 'semaine_precedente', '2026-12-28', '2027-01-03'],
            'cette semaine un dimanche' => ['2026-11-08 23:00:00', 'cette_semaine', '2026-11-02', '2026-11-08'],
            'cette semaine un lundi' => ['2026-11-02 00:00:00', 'cette_semaine', '2026-11-02', '2026-11-08'],
            'février bissextile' => ['2028-02-10 08:00:00', 'ce_mois', '2028-02-01', '2028-02-29'],
            'mois dernier un 31 mars d\'année bissextile' => ['2028-03-31 08:00:00', 'mois_precedent', '2028-02-01', '2028-02-29'],
            'hier un 1er janvier' => ['2027-01-01 00:30:00', 'hier', '2026-12-31', '2026-12-31'],
            'année dernière un 29 février' => ['2028-02-29 08:00:00', 'annee_precedente', '2027-01-01', '2027-12-31'],
        ];
    }

    #[DataProvider('changementsDeCalendrier')]
    public function test_changements_de_mois_d_annee_et_annee_bissextile(string $maintenant, string $cle, string $debut, string $fin): void
    {
        $periode = Periode::rapide($cle, $this->maintenant($maintenant));

        $this->assertSame([$debut, $fin], [$periode->debut->toDateString(), $periode->fin->toDateString()]);
    }

    public function test_anciennes_cles_restent_acceptees(): void
    {
        foreach (['today' => 'aujourd_hui', 'week' => 'cette_semaine', 'month' => 'ce_mois', 'semaine_derniere' => 'semaine_precedente', 'mois_dernier' => 'mois_precedent'] as $ancienne => $cle) {
            $this->assertSame($cle, Periode::depuisRequete($this->requete(['periode' => $ancienne]), $this->maintenant())->cle, $ancienne);
        }

        $tout = Periode::depuisRequete($this->requete(['periode' => 'all']), $this->maintenant(), defaut: 'ce_mois');
        $this->assertSame([Periode::TOUT, null], [$tout->cle, $tout->debut]);
    }

    public function test_sans_parametre_ou_cle_inconnue_periode_par_defaut(): void
    {
        foreach ([[], ['periode' => 'nimportequoi']] as $query) {
            $periode = Periode::depuisRequete($this->requete($query), $this->maintenant());
            $this->assertSame([Periode::TOUT, null, null], [$periode->cle, $periode->debut, $periode->fin]);
        }

        $defaut = Periode::depuisRequete($this->requete([]), $this->maintenant(), defaut: 'aujourd_hui');
        $this->assertSame(['aujourd_hui', '2026-09-16'], [$defaut->cle, $defaut->debut->toDateString()]);
    }

    public function test_cle_hors_des_choix_de_l_ecran_retombe_sur_le_defaut(): void
    {
        $cles = ['aujourd_hui', 'hier', Periode::PERSONNALISEE];

        $this->assertSame('aujourd_hui', Periode::depuisRequete($this->requete(['periode' => 'tout']), $this->maintenant(), 'periode', 'aujourd_hui', $cles)->cle);
        $this->assertSame('aujourd_hui', Periode::depuisRequete($this->requete(['periode' => 't1']), $this->maintenant(), 'periode', 'aujourd_hui', $cles)->cle);
        $this->assertSame('hier', Periode::depuisRequete($this->requete(['periode' => 'hier']), $this->maintenant(), 'periode', 'aujourd_hui', $cles)->cle);
    }

    public function test_un_autre_parametre_peut_porter_la_cle(): void
    {
        $periode = Periode::depuisRequete($this->requete(['situation_periode' => 'hier', 'periode' => 'ce_mois']), $this->maintenant(), 'situation_periode');

        $this->assertSame('hier', $periode->cle);
    }

    public function test_periode_personnalisee_inclut_les_deux_dates(): void
    {
        $periode = Periode::depuisRequete($this->requete(['date_debut' => '2026-11-01', 'date_fin' => '2026-11-04']), $this->maintenant());

        $this->assertSame(Periode::PERSONNALISEE, $periode->cle);
        $this->assertSame('2026-11-01 00:00:00', $periode->debut->toDateTimeString());
        $this->assertSame('2026-11-04 23:59:59', $periode->fin->toDateTimeString());
        $this->assertSame('2026-11-05 00:00:00', $periode->finExclue()->toDateTimeString());
    }

    public function test_meme_jour_en_debut_et_en_fin(): void
    {
        $periode = Periode::depuisRequete($this->requete(['date_debut' => '2026-11-04', 'date_fin' => '2026-11-04']), $this->maintenant());

        $this->assertSame(['2026-11-04 00:00:00', '2026-11-05 00:00:00'], [$periode->debut->toDateTimeString(), $periode->finExclue()->toDateTimeString()]);
        $this->assertSame('04/11/2026', substr($periode->libelle(), 0, 10));
    }

    public function test_date_de_debut_seule_va_jusqu_a_aujourd_hui(): void
    {
        $periode = Periode::depuisRequete($this->requete(['date_debut' => '2026-09-01']), $this->maintenant());

        $this->assertSame(Periode::PERSONNALISEE, $periode->cle);
        $this->assertSame(['2026-09-01 00:00:00', '2026-09-16 23:59:59'], [$periode->debut->toDateTimeString(), $periode->fin->toDateTimeString()]);
        $this->assertTrue($periode->finOuverte);
        // Fin renvoyée vide au filtre : une vue enregistrée suit le jour courant au lieu de figer cette date.
        $this->assertSame(['periode' => 'personnalisee', 'date_debut' => '2026-09-01', 'date_fin' => null], $periode->valeursFiltre());
        $this->assertSame('Du 01/09/2026 au 16/09/2026', $periode->libelle());
    }

    public function test_date_de_debut_seule_aujourd_hui_est_acceptee(): void
    {
        CarbonImmutable::setTestNow($this->maintenant());

        try {
            $periode = Periode::depuisRequeteValidee($this->requete(['date_debut' => '2026-09-16']));
            $this->assertSame(['2026-09-16', '2026-09-16'], [$periode->debut->toDateString(), $periode->fin->toDateString()]);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_date_de_debut_seule_dans_le_futur_refusee_avec_un_message(): void
    {
        CarbonImmutable::setTestNow($this->maintenant());

        try {
            Periode::depuisRequeteValidee($this->requete(['date_debut' => '2026-09-17']));
            $this->fail('La requête aurait dû être refusée.');
        } catch (ValidationException $e) {
            $this->assertSame(["Sans date de fin, la date de début ne peut pas dépasser aujourd'hui."], $e->errors()['date_debut']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_anciens_noms_de_dates_restent_acceptes(): void
    {
        $periode = Periode::depuisRequete($this->requete(['date_from' => '2026-09-01', 'date_to' => '2026-09-13']), $this->maintenant());

        $this->assertSame([Periode::PERSONNALISEE, '2026-09-01', '2026-09-13'], [$periode->cle, $periode->debut->toDateString(), $periode->fin->toDateString()]);
    }

    public function test_dates_hors_des_choix_de_l_ecran_sont_ignorees(): void
    {
        $periode = Periode::depuisRequete($this->requete(['date_debut' => '2026-09-01', 'date_fin' => '2026-09-13']), $this->maintenant(), cles: ['ce_mois']);

        $this->assertSame(Periode::TOUT, $periode->cle);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function requetesInvalides(): array
    {
        return [
            'date de fin seule' => [['date_fin' => '2026-09-13'], 'date_debut'],
            'date de début seule dans le futur' => [['date_debut' => '2999-01-01'], 'date_debut'],
            'début après la fin' => [['date_debut' => '2026-09-13', 'date_fin' => '2026-09-01'], 'date_fin'],
            'format invalide' => [['date_debut' => '01/09/2026', 'date_fin' => '2026-09-13'], 'date_debut'],
            'date impossible' => [['date_debut' => '2026-02-31', 'date_fin' => '2026-03-05'], 'date_debut'],
            '29 février d\'une année non bissextile' => [['date_debut' => '2027-02-29', 'date_fin' => '2027-03-05'], 'date_debut'],
            'période et dates ensemble' => [['periode' => 'ce_mois', 'date_debut' => '2026-09-01', 'date_fin' => '2026-09-13'], 'periode'],
            'période inconnue' => [['periode' => 'trimestre_dernier'], 'periode'],
            'ancien nom, début après la fin' => [['date_from' => '2026-09-13', 'date_to' => '2026-09-01'], 'date_fin'],
        ];
    }

    #[DataProvider('requetesInvalides')]
    public function test_requete_invalide_refusee_avec_un_message(array $query, string $champ): void
    {
        try {
            Periode::depuisRequeteValidee($this->requete($query));
            $this->fail('La requête aurait dû être refusée.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($champ, $e->errors());
        }
    }

    public function test_message_explicite_si_la_fin_precede_le_debut(): void
    {
        try {
            Periode::depuisRequeteValidee($this->requete(['date_debut' => '2026-09-13', 'date_fin' => '2026-09-01']));
            $this->fail('La requête aurait dû être refusée.');
        } catch (ValidationException $e) {
            $this->assertSame(['La date de fin doit être postérieure ou égale à la date de début.'], $e->errors()['date_fin']);
        }
    }

    public function test_cle_sans_effet_envoyee_avec_des_dates_est_ignoree(): void
    {
        foreach (['all', 'tout', 'personnalisee'] as $cle) {
            $periode = Periode::depuisRequeteValidee($this->requete(['periode' => $cle, 'date_debut' => '2026-09-01', 'date_fin' => '2026-09-13']));
            $this->assertSame(Periode::PERSONNALISEE, $periode->cle, $cle);
        }
    }

    /** Date précise : une journée = mêmes dates de début et de fin, jamais un troisième mode. */
    public function test_date_precise_est_une_journee_aux_dates_identiques(): void
    {
        $cles = ['aujourd_hui', Periode::DATE_PRECISE, Periode::PERSONNALISEE];
        $jour = Periode::depuisRequeteValidee($this->requete(['date_debut' => '2026-11-13', 'date_fin' => '2026-11-13']), 'aujourd_hui', $cles);

        $this->assertSame(Periode::PERSONNALISEE, $jour->cle);
        $this->assertSame('2026-11-13 00:00:00', $jour->debut->toDateTimeString());
        $this->assertSame('2026-11-13 23:59:59', $jour->fin->toDateTimeString());
        $this->assertSame(Periode::DATE_PRECISE, $jour->cleAffichee($cles));
        $this->assertSame(['periode' => 'date_precise', 'date_debut' => '2026-11-13', 'date_fin' => '2026-11-13'], $jour->valeursFiltre('periode', $cles));
        $this->assertSame('13/11/2026', $jour->libelle());

        // Sans le choix dans l'écran, ou pour une vraie plage : la clé reste « personnalisee ».
        $this->assertSame(Periode::PERSONNALISEE, $jour->cleAffichee(Periode::STANDARD));
        $plage = Periode::depuisRequeteValidee($this->requete(['date_debut' => '2026-11-01', 'date_fin' => '2026-11-13']), 'aujourd_hui', $cles);
        $this->assertSame(Periode::PERSONNALISEE, $plage->cleAffichee($cles));
    }

    /** Une date de début seule (fin ouverte = aujourd'hui) n'est pas une date précise, même le jour même. */
    public function test_fin_ouverte_n_est_jamais_une_date_precise(): void
    {
        $cles = [Periode::DATE_PRECISE, Periode::PERSONNALISEE];
        $periode = Periode::depuisRequete($this->requete(['date_debut' => '2026-09-16']), $this->maintenant(), cles: $cles);

        $this->assertSame(Periode::PERSONNALISEE, $periode->cleAffichee($cles));
    }

    public function test_date_precise_n_est_pas_une_cle_envoyable_seule(): void
    {
        try {
            Periode::depuisRequeteValidee($this->requete(['periode' => 'date_precise']), 'aujourd_hui', [Periode::DATE_PRECISE, Periode::PERSONNALISEE]);
            $this->fail('La requête aurait dû être refusée.');
        } catch (ValidationException $e) {
            $this->assertSame(['Période inconnue.'], $e->errors()['periode']);
        }

        $avecDates = Periode::depuisRequeteValidee($this->requete(['periode' => 'date_precise', 'date_debut' => '2026-09-10', 'date_fin' => '2026-09-10']), 'aujourd_hui', [Periode::DATE_PRECISE, Periode::PERSONNALISEE]);
        $this->assertSame(Periode::PERSONNALISEE, $avecDates->cle);
    }

    public function test_date_precise_proposee_seulement_par_les_ecrans_qui_la_listent(): void
    {
        $this->assertNotContains(Periode::DATE_PRECISE, array_column(Periode::options(), 'value'));
        $this->assertSame(
            [['value' => 'hier', 'label' => 'Hier'], ['value' => 'date_precise', 'label' => 'Date précise'], ['value' => 'personnalisee', 'label' => 'Période personnalisée']],
            Periode::options(['hier', Periode::DATE_PRECISE, Periode::PERSONNALISEE]),
        );
    }

    public function test_les_trimestres_ne_sont_acceptes_que_si_l_ecran_les_propose(): void
    {
        $this->expectException(ValidationException::class);
        Periode::depuisRequeteValidee($this->requete(['periode' => 't1']));
    }

    public function test_trimestre_accepte_quand_l_ecran_le_propose(): void
    {
        $periode = Periode::depuisRequeteValidee($this->requete(['periode' => 't2']), 'ce_mois', [...Periode::STANDARD, ...Periode::TRIMESTRES_SEMESTRES]);

        $this->assertSame('t2', $periode->cle);
    }

    public function test_ecran_sans_dates_libres_refuse_des_dates_au_lieu_de_les_ignorer(): void
    {
        try {
            Periode::depuisRequeteValidee($this->requete(['date_debut' => '2026-09-01', 'date_fin' => '2026-09-13']), 'ce_mois', ['ce_mois', Periode::TOUT]);
            $this->fail('La requête aurait dû être refusée.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('date_debut', $e->errors());
        }
    }

    public function test_appliquer_utilise_des_bornes_semi_ouvertes_sans_fonction_sur_la_colonne(): void
    {
        $periode = Periode::depuisRequete($this->requete(['date_debut' => '2026-11-01', 'date_fin' => '2026-11-04']), $this->maintenant());

        $requete = $periode->appliquer(DB::table('ventes'), 'created_at');
        $this->assertSame('select * from "ventes" where "created_at" >= ? and "created_at" < ?', $requete->toSql());
        $this->assertSame(['2026-11-01 00:00:00', '2026-11-05 00:00:00'], $requete->getBindings());

        $date = $periode->appliquer(DB::table('depenses'), 'date_depense', colonneDate: true);
        $this->assertSame(['2026-11-01', '2026-11-05'], $date->getBindings());
    }

    public function test_toutes_les_periodes_n_ajoute_aucune_condition(): void
    {
        $requete = Periode::depuisRequete($this->requete([]), $this->maintenant())->appliquer(DB::table('ventes'), 'created_at');

        $this->assertSame('select * from "ventes"', $requete->toSql());
    }

    public function test_contient_suit_la_meme_regle_que_la_requete(): void
    {
        $periode = Periode::depuisRequete($this->requete(['date_debut' => '2026-11-01', 'date_fin' => '2026-11-04']), $this->maintenant());

        $this->assertTrue($periode->contient(CarbonImmutable::parse('2026-11-04 23:59:59.999999')));
        $this->assertTrue($periode->contient(CarbonImmutable::parse('2026-11-01 00:00:00')));
        $this->assertFalse($periode->contient(CarbonImmutable::parse('2026-11-05 00:00:00')));
        $this->assertFalse($periode->contient(CarbonImmutable::parse('2026-10-31 23:59:59')));
        $this->assertFalse($periode->contient(null));
    }

    public function test_options_standard_dans_l_ordre_du_cahier_des_charges(): void
    {
        $this->assertSame([
            ['value' => 'aujourd_hui', 'label' => "Aujourd'hui"],
            ['value' => 'hier', 'label' => 'Hier'],
            ['value' => 'cette_semaine', 'label' => 'Cette semaine'],
            ['value' => 'semaine_precedente', 'label' => 'Semaine dernière'],
            ['value' => 'ce_mois', 'label' => 'Ce mois'],
            ['value' => 'mois_precedent', 'label' => 'Mois dernier'],
            ['value' => 'cette_annee', 'label' => 'Cette année'],
            ['value' => 'annee_precedente', 'label' => 'Année dernière'],
            ['value' => 'tout', 'label' => 'Toutes les périodes'],
            ['value' => 'personnalisee', 'label' => 'Période personnalisée'],
        ], Periode::options());
    }

    public function test_expose_les_bornes_et_les_valeurs_du_filtre(): void
    {
        $ceMois = Periode::depuisRequete($this->requete(['periode' => 'ce_mois']), $this->maintenant());
        $this->assertSame(['cle' => 'ce_mois', 'date_debut' => '2026-09-01', 'date_fin' => '2026-09-30'], array_slice($ceMois->pourFront(), 0, 3));
        $this->assertSame(['periode' => 'ce_mois', 'date_debut' => null, 'date_fin' => null], $ceMois->valeursFiltre());

        $perso = Periode::depuisRequete($this->requete(['date_debut' => '2026-09-01', 'date_fin' => '2026-09-05']), $this->maintenant());
        $this->assertSame(['periode' => 'personnalisee', 'date_debut' => '2026-09-01', 'date_fin' => '2026-09-05'], $perso->valeursFiltre());
    }

    public function test_options_avec_bornes_pour_un_filtrage_dans_le_navigateur(): void
    {
        $options = Periode::optionsAvecBornes(['hier', 't1', Periode::TOUT], $this->maintenant());

        $this->assertSame([
            ['value' => 'hier', 'label' => 'Hier', 'date_debut' => '2026-09-15', 'date_fin' => '2026-09-15'],
            ['value' => 't1', 'label' => 'T1', 'date_debut' => '2026-01-01', 'date_fin' => '2026-03-31'],
            ['value' => 'tout', 'label' => 'Toutes les périodes', 'date_debut' => null, 'date_fin' => null],
        ], $options);
    }

    public function test_libelles(): void
    {
        $this->assertSame('Cette semaine (du 14/09/2026 au 20/09/2026)', Periode::rapide('cette_semaine', $this->maintenant())->libelle());
        $this->assertSame("Aujourd'hui (16/09/2026)", Periode::rapide('aujourd_hui', $this->maintenant())->libelle());
        $this->assertSame('Du 01/09/2026 au 05/09/2026', Periode::depuisRequete($this->requete(['date_debut' => '2026-09-01', 'date_fin' => '2026-09-05']))->libelle());
        $this->assertSame('Toutes les périodes', Periode::depuisRequete($this->requete([]))->libelle());
    }
}
