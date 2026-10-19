/**
 * Filtres Statut / Période des listes de commissions : plusieurs valeurs cochées s'unissent
 * (cf. CommissionStatutFilter / CommissionPeriodeFilter côté backend).
 */

export interface PeriodeCommissionOption {
    code: string;
    label: string;
}

/** Au-delà, l'en-tête affiche un décompte plutôt que la liste complète des libellés. */
const MAX_LIBELLES_PERIODES = 2;

export function libellePeriodes(
    codes: string[],
    periodes: PeriodeCommissionOption[],
): string {
    if (codes.length === 0) return 'Toutes les périodes';
    if (codes.length > MAX_LIBELLES_PERIODES) {
        return `${codes.length} périodes sélectionnées`;
    }

    return codes
        .map((code) => periodes.find((p) => p.code === code)?.label ?? code)
        .join(' + ');
}

/** Ajoute statut[] et periode[] aux paramètres d'export — mêmes clés que DataFilters. */
export function ajouterStatutsEtPeriodes(
    params: URLSearchParams,
    statuts: string[],
    periodes: string[],
): URLSearchParams {
    for (const statut of statuts) params.append('statut[]', statut);
    for (const periode of periodes) params.append('periode[]', periode);
    return params;
}
