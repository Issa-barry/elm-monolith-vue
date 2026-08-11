/**
 * Sortie de `App\Services\CommissionStatusResolver::resolve()` — trois axes distincts
 * (période / équipe / paiement) exposés séparément, plus une projection `display_*`
 * pour le badge principal. Ne jamais recalculer cette logique côté Vue : le backend
 * est la seule source de vérité (voir `PeriodePayabilityChecker` pour le verrou réel).
 */
export interface StatutCommissionResolu {
    periode_status: string | null;
    periode_status_label: string | null;
    team_validation_status: string | null;
    team_validation_status_label: string | null;
    commission_status: string;
    commission_status_label: string;
    payment_status: string;
    payment_status_label: string;
    display_status: string;
    display_label: string;
    can_pay: boolean;
}

export interface PeriodeAffichee {
    id: string;
    reference: string;
    statut: string;
    statut_label: string;
}
