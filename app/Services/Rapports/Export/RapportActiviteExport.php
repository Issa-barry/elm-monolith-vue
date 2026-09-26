<?php

namespace App\Services\Rapports\Export;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Classeur Excel du rapport d'activité / de « Ma situation » : une feuille par onglet de l'écran,
 * construites depuis les mêmes données (RapportActiviteService, sans limite de lignes).
 */
class RapportActiviteExport implements WithMultipleSheets
{
    private const ANOMALIES = [
        'reference_absente' => 'Référence absente',
        'reference_dupliquee' => 'Référence déjà utilisée',
        'anterieure_obligation' => "Sans référence (antérieur à l'obligation)",
    ];

    /**
     * @param  array<string, mixed>  $rapport  RapportActiviteService::rapport()
     * @param  array<string, string>  $entete  libellés « Périmètre », « Période », etc.
     */
    public function __construct(
        private readonly array $rapport,
        private readonly array $entete,
    ) {}

    public static function libelleAnomalie(?string $anomalie): string
    {
        return $anomalie === null ? '' : (self::ANOMALIES[$anomalie] ?? $anomalie);
    }

    /** @return list<RapportFeuille> */
    public function sheets(): array
    {
        return [
            $this->resume(),
            $this->ventes(),
            $this->encaissements(),
            $this->creances(),
            $this->mobileMoney(),
            $this->caisse(),
        ];
    }

    private function resume(): RapportFeuille
    {
        $v = $this->rapport['ventes']['resume'];
        $e = $this->rapport['encaissements'];
        $c = $this->rapport['creances']['resume'];
        $m = $this->rapport['mobile_money']['resume'];
        $k = $this->rapport['caisse']['resume'];

        $lignes = [];
        foreach ($this->entete as $libelle => $valeur) {
            $lignes[] = [$libelle, $valeur];
        }
        $lignes[] = ['', ''];
        $lignes[] = ['Ventes de la période (nombre)', $v['nombre']];
        $lignes[] = ['Montant facturé', $v['facture']];
        $lignes[] = ['Encaissé sur ces ventes', $v['encaisse']];
        $lignes[] = ['Reste à payer sur ces ventes', $v['reste']];
        $lignes[] = ['Ventes annulées / retournées (hors CA)', $v['annulees_nombre'].' — '.$v['annulees_montant']];
        $lignes[] = ['Encaissements de la période (nombre)', $e['resume']['nombre']];
        $lignes[] = ['Montant encaissé', $e['resume']['montant']];
        foreach ($e['par_moyen'] as $moyen) {
            $lignes[] = ['  dont '.$moyen['libelle'], $moyen['montant']];
        }
        $lignes[] = ['Créances en cours (état actuel, toutes dates)', $c['nombre']];
        $lignes[] = ['  dont impayées', $c['impayees']];
        $lignes[] = ['  dont partielles', $c['partielles']];
        $lignes[] = ['Reste dû', $c['reste']];
        $lignes[] = ['Mobile Money — références absentes', $m['reference_absente']];
        $lignes[] = ['Mobile Money — références déjà utilisées', $m['reference_dupliquee']];
        $lignes[] = ['Caisse — solde de début', $k['solde_debut']];
        $lignes[] = ['Caisse — solde de fin', $k['solde_fin']];
        $lignes[] = ['Caisse — solde actuel (à remettre, théorique)', $k['solde_actuel']];
        $lignes[] = ['Caisse — en cours de versement', $k['en_cours_montant']];

        return new RapportFeuille('Résumé', ['Indicateur', 'Valeur'], $lignes);
    }

    private function ventes(): RapportFeuille
    {
        return new RapportFeuille(
            'Ventes',
            ['Facture', 'Date', 'Client', 'Agent', 'Agence', 'Montant', 'Encaissé', 'Reste', 'Statut'],
            array_map(fn (array $l) => [
                $l['reference'], $l['date'], $l['client'], $l['agent'], $l['site_nom'],
                $l['montant'], $l['encaisse'], $l['reste'], $l['statut_label'],
            ], $this->rapport['ventes']['lignes']),
        );
    }

    private function encaissements(): RapportFeuille
    {
        return new RapportFeuille(
            'Encaissements',
            ['Date encaissement', 'Saisi le', 'Facture', 'Client', 'Agent', 'Agence', 'Moyen', 'Référence', 'Montant'],
            array_map(fn (array $l) => [
                $l['date_encaissement'], $l['saisi_le'], $l['facture_reference'], $l['client'], $l['agent'],
                $l['site_nom'], $l['moyen_libelle'], $l['reference_paiement'], $l['montant'],
            ], $this->rapport['encaissements']['lignes']),
        );
    }

    private function creances(): RapportFeuille
    {
        return new RapportFeuille(
            'Créances',
            ['Facture', 'Date', 'Ancienneté (jours)', 'Client', 'Agent', 'Agence', 'Montant', 'Encaissé', 'Reste', 'Statut'],
            array_map(fn (array $l) => [
                $l['reference'], $l['date'], $l['anciennete_jours'], $l['client'], $l['agent'], $l['site_nom'],
                $l['montant'], $l['encaisse'], $l['reste'], $l['statut_label'],
            ], $this->rapport['creances']['lignes']),
        );
    }

    private function mobileMoney(): RapportFeuille
    {
        return new RapportFeuille(
            'Mobile Money',
            ['Date encaissement', 'Saisi le', 'Opérateur', 'Référence', 'Montant', 'Facture', 'Client', 'Agent', 'Agence', 'Contrôle'],
            array_map(fn (array $l) => [
                $l['date_encaissement'], $l['saisi_le'], $l['moyen_libelle'], $l['reference_paiement'], $l['montant'],
                $l['facture_reference'], $l['client'], $l['agent'], $l['site_nom'], self::libelleAnomalie($l['anomalie']),
            ], $this->rapport['mobile_money']['lignes']),
        );
    }

    private function caisse(): RapportFeuille
    {
        $lignes = [];
        foreach ($this->rapport['caisse']['fiches'] as $f) {
            $autres = array_filter($f['mouvements'], fn (array $m) => ! in_array($m['categorie'], ['encaissements', 'versements_envoyes'], true));
            $lignes[] = [
                $f['caisse']['agent_nom'], $f['caisse']['site_nom'], $f['caisse']['libelle'],
                $f['solde_debut'],
                $this->montantCategorie($f, 'encaissements', 'entrees'),
                $this->montantCategorie($f, 'versements_envoyes', 'sorties'),
                round(array_sum(array_column($autres, 'entrees')) - array_sum(array_column($autres, 'sorties')), 2),
                $f['solde_fin'],
                $f['solde_actuel'],
                $f['en_cours']['montant'],
                $f['dernier_versement']['date_envoi'] ?? '',
            ];
        }

        return new RapportFeuille(
            'Caisse',
            ['Agent', 'Agence', 'Caisse', 'Solde début', 'Encaissements espèces', 'Versements envoyés', 'Autres mouvements (net)', 'Solde fin', 'Solde actuel', 'En cours de versement', 'Dernier versement'],
            $lignes,
        );
    }

    /**
     * @param  array<string, mixed>  $fiche
     */
    private function montantCategorie(array $fiche, string $categorie, string $sens): float
    {
        foreach ($fiche['mouvements'] as $m) {
            if ($m['categorie'] === $categorie) {
                return $m[$sens];
            }
        }

        return 0.0;
    }
}
