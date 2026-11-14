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
        return array_values(array_filter([
            $this->resume(),
            $this->ventes(),
            $this->encaissements(),
            $this->creances(),
            $this->mobileMoney(),
            $this->caisse(),
            $this->comptesMobileMoney(),
            $this->charges(),
            $this->agents(),
        ]));
    }

    /** Charges saisies par les agents (dépenses validées ou en attente de validation). */
    private function charges(): RapportFeuille
    {
        return new RapportFeuille(
            'Charges',
            ['Date', 'Type', 'Catégorie', 'Avance récupérable', 'Agence', 'Saisie par', 'Montant', 'Statut'],
            array_map(fn (array $l) => [
                $l['date'], $l['type'], $l['categorie'], $l['avance_tiers'] ? 'Oui' : 'Non', $l['site_nom'], $l['agent'],
                $l['montant'], $l['statut_label'],
            ], $this->rapport['charges']['lignes'] ?? []),
        );
    }

    /** Rapport d'activité : le tableau par agent de l'écran (absent de « Ma situation »). */
    private function agents(): ?RapportFeuille
    {
        if (($this->rapport['agents'] ?? null) === null) {
            return null;
        }

        $moyens = $this->rapport['moyens'];
        $entetes = [
            'Agent', 'Agence de rattachement', 'Ventes (nombre)', 'Ventes',
            ...array_map(fn (array $m) => 'Encaissé '.$m['libelle'], $moyens),
            'Total encaissé', 'Dettes clients', 'Charges saisies', 'dont en attente',
            "Remis à l'agence (sur la période)", 'En transit (à ce jour)', 'Reste à remettre (à ce jour)', 'Statut de remise',
        ];

        $lignes = array_map(function (array $a) use ($moyens) {
            $r = $a['remise'];

            return [
                $a['agent'], implode(', ', $a['agences']), $a['ventes_nombre'], $a['ventes_montant'],
                ...array_map(fn (array $m) => $a['par_moyen'][$m['cle']]['montant'] ?? 0.0, $moyens),
                $a['encaisse_montant'], $a['dettes_montant'], $a['charges_saisies'], $a['charges_en_attente'],
                $r['remis'] ?? '', $r['en_transit'] ?? '', $r['reste_a_remettre'] ?? '', $r['statut_label'] ?? 'Aucune caisse dédiée',
            ];
        }, $this->rapport['agents']['lignes']);

        return new RapportFeuille('Agents', $entetes, $lignes);
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
        // Mêmes colonnes que l'écran (Espèces, Orange Money, puis tout moyen utilisé) ; total = leur somme.
        $parMoyen = array_column($e['par_moyen'], 'montant', 'cle');
        foreach ($this->rapport['moyens'] ?? [] as $moyen) {
            $lignes[] = ['Encaissé '.$moyen['libelle'], $parMoyen[$moyen['cle']] ?? 0.0];
        }
        $lignes[] = ['Total encaissé', $e['resume']['montant']];
        $lignes[] = ["  dont pour d'autres agences (à reverser)", $e['resume']['pour_autres_agences_montant']];
        $lignes[] = ['Dettes clients sur la période (factures de la période encore dues)', $c['nombre']];
        $lignes[] = ['  dont impayées', $c['impayees']];
        $lignes[] = ['  dont partielles', $c['partielles']];
        $lignes[] = ['Reste dû', $c['reste']];
        $ch = $this->rapport['charges']['resume'] ?? null;
        if ($ch !== null) {
            $lignes[] = ['Charges saisies (validées + en attente)', $ch['charges_saisies']];
            $lignes[] = ['  dont validées (payées)', $ch['charges_payees']];
            $lignes[] = ['  dont en attente de validation', $ch['charges_en_attente']];
            $lignes[] = ['  dont avances récupérables', $ch['charges_avances_tiers']];
        }
        $r = $this->rapport['remise'] ?? null;
        $lignes[] = ["Remise à l'agence de rattachement", $r === null ? 'Aucune caisse dédiée' : implode(', ', $r['agences'])];
        if ($r !== null) {
            $lignes[] = ['  Remis sur la période (réception confirmée)', $r['remis']];
            $lignes[] = ['  En transit (à ce jour)', $r['en_transit']];
            $lignes[] = ['  Reste à remettre (à ce jour)', $r['reste_a_remettre']];
            $lignes[] = ['  Statut', $r['statut_label']];
        }
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
            ['Facture', 'Date', 'Client', 'Véhicule', 'Agent', 'Créée à', 'Encaissée à', 'Montant', 'Encaissé', 'Reste', 'Statut'],
            array_map(fn (array $l) => [
                $l['reference'], $l['date'], $l['client'], $l['vehicule_nom'], $l['agent'], $l['site_nom'], $l['encaisse_a'],
                $l['montant'], $l['encaisse'], $l['reste'], $l['statut_label'],
            ], $this->rapport['ventes']['lignes']),
        );
    }

    private function encaissements(): RapportFeuille
    {
        return new RapportFeuille(
            'Encaissements',
            ['Date encaissement', 'Saisi le', 'Facture', 'Client', 'Agent', 'Créée à', 'Encaissée à', 'Moyen', 'Référence', 'Montant'],
            array_map(fn (array $l) => [
                $l['date_encaissement'], $l['saisi_le'], $l['facture_reference'], $l['client'], $l['agent'],
                $l['site_nom'], $l['encaisse_a'], $l['moyen_libelle'], $l['reference_paiement'], $l['montant'],
            ], $this->rapport['encaissements']['lignes']),
        );
    }

    private function creances(): RapportFeuille
    {
        return new RapportFeuille(
            'Dettes clients',
            ['Facture', 'Date', 'Ancienneté (jours)', 'Client', 'Véhicule', 'Agent', 'Créée à', 'Encaissée à', 'Montant', 'Encaissé', 'Reste', 'Statut'],
            array_map(fn (array $l) => [
                $l['reference'], $l['date'], $l['anciennete_jours'], $l['client'], $l['vehicule_nom'], $l['agent'], $l['site_nom'], $l['encaisse_a'],
                $l['montant'], $l['encaisse'], $l['reste'], $l['statut_label'],
            ], $this->rapport['creances']['lignes']),
        );
    }

    private function mobileMoney(): RapportFeuille
    {
        return new RapportFeuille(
            'Mobile Money',
            ['Date encaissement', 'Saisi le', 'Opérateur', 'Référence', 'Montant', 'Facture', 'Client', 'Agent', 'Créée à', 'Encaissée à', 'Contrôle'],
            array_map(fn (array $l) => [
                $l['date_encaissement'], $l['saisi_le'], $l['moyen_libelle'], $l['reference_paiement'], $l['montant'],
                $l['facture_reference'], $l['client'], $l['agent'], $l['site_nom'], $l['encaisse_a'], self::libelleAnomalie($l['anomalie']),
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

    /** Comptes Mobile Money des agents (ADR 0027) : même lecture que la caisse, frais à part. */
    private function comptesMobileMoney(): RapportFeuille
    {
        $lignes = [];
        foreach ($this->rapport['comptes_mobile_money']['fiches'] ?? [] as $f) {
            $autres = array_filter($f['mouvements'], fn (array $m) => ! in_array($m['categorie'], ['encaissements', 'versements_envoyes', 'frais_transfert'], true));
            $lignes[] = [
                $f['caisse']['agent_nom'], $f['caisse']['site_nom'], $f['caisse']['libelle'],
                $f['solde_debut'],
                $this->montantCategorie($f, 'encaissements', 'entrees'),
                $this->montantCategorie($f, 'versements_envoyes', 'sorties'),
                $this->montantCategorie($f, 'frais_transfert', 'sorties'),
                round(array_sum(array_column($autres, 'entrees')) - array_sum(array_column($autres, 'sorties')), 2),
                $f['solde_fin'],
                $f['solde_actuel'],
                $f['en_cours']['montant'],
                $f['dernier_versement']['date_envoi'] ?? '',
            ];
        }

        return new RapportFeuille(
            'Mobile Money agents',
            ['Agent', 'Agence', 'Compte', 'Solde début', 'Encaissements Mobile Money', 'Transferts envoyés', 'Frais de transfert', 'Autres mouvements (net)', 'Solde fin', 'Solde actuel', 'En cours de transfert', 'Dernier transfert'],
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
