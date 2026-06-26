export interface CommissionSummary {
    brut_cumule: number;
    frais: number;
    net_a_payer: number;
    deja_paye: number;
    reste_a_payer: number;
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
    periode?: string | null;
    periode_label: string | null;
    vehicule?: CommissionVehiculeInfo | null;
    montant: number;
    paye: number;
    reste: number;
    statut: string | null;
    statut_dot_class?: string | null;
}

export interface CommissionExpenseRow {
    id: string;
    date: string | null;
    type: string;
    commentaire: string | null;
    saisi_par: string | null;
    validateur: string | null;
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
