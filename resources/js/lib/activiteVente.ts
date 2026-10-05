import { formatGNF, formatQuantite } from '@/lib/utils';

/**
 * Résumé lisible des détails d'une entrée du journal d'activité d'une commande
 * (CommandeVenteActiviteService) : montant, acompte, quantité, date prévue. Le motif reste affiché
 * à part par les fiches.
 */
export function resumeActivite(
    details: Record<string, unknown> | null | undefined,
): string {
    if (!details) return '';
    const parties: string[] = [];

    if (typeof details.montant === 'number' && details.montant > 0) {
        parties.push(formatGNF(details.montant));
    }
    if (typeof details.acompte === 'number' && details.acompte > 0) {
        parties.push(`Acompte ${formatGNF(details.acompte)}`);
    }

    // Préparation / retrait : quantités par ligne ; chargement / retour : quantité totale.
    const quantite =
        typeof details.quantite === 'number'
            ? details.quantite
            : details.quantites && typeof details.quantites === 'object'
              ? Object.values(details.quantites as Record<string, unknown>)
                    .map(Number)
                    .filter((q) => Number.isFinite(q))
                    .reduce((total, q) => total + q, 0)
              : null;
    if (quantite !== null) {
        parties.push(
            `${formatQuantite(quantite)} unité${quantite > 1 ? 's' : ''}`,
        );
    }

    if (
        typeof details.date_remise_prevue === 'string' &&
        /^\d{4}-\d{2}-\d{2}$/.test(details.date_remise_prevue)
    ) {
        const [a, m, j] = details.date_remise_prevue.split('-');
        parties.push(`Remise prévue le ${j}/${m}/${a}`);
    }

    return parties.join(' · ');
}
