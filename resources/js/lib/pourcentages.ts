/**
 * Affichage des pourcentages des tableaux de bord (Stat-Ventes, Stat-Commissions, Stat-Dépenses) :
 * toujours une décimale, virgule française, symbole « % » précédé d'une espace (décision du
 * 24/11/2026). Seul l'affichage est arrondi : chaque pourcentage est calculé à partir des valeurs
 * réelles, jamais à partir d'une valeur déjà arrondie, et aucun total n'est ajusté pour atteindre
 * 100,0 %. Une répartition peut donc totaliser 99,9 % ou 100,1 %.
 */

const UNE_DECIMALE = new Intl.NumberFormat('fr-FR', {
    minimumFractionDigits: 1,
    maximumFractionDigits: 1,
});

/** Espaces insécables d'Intl remplacées par une espace simple, comme les montants des cartes. */
function nombre(valeur: number): string {
    return UNE_DECIMALE.format(valeur).replace(/[  ]/g, ' ');
}

/** Valeur déjà exprimée en pourcentage : 56.74 → « 56,7 % », 100 → « 100,0 % ». */
export function formatPourcentage(valeur: number): string {
    return `${nombre(valeur)} %`;
}

/**
 * Part d'une valeur dans un total : 2 000 000 / 3 525 000 → « 56,7 % ». « 0,0 % » sans total ; une
 * part non nulle trop petite pour une décimale s'affiche « < 0,1 % », jamais « 0,0 % ».
 */
export function partEnPourcentage(valeur: number, total: number): string {
    if (total <= 0) {
        return formatPourcentage(0);
    }
    const part = (valeur / total) * 100;
    if (part > 0 && part < 0.05) {
        return `< ${formatPourcentage(0.1)}`;
    }

    return formatPourcentage(part);
}

/** Évolution signée : « +12,5 % », « -8,0 % », « 0,0 % ». */
export function evolutionEnPourcentage(evolution: number): string {
    const signe = evolution > 0 ? '+' : evolution < 0 ? '-' : '';

    return `${signe}${formatPourcentage(Math.abs(evolution))}`;
}

/** Somme des montants d'une répartition (dénominateur des parts). */
export function totalParts(parts: { montant: number }[]): number {
    return parts.reduce((somme, p) => somme + p.montant, 0);
}
