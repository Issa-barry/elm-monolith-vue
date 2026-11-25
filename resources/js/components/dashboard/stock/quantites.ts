/**
 * Quantités de Stat-Stock : nombres entiers d'unités de l'article (pack, sachet…), séparateur de
 * milliers français, jamais d'unité commune entre articles.
 */
const ENTIER = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 });

/** 12 500 → « 12 500 » ; -3 → « -3 ». */
export function quantiteTexte(valeur: number): string {
    return ENTIER.format(valeur).replace(/[  ]/g, ' ');
}

/** Axe des graphiques : valeurs absolues abrégées (« 12,5 k »). */
export function quantiteCompacte(valeur: number): string {
    return new Intl.NumberFormat('fr-FR', {
        notation: 'compact',
        maximumFractionDigits: 1,
    })
        .format(Math.abs(valeur))
        .replace(/[  ]/g, ' ');
}
