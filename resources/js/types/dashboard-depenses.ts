/**
 * Tableau de bord des dépenses, Stat-Dépenses (App\Services\Depenses\TableauDeBordDepensesService) :
 * toutes les sommes, évolutions et taux sont calculés par le serveur.
 *
 * Égalités garanties par le serveur : total = payées + en attente ; payées = charges + avances. Les
 * rejetées n'entrent dans aucune des deux.
 */

/** Indicateurs en montant avec évolution : total, payées (= validées), charges, avances. */
export type CleIndicateurDepenses = 'total' | 'payees' | 'charges' | 'avances';

export interface IndicateurDepenses {
    montant: number;
    /** Évolution en % par rapport à la période de comparaison ; null = pas de comparaison possible. */
    evolution: number | null;
}

export type IndicateursDepenses = Record<
    CleIndicateurDepenses,
    IndicateurDepenses
>;

/** Dépenses soumises de la période, pas encore validées : une demande, jamais une dette. */
export interface EnAttenteDepenses {
    montant: number;
    nombre: number;
    /** Date (Y-m-d) de la plus ancienne dépense en attente ; null s'il n'y en a aucune. */
    plus_ancienne: string | null;
    /** Jours écoulés depuis cette date de dépense (la date de soumission n'est pas conservée). */
    anciennete_jours: number | null;
}

/** Dépenses de la période dont le statut actuel est « Rejeté ». */
export interface RejeteesDepenses {
    montant: number;
    nombre: number;
    /** Rejetées / (validées + rejetées), en % ; null sans dépense tranchée. */
    taux: number | null;
}

export interface TrancheDepenses extends Record<CleIndicateurDepenses, number> {
    cle: string;
    label: string;
}

export interface EvolutionDepenses {
    granularite: 'jour' | 'mois';
    tranches: TrancheDepenses[];
}

/** Part des dépenses validées (type ou agence) ; id null = « Autres types » ou « Sans agence ». */
export interface PartDepenses {
    id: string | null;
    label: string;
    montant: number;
}
