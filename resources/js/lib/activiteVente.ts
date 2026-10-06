import { formatGNF, formatQuantite } from '@/lib/utils';

/** Préparation / retrait : quantités par ligne ; chargement / retour : quantité totale. */
function quantiteTotale(details: Record<string, unknown>): number | null {
    if (typeof details.quantite === 'number') return details.quantite;
    if (!details.quantites || typeof details.quantites !== 'object') {
        return null;
    }
    return Object.values(details.quantites as Record<string, unknown>)
        .map(Number)
        .filter((q) => Number.isFinite(q))
        .reduce((total, q) => total + q, 0);
}

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

    // Changement du mode de remise d'une précommande : nouveau mode, et véhicule en livraison.
    if (details.mode === 'livraison' || details.mode === 'retrait') {
        parties.push(details.mode === 'livraison' ? 'Livraison' : 'Retrait');
        if (typeof details.vehicule === 'string' && details.vehicule) {
            parties.push(details.vehicule);
        }
    }

    if (typeof details.montant === 'number' && details.montant > 0) {
        parties.push(formatGNF(details.montant));
    }
    if (typeof details.acompte === 'number' && details.acompte > 0) {
        parties.push(`Acompte ${formatGNF(details.acompte)}`);
    }

    const quantite = quantiteTotale(details);
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
