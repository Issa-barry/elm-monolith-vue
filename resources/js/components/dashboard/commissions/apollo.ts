/**
 * Reproduction du tableau de bord Ecommerce d'Apollo (_template/apollo-vue-6.2.0, composants
 * StatsEcommerceWidget, RevenueOverviewWidget, SalesByCategoryWidget). Apollo fixe
 * `html { font-size: 14px }` alors qu'ELM garde 16 px : chaque taille Apollo en rem est donc
 * reprise en px (valeur rem × 14) pour un rendu identique — même méthode que KpiCard.vue.
 */

/**
 * `.card` d'Apollo (assets/layout/_utils.scss) : fond et bordure des tokens PrimeVue, rayon 12 px,
 * padding 2rem (28 px), ombre --card-shadow du thème clair (aucune en sombre). Le texte hérite de
 * la base d'Apollo : 14 px, interligne 1,2, couleur --text-color, Poppins.
 */
export const CARTE_APOLLO =
    'h-full rounded-[12px] border border-[var(--p-content-border-color)] bg-[var(--p-content-background)] p-7 font-apollo text-[14px] leading-[1.2] text-[var(--p-text-color)] antialiased shadow-[0px_4px_30px_rgba(221,224,255,0.54)] dark:shadow-none';

/** `text-surface-900 dark:text-surface-0 text-xl font-semibold` (titres des graphiques). */
export const TITRE_GRAPHIQUE_APOLLO =
    'text-[17.5px] leading-[24.5px] font-semibold text-[var(--p-surface-900)] dark:text-[var(--p-surface-0)]';

/** Montant entier en GNF, séparateur de milliers lisible (comme formatGNF). */
export function montantGnf(valeur: number): string {
    return (
        new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 })
            .format(valeur)
            .replace(/[  ]/g, ' ') + ' GNF'
    );
}

/** Montant abrégé pour les chiffres des cartes et l'axe des graphiques : 53,4 M, 1,2 Md. */
export function montantCompact(valeur: number): string {
    return new Intl.NumberFormat('fr-FR', {
        notation: 'compact',
        maximumFractionDigits: 1,
    })
        .format(valeur)
        .replace(/[  ]/g, ' ');
}

/** « +12,5 % », « -8 % », « 0 % ». */
export function evolutionTexte(evolution: number): string {
    const valeur = new Intl.NumberFormat('fr-FR', {
        maximumFractionDigits: 1,
    }).format(Math.abs(evolution));
    const signe = evolution > 0 ? '+' : evolution < 0 ? '-' : '';

    return `${signe}${valeur} %`;
}

/**
 * Tracé SVG d'une courbe lissée dans la viewBox d'une mini-courbe Apollo, à partir des montants
 * réels (une valeur par tranche). Marge d'1 unité comme les tracés d'origine ; une série plate ou
 * d'un seul point donne un trait horizontal.
 */
export function traceCourbe(
    valeurs: number[],
    largeur: number,
    hauteur: number,
): string {
    const marge = 1;
    const points =
        valeurs.length > 1 ? valeurs : [valeurs[0] ?? 0, valeurs[0] ?? 0];
    const min = Math.min(...points);
    const max = Math.max(...points);
    const pasX = (largeur - 2 * marge) / (points.length - 1);
    const y = (v: number) =>
        max === min
            ? hauteur - marge
            : hauteur -
              marge -
              ((v - min) / (max - min)) * (hauteur - 2 * marge);
    const arrondi = (n: number) => Math.round(n * 100) / 100;

    let trace = `M${arrondi(marge)} ${arrondi(y(points[0]))}`;
    for (let i = 1; i < points.length; i++) {
        const x0 = marge + (i - 1) * pasX;
        const x1 = marge + i * pasX;
        const milieu = arrondi((x0 + x1) / 2);
        trace += `C${milieu} ${arrondi(y(points[i - 1]))} ${milieu} ${arrondi(y(points[i]))} ${arrondi(x1)} ${arrondi(y(points[i]))}`;
    }

    return trace;
}
