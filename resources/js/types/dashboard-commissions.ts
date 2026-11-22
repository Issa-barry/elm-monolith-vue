/**
 * Tableau de bord des commissions (App\Services\Commission\TableauDeBordCommissionsService) :
 * toutes les sommes et évolutions sont calculées par le serveur.
 */

/** Partition du total généré (CommissionKpiBuckets) : générées = en attente + à payer + payées. */
export type CompartimentCommission =
    | 'total_genere'
    | 'en_attente_periode'
    | 'payable'
    | 'deja_paye';

export interface IndicateurCommission {
    montant: number;
    /** Évolution en % par rapport à la période de comparaison ; null = pas de comparaison possible. */
    evolution: number | null;
}

export type IndicateursCommissions = Record<
    CompartimentCommission,
    IndicateurCommission
>;

export interface TrancheCommissions
    extends Record<CompartimentCommission, number> {
    cle: string;
    label: string;
}

export interface EvolutionCommissions {
    granularite: 'jour' | 'mois';
    tranches: TrancheCommissions[];
}

export interface RepartitionCommission {
    type: string;
    label: string;
    montant: number;
}
