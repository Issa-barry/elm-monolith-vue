import { toUrl } from '@/lib/utils';
import type { NavItem } from '@/types';

export function urlCorrespond(href: NavItem['href'], pageUrl: string): boolean {
    const url = toUrl(href);
    if (!url) return false;

    return (
        pageUrl === url ||
        pageUrl.startsWith(`${url}/`) ||
        pageUrl.startsWith(`${url}?`)
    );
}

/**
 * Un lien frère plus précis l'emporte : sur /backoffice/achats/factures, seul
 * « Factures fournisseurs » est actif, pas « Bons de commande » (/backoffice/achats).
 */
export function lienActif(
    href: NavItem['href'],
    freres: NavItem[],
    pageUrl: string,
): boolean {
    if (!urlCorrespond(href, pageUrl)) return false;

    const longueur = toUrl(href)?.length ?? 0;

    return !freres.some(
        (frere) =>
            (toUrl(frere.href)?.length ?? 0) > longueur &&
            urlCorrespond(frere.href, pageUrl),
    );
}
