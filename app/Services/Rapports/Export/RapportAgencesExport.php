<?php

namespace App\Services\Rapports\Export;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Classeur Excel du rapport par agence : un résumé (périmètre, période, totaux) et le tableau de
 * l'écran, une ligne par agence — mêmes données (RapportAgencesService::rapport()).
 */
class RapportAgencesExport implements WithMultipleSheets
{
    /**
     * @param  array<string, mixed>  $rapport  RapportAgencesService::rapport()
     * @param  array<string, string>  $entete  libellés « Agences », « Période », etc.
     */
    public function __construct(
        private readonly array $rapport,
        private readonly array $entete,
    ) {}

    /** @return list<RapportFeuille> */
    public function sheets(): array
    {
        return [$this->resume(), $this->agences()];
    }

    private function resume(): RapportFeuille
    {
        $t = $this->rapport['totaux'];

        $lignes = [];
        foreach ($this->entete as $libelle => $valeur) {
            $lignes[] = [$libelle, $valeur];
        }
        $lignes[] = ['', ''];
        $lignes[] = ['Ventes de la période (nombre)', $t['ventes_nombre']];
        $lignes[] = ['Montant facturé', $t['ventes_montant']];
        $lignes[] = ['Montant encaissé', $t['encaisse_montant']];
        foreach ($this->rapport['moyens'] as $moyen) {
            $lignes[] = ['  dont '.$moyen['libelle'], $t['par_moyen'][$moyen['cle']] ?? 0.0];
        }
        $lignes[] = ["  dont pour d'autres agences (à reverser)", $t['pour_autres_agences']];
        $lignes[] = ['Dettes clients sur la période', $t['dettes_montant']];
        $lignes[] = ['Charges payées (dépenses validées)', $t['charges_payees']];
        $lignes[] = ['  dont avances sur tiers (récupérables)', $t['charges_avances_tiers']];
        $lignes[] = ['Charges en attente de validation (non payées)', $t['charges_en_attente']];
        $lignes[] = ['Remis à la trésorerie principale (reçu sur la période)', $t['remis']];
        // Montants à ce jour, quelle que soit la période choisie (ADR 0016) : jamais mêlés au « Remis ».
        $lignes[] = ['En transit vers la trésorerie principale (à ce jour)', $t['en_transit']];
        $lignes[] = ['Reste à remettre (à ce jour)', $t['reste_a_remettre']];
        if ($t['reste_incomplet']) {
            $lignes[] = ['  Attention', 'Données incomplètes pour au moins une agence : reste minimum'];
        }

        return new RapportFeuille('Résumé', ['Indicateur', 'Valeur'], $lignes);
    }

    private function agences(): RapportFeuille
    {
        $moyens = $this->rapport['moyens'];

        $entetes = [
            'Agence', 'Ventes (nombre)', 'Ventes', 'Encaissé',
            ...array_map(fn (array $m) => $m['libelle'], $moyens),
            "Pour d'autres agences", 'Dettes clients',
            'Charges payées', 'dont avances sur tiers', 'Charges en attente de validation',
            'Remis à la trésorerie principale (sur la période)', 'En transit (à ce jour)', 'Reste à remettre (à ce jour)', 'Disponible (à ce jour)', 'Dernière remise', 'Statut de remise',
        ];

        $lignes = array_map(function (array $a) use ($moyens) {
            $r = $a['remise'];

            return [
                $a['site_nom'], $a['ventes_nombre'], $a['ventes_montant'], $a['encaisse_montant'],
                ...array_map(fn (array $m) => $a['par_moyen'][$m['cle']]['montant'] ?? 0.0, $moyens),
                $a['pour_autres_agences'], $a['dettes_montant'],
                $a['charges_payees'], $a['charges_avances_tiers'], $a['charges_en_attente'],
                $r['remis'] ?? '', $r['en_transit'] ?? '', $r['reste_a_remettre'] ?? '', $r['disponible'] ?? '',
                $r['derniere_remise'] ?? '', $r['statut_label'] ?? '—',
            ];
        }, $this->rapport['agences']);

        return new RapportFeuille('Agences', $entetes, $lignes);
    }
}
