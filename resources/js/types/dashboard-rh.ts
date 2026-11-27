/**
 * Tableau de bord RH, Stat-RH lot R1 (App\Services\Rh\TableauDeBordRhService) : tous les chiffres
 * sont calculés par le serveur. Chaque bloc n'est envoyé qu'avec la permission de sa liste RH
 * (Employés, Contrats, Absences) : null sinon.
 */

export interface BlocsRh {
    effectifs: boolean;
    contrats: boolean;
    absences: boolean;
}

/** Effectif à ce jour ; entrées (date d'embauche) et sorties (date de sortie) de la période. */
export interface EffectifsRh {
    actifs: number;
    suspendus: number;
    entrees: number;
    sorties: number;
}

export interface EffectifAgenceRh {
    site_id: string | null;
    site: string;
    actifs: number;
    suspendus: number;
    entrees: number;
    sorties: number;
}

/** Dossiers des salariés présents (actifs et suspendus) auxquels il manque une donnée. */
export interface ACompleterRh {
    presents: number;
    sans_date_embauche: number;
    sans_fonction: number;
    sans_service: number;
    sans_agence: number;
    /** Anciennes fiches « Sorti » sans date de sortie : exclues du taux d'absentéisme. */
    sortis_sans_date: number;
}

/** Contrats en cours des salariés présents ; salariés actifs sans contrat en cours. */
export interface ContratsRh {
    cdi: number;
    cdd: number;
    sans_contrat: number;
}

/** CDD en cours dont la fin prévue est dépassée ou proche (30 jours). */
export interface FinCddRh {
    contrat_id: string;
    /** null sans accès aux fiches salariés : pas de lien. */
    employe_id: string | null;
    salarie: string;
    matricule: string | null;
    site: string;
    date_fin: string;
    /** Jours jusqu'à la fin prévue ; négatif si elle est dépassée. */
    jours: number;
    statut: 'expire_bientot' | 'echeance_depassee';
    statut_label: string;
}

export interface MotifAbsenceRh {
    motif_id: string;
    libelle: string;
    injustifiee: boolean;
    absences: number;
    jours: number;
}

/**
 * Absences enregistrées, en jours calendaires, sur les jours écoulés de la période et dans la
 * période d'emploi connue de chaque salarié.
 */
export interface AbsencesRh {
    jours: number;
    absences: number;
    salaries: number;
    /**
     * Déjà en pourcentage (8,5 = 8,5 %), calculé sur les seuls salariés dont la période d'emploi est
     * connue (date d'embauche, et date de sortie s'ils sont sortis) ; null si non calculable.
     */
    taux: number | null;
    /** Pourquoi le taux manque : période non bornée ou pas commencée, ou aucun salarié retenu. */
    taux_motif: 'periode' | 'aucun_salarie' | null;
    /** Détail du taux, envoyé seulement avec l'accès aux fiches salariés (il révèle l'effectif). */
    jours_attendus: number | null;
    salaries_taux: number | null;
    salaries_exclus: number | null;
    injustifiees_jours: number;
    injustifiees_absences: number;
    absents_aujourdhui: number;
    a_venir: number;
    /** Dernier jour compté (aujourd'hui au plus tard) ; null si la période n'a pas commencé. */
    jusqu_au: string | null;
    par_motif: MotifAbsenceRh[];
}
