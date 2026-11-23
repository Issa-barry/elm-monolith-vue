import type { PeriodeFiltre } from '@/types/periode';

/**
 * Paramètres d'URL qui rejouent une période résolue par le serveur : `periode=<clé>`, ou les deux
 * dates d'une période personnalisée. Sert à conserver la période quand un autre filtre de la page
 * (ex. agence) recharge les données.
 */
export function parametresPeriode(
    periode: PeriodeFiltre,
): Record<string, string> {
    if (
        periode.cle === 'personnalisee' &&
        periode.date_debut &&
        periode.date_fin
    ) {
        return { date_debut: periode.date_debut, date_fin: periode.date_fin };
    }

    return { periode: periode.cle };
}
