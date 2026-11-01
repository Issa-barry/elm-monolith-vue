/**
 * Saisie d'un montant avec séparateur de milliers (« 750000 » s'affiche « 750 000 »). La valeur
 * transmise au serveur reste un nombre, sans aucun séparateur de présentation.
 *
 * Le séparateur affiché est une espace normale : l'espace fine insécable de
 * `toLocaleString('fr-FR')` est quasi invisible dans un champ de saisie.
 */

export interface SaisieMontant {
    valeur: number | null;
    affichage: string;
}

function grouper(entier: string): string {
    return entier.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
}

/**
 * Lit ce que l'utilisateur a tapé ou collé (« 750 000 », « 750.000 », « 1 250,50 »…).
 *
 * Le dernier « , » ou « . » est une virgule décimale, sauf s'il est suivi d'exactement trois
 * chiffres : c'est alors un séparateur de milliers (« 750.000 » = 750 000). Sans décimales
 * autorisées, la partie décimale est ignorée.
 */
export function analyserSaisieMontant(
    texte: string,
    decimales = 0,
): SaisieMontant {
    const brut = texte.replace(/[^\d.,]/g, '');
    if (!/\d/.test(brut)) {
        return { valeur: null, affichage: '' };
    }

    let partieEntiere = brut;
    let fraction: string | null = null;
    const separateur = brut.match(/^(.*)[.,](\d*)$/);
    if (separateur && separateur[2].length !== 3) {
        partieEntiere = separateur[1];
        fraction = separateur[2];
    }

    const entier =
        partieEntiere.replace(/\D/g, '').replace(/^0+(?=\d)/, '') || '0';
    const chiffresDecimaux =
        decimales > 0 && fraction !== null ? fraction.slice(0, decimales) : '';

    const valeur = Number(
        chiffresDecimaux ? `${entier}.${chiffresDecimaux}` : entier,
    );
    const affichage =
        grouper(entier) +
        (decimales > 0 && fraction !== null ? `,${chiffresDecimaux}` : '');

    return { valeur, affichage };
}

/**
 * Affichage d'une valeur venant du serveur ou du formulaire. Une valeur décimale reste visible
 * (au plus deux décimales) même dans un champ sans décimales : l'affichage ne doit jamais
 * masquer la valeur réelle.
 */
export function formaterMontantSaisie(
    valeur: number | string | null | undefined,
    decimales = 0,
): string {
    if (valeur === null || valeur === undefined || valeur === '') {
        return '';
    }
    const nombre = Number(valeur);
    if (!Number.isFinite(nombre)) {
        return '';
    }

    const precision = Math.max(decimales, Number.isInteger(nombre) ? 0 : 2);
    const [entier, fraction = ''] = Math.abs(nombre)
        .toFixed(precision)
        .split('.');
    const fractionUtile = fraction.replace(/0+$/, '');

    return (
        (nombre < 0 ? '-' : '') +
        grouper(entier) +
        (fractionUtile ? `,${fractionUtile}` : '')
    );
}
