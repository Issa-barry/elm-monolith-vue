// Absences des salariés (docs/rh-absences.md) : données préparées par App\Support\Rh\AbsencePresenter.

export interface Option {
    value: string;
    label: string;
}

/** État affiché, valeur de StatusDot.vue : annulée, sinon déduit des dates. */
export type EtatAbsence = 'a_venir' | 'en_cours' | 'terminee' | 'annulee';

export interface AbsenceRow {
    id: string;
    employe_id: string;
    salarie: string;
    matricule: string | null;
    agence: string | null;
    motif_id: string;
    motif: string;
    date_debut: string;
    date_fin: string;
    date_debut_label: string;
    date_fin_label: string;
    duree_jours: number;
    commentaire: string | null;
    a_justificatif: boolean;
    justificatif_nom: string | null;
    statut: 'enregistree' | 'annulee';
    etat: EtatAbsence;
    etat_label: string;
    auteur: string | null;
    saisie_le: string | null;
    // Actions calculées par le serveur (permission, agence de rattachement, statut).
    peut_modifier: boolean;
    peut_annuler: boolean;
}

export interface AbsenceDetail extends AbsenceRow {
    modifie_par: string | null;
    modifie_le: string | null;
    annule_par: string | null;
    annule_le: string | null;
    motif_annulation: string | null;
}

export const BASE_ABSENCES = '/backoffice/absences';

/** « 1 jour », « 3 jours ». */
export function libelleDuree(jours: number): string {
    return `${jours} jour${jours > 1 ? 's' : ''}`;
}

/**
 * Aperçu de la durée dans le formulaire (jours calendaires, bornes incluses) — la durée enregistrée
 * est toujours recalculée par le serveur. null tant que les deux dates ne sont pas valides et
 * ordonnées.
 */
export function dureeEnJours(debut: string, fin: string): number | null {
    const motif = /^(\d{4})-(\d{2})-(\d{2})$/;
    const d = motif.exec(debut);
    const f = motif.exec(fin);
    if (!d || !f) return null;
    const jourDebut = Date.UTC(+d[1], +d[2] - 1, +d[3]);
    const jourFin = Date.UTC(+f[1], +f[2] - 1, +f[3]);
    if (jourFin < jourDebut) return null;
    return Math.round((jourFin - jourDebut) / 86_400_000) + 1;
}
