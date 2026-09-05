export interface CommissionSummary {
    brut_cumule: number;
    frais: number;
    net_a_payer: number;
    deja_paye: number;
    reste_a_payer: number;
    /**
     * Compartiments V2 (CommissionKpiBuckets côté back) — null sur les écrans Legacy/Logistique,
     * qui n'ont pas cette notion de commission "créée mais pas encore éligible au paiement".
     * total_genere === en_attente_periode + payable + deja_paye (à l'arrondi près).
     */
    total_genere: number | null;
    en_attente_periode: number | null;
    payable: number | null;
}

/**
 * Synthèse commune aux pages de liste des commissions.
 *
 * Les contrôleurs restent libres de calculer leurs indicateurs selon leur
 * domaine ; ce contrat ne mutualise que leur présentation.
 */
export interface CommissionIndexSummary {
    generated: number;
    expenses: number;
    netValidated: number;
    remaining: number;
    paid?: number;
}

export interface CommissionVehiculeInfo {
    id: string | null;
    nom: string | null;
    immatriculation: string | null;
}

export interface CommissionDetailRow {
    id?: string;
    commission_id?: string;
    reference: string | null;
    date: string | null;
    site?: string | null;
    periode?: string | null;
    periode_label: string | null;
    vehicule?: CommissionVehiculeInfo | null;
    montant: number;
    paye: number;
    reste: number;
    statut: string | null;
    statut_dot_class?: string | null;
    /** Origine (Vente/Distribution client/Transfert logistique) — absent sur les écrans qui ne
     * ventilent pas encore par processus (Logistique/Propriétaire/Consultant/Site/Cashback/
     * Salaire) ; CommissionDetailTable n'affiche la colonne Origine que si au moins une ligne la
     * fournit. */
    processus?: string | null;
    processus_label?: string | null;
}

export interface CommissionProcessusOption {
    value: string;
    label: string;
}

export interface CommissionExpenseRow {
    id: string;
    date: string | null;
    type: string;
    commentaire: string | null;
    saisi_par: string | null;
    validateur: string | null;
    vehicule?: CommissionVehiculeInfo | null;
    montant: number;
}

export interface CommissionPaymentRow {
    id: string;
    paid_at: string | null;
    montant: number;
    mode_paiement: string;
    note: string | null;
    created_by: string | null;
}

export interface PeriodeOption {
    code: string;
    label: string;
}

export interface ModePaiementOption {
    value: string;
    label: string;
}

export type CommissionDetailTab =
    | 'informations'
    | 'depenses'
    | 'paiements'
    | 'historique';

export interface AgenceOption {
    id: string;
    nom: string;
}

export interface CommissionGlobalFiltersValue {
    periode: string;
    vehicule_ids: (string | number)[];
    site_ids: (string | number)[];
    periode_range?: { debut: string | null; fin: string | null };
    /** Optionnel : seules les pages qui fournissent processusOptions à CommissionGlobalFilters
     * en font usage (fiche détail bénéficiaire). '' = tous les processus. */
    processus?: string;
}
