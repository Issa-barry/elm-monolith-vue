/** Nombres de Stat-RH (salariés, jours, absences) : entiers, séparateur de milliers français. */
const ENTIER = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 });

/** 1250 → « 1 250 ». */
export function nombreTexte(valeur: number): string {
    return ENTIER.format(valeur).replace(/[  ]/g, ' ');
}

/** 1 → « 1 salarié » ; 3 → « 3 salariés » ; 0 → « 0 salarié ». */
export function pluriel(n: number, mot: string): string {
    return `${nombreTexte(n)} ${mot}${n > 1 ? 's' : ''}`;
}
