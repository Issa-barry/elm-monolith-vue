import { describe, expect, it } from 'vitest';
import { normalizePrimeVueTheme } from '../primevue-theme';
import { themeActifInitial } from '../themeInitial';

// Page d'erreur ouverte directement sans prop `theme` : app.ts lisait `theme.active` sans
// garde et l'application ne démarrait pas (page blanche au lieu de « Page introuvable »).
describe('themeActifInitial', () => {
    it('retourne le thème actif partagé par le serveur', () => {
        const active = { preset: 'starter', primary: 'blue', surface: 'slate' };

        expect(
            themeActifInitial({
                status: 404,
                theme: { active, allowed: {}, locked: {} },
            }),
        ).toEqual(active);
    });

    it('retourne undefined sans planter quand le prop theme manque', () => {
        expect(themeActifInitial({ status: 404 })).toBeUndefined();
        expect(themeActifInitial({ status: 500, theme: null })).toBeUndefined();
        expect(themeActifInitial(undefined)).toBeUndefined();
    });

    it('sans thème, le démarrage retombe sur le preset par défaut', () => {
        expect(
            normalizePrimeVueTheme(themeActifInitial({ status: 404 })?.preset),
        ).toBe('starter');
    });
});
