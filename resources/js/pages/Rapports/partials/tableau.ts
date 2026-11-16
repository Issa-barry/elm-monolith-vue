import { formatQuantite } from '@/lib/utils';

// Conventions des tableaux des rapports (référence : Rapport par agence) : montants sans unité
// (« Montants en GNF » en tête), alignés à droite en chiffres tabulaires, zéros atténués, groupe
// « Remis » sur fond distinct.

export const TH =
    'px-5 py-2.5 text-right text-xs font-medium whitespace-nowrap';
export const TD =
    'px-5 py-3 text-right align-top tabular-nums whitespace-nowrap';
export const REMISE = 'bg-muted/30';
export const TOTAL_ENCAISSE = 'border-x bg-muted/40';

/** Montant sans unité ; « — » pour une valeur absente. */
export function n(valeur: number | null | undefined): string {
    return valeur === null || valeur === undefined
        ? '—'
        : formatQuantite(valeur);
}

/** Zéro affiché en retrait : l'œil va aux montants non nuls. */
export function attenue(valeur: number | null | undefined): string {
    return !valeur ? 'text-muted-foreground/70' : '';
}
