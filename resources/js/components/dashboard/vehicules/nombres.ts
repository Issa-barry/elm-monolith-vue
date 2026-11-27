/**
 * Nombres de Stat-Véhicules : véhicules, rotations, jours et quantités d'une catégorie (entiers,
 * séparateur de milliers français) ; jamais d'unité commune entre catégories de produits.
 */
const ENTIER = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 });
const UNE_DECIMALE = new Intl.NumberFormat('fr-FR', {
    minimumFractionDigits: 1,
    maximumFractionDigits: 1,
});

const espaces = (texte: string) => texte.replace(/[  ]/g, ' ');

/** 1250 → « 1 250 ». */
export function nombreTexte(valeur: number): string {
    return espaces(ENTIER.format(valeur));
}

/** 4.25 → « 4,3 ». */
export function decimaleTexte(valeur: number): string {
    return espaces(UNE_DECIMALE.format(valeur));
}

/** 1 → « 1 véhicule » ; 3 → « 3 véhicules » ; 0 → « 0 véhicule ». */
export function pluriel(
    n: number,
    mot: string,
    motPluriel = `${mot}s`,
): string {
    return `${nombreTexte(n)} ${n > 1 ? motPluriel : mot}`;
}

/** Valeur absente parce que sa source n'est pas consultable : tiret. */
export function ouTiret(valeur: number | null): string {
    return valeur === null ? '—' : nombreTexte(valeur);
}
