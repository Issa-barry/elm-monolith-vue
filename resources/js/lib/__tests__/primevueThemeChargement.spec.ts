import { definePreset } from '@primeuix/themes';
import Aura from '@primeuix/themes/aura';
import Lara from '@primeuix/themes/lara';
import Material from '@primeuix/themes/material';
import Nora from '@primeuix/themes/nora';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { beforeEach, describe, expect, it, vi } from 'vitest';

// Valeurs attendues, recopiées volontairement : le preset chargé à la demande doit être
// exactement celui que l'ancien code construisait au démarrage.
const DENSITE_APOLLO = {
    components: {
        datatable: {
            headerCell: { padding: '0.65625rem 0.875rem' },
            bodyCell: { padding: '0.65625rem 0.875rem' },
            footerCell: { padding: '0.65625rem 0.875rem' },
        },
        paginator: {
            navButton: { width: '2.1875rem', height: '2.1875rem' },
        },
    },
};

async function chargerModule() {
    vi.resetModules();

    return import('@/lib/primevue-theme');
}

describe('chargement des presets PrimeVue', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
    });

    it('Aura et starter sont disponibles tout de suite, les autres pas avant chargement', async () => {
        const theme = await chargerModule();
        const aura = definePreset(Aura, DENSITE_APOLLO);

        expect(theme.getLoadedPrimeVuePreset('aura')).toEqual(aura);
        expect(theme.getLoadedPrimeVuePreset('starter')).toBe(
            theme.getLoadedPrimeVuePreset('aura'),
        );
        for (const nom of ['lara', 'material', 'nora']) {
            expect(theme.getLoadedPrimeVuePreset(nom)).toBeUndefined();
        }
    });

    it.each([
        ['aura', Aura],
        ['starter', Aura],
        ['lara', Lara],
        ['material', Material],
        ['nora', Nora],
    ])(
        'le preset %s chargé est identique à celui construit avant',
        async (nom, base) => {
            const theme = await chargerModule();

            const preset = await theme.loadPrimeVuePreset(nom);

            expect(preset).toEqual(definePreset(base, DENSITE_APOLLO));
        },
    );

    it('une valeur inconnue ou vide retombe sur starter (Aura), comme avant', async () => {
        const theme = await chargerModule();
        const aura = theme.getLoadedPrimeVuePreset('aura');

        expect(await theme.loadPrimeVuePreset('inconnu')).toBe(aura);
        expect(await theme.loadPrimeVuePreset(null)).toBe(aura);
        expect(await theme.loadPrimeVuePreset('LARA')).toEqual(
            definePreset(Lara, DENSITE_APOLLO),
        );
    });

    it('un preset chargé une fois est réutilisé', async () => {
        const theme = await chargerModule();

        const premier = await theme.loadPrimeVuePreset('nora');

        expect(theme.getLoadedPrimeVuePreset('nora')).toBe(premier);
        expect(await theme.loadPrimeVuePreset('nora')).toBe(premier);
    });

    it('le fichier ne réimporte pas statiquement les presets chargés à la demande', () => {
        const source = readFileSync(
            resolve(process.cwd(), 'resources/js/lib/primevue-theme.ts'),
            'utf8',
        );

        expect(source).not.toMatch(
            /^import\s[^;]*from\s+'@primeuix\/themes\/(lara|material|nora)'/m,
        );
    });
});

describe('application du thème de l’organisation', () => {
    const appels: string[] = [];
    const presetsAppliques: unknown[] = [];

    beforeEach(() => {
        appels.length = 0;
        presetsAppliques.length = 0;
        vi.resetModules();
        vi.doMock('@primeuix/styled', () => ({
            usePreset: (preset: unknown) => {
                appels.push('preset');
                presetsAppliques.push(preset);
            },
            updatePrimaryPalette: () => appels.push('primary'),
            updateSurfacePalette: () => appels.push('surface'),
        }));
    });

    async function chargerComposable() {
        const theme = await import('@/lib/primevue-theme');
        const composable = await import('@/composables/useEnvironmentTheme');

        return { theme, composable };
    }

    const actif = (preset: string) =>
        ({ preset, primary: 'blue', surface: 'slate' }) as never;

    it('Aura est appliqué immédiatement, preset puis couleurs, comme avant', async () => {
        const { theme, composable } = await chargerComposable();

        void composable.applyEnvironmentTheme(actif('starter'));

        expect(appels).toEqual(['preset', 'primary', 'surface']);
        expect(presetsAppliques).toEqual([
            theme.getLoadedPrimeVuePreset('aura'),
        ]);
    });

    it('un preset à charger est appliqué après chargement, dans le même ordre', async () => {
        const { theme, composable } = await chargerComposable();

        await composable.applyEnvironmentTheme(actif('lara'));

        expect(appels).toEqual(['preset', 'primary', 'surface']);
        expect(presetsAppliques).toHaveLength(1);
        expect(presetsAppliques[0]).toBe(theme.getLoadedPrimeVuePreset('lara'));
        expect(presetsAppliques[0]).toEqual(definePreset(Lara, DENSITE_APOLLO));
    });

    it('le dernier thème demandé l’emporte si un chargement est encore en cours', async () => {
        const { theme, composable } = await chargerComposable();

        const lara = composable.applyEnvironmentTheme(actif('lara'));
        void composable.applyEnvironmentTheme(actif('aura'));
        await lara;

        // Lara arrive après Aura : il ne doit pas écraser le choix plus récent.
        expect(appels).toEqual(['preset', 'primary', 'surface']);
        expect(presetsAppliques).toEqual([
            theme.getLoadedPrimeVuePreset('aura'),
        ]);
    });

    it('si le téléchargement échoue, l’application démarre quand même avec Aura', async () => {
        vi.doMock('@primeuix/themes/material', () => {
            throw new Error('réseau indisponible');
        });
        const { theme } = await chargerComposable();

        const preset = await theme.loadPrimeVuePreset('material');

        expect(preset).toBe(theme.getLoadedPrimeVuePreset('aura'));
        expect(theme.getLoadedPrimeVuePreset('material')).toBeUndefined();
    });
});
