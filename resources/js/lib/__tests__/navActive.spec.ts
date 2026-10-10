import { lienActif } from '@/lib/navActive';
import type { NavItem } from '@/types';
import { describe, expect, it } from 'vitest';

const achats: NavItem[] = [
    { title: 'Bons de commande', href: '/backoffice/achats' },
    { title: 'Factures d’achat', href: '/backoffice/achats/factures' },
];

const actifs = (pageUrl: string) =>
    achats
        .filter((item) => lienActif(item.href, achats, pageUrl))
        .map((item) => item.title);

describe('lienActif', () => {
    it('un seul sous-menu actif sur la liste des factures d’achat', () => {
        expect(actifs('/backoffice/achats/factures')).toEqual([
            'Factures d’achat',
        ]);
        expect(actifs('/backoffice/achats/factures?statut=validee')).toEqual([
            'Factures d’achat',
        ]);
        expect(actifs('/backoffice/achats/factures/12')).toEqual([
            'Factures d’achat',
        ]);
    });

    it('les bons de commande restent actifs sur leurs propres pages', () => {
        expect(actifs('/backoffice/achats')).toEqual(['Bons de commande']);
        expect(actifs('/backoffice/achats/5')).toEqual(['Bons de commande']);
        expect(actifs('/backoffice/achats?statut=brouillon')).toEqual([
            'Bons de commande',
        ]);
    });

    it("n'active pas un lien dont l'URL est seulement un préfixe textuel", () => {
        expect(actifs('/backoffice/achatsx')).toEqual([]);
    });

    it('sans frères, garde la correspondance par préfixe', () => {
        expect(
            lienActif('/backoffice/achats', [], '/backoffice/achats/factures'),
        ).toBe(true);
    });
});
