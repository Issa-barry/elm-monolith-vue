import { queryDe, useUrlTab } from '@/composables/useUrlTab';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope, nextTick, type EffectScope } from 'vue';

const etat = vi.hoisted(() => ({
    page: { url: '', component: '' },
    replace: vi.fn(),
}));

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    etat.page = reactive({ url: '', component: '' });

    return {
        usePage: () => etat.page,
        router: { replace: etat.replace },
    };
});

const ONGLETS = ['informations', 'equipe', 'parrain', 'situation'] as const;
const FICHE = '/backoffice/vehicules/V1';

let scope: EffectScope;

function monter() {
    scope = effectScope();

    return scope.run(() => useUrlTab(ONGLETS, 'informations'))!;
}

describe('useUrlTab', () => {
    beforeEach(() => {
        etat.page.url = FICHE;
        etat.page.component = 'Vehicules/Show';
        etat.replace.mockReset();
        // Simule Inertia : la visite client-side met à jour page.url.
        etat.replace.mockImplementation(({ url }: { url: string }) => {
            etat.page.url = url;
        });
    });

    afterEach(() => scope?.stop());

    it("lit l'onglet dans l'URL au chargement", () => {
        etat.page.url = `${FICHE}?tab=situation&situation_periode=month`;

        expect(monter().onglet.value).toBe('situation');
    });

    it('retombe sur informations quand tab est absent ou invalide', () => {
        expect(monter().onglet.value).toBe('informations');
        scope.stop();

        etat.page.url = `${FICHE}?tab=nimportequoi`;
        expect(monter().onglet.value).toBe('informations');
    });

    it("affiche l'onglet cliqué immédiatement et l'écrit dans l'URL sans requête serveur", () => {
        const { onglet, choisir } = monter();

        choisir('equipe');

        expect(onglet.value).toBe('equipe');
        expect(etat.replace).toHaveBeenCalledTimes(1);
        expect(etat.replace).toHaveBeenCalledWith({
            url: `${FICHE}?tab=equipe`,
            preserveScroll: true,
            preserveState: true,
        });
    });

    it("conserve les autres paramètres de l'URL en changeant d'onglet", () => {
        etat.page.url = `${FICHE}?tab=situation&situation_periode=month`;
        const { choisir } = monter();

        choisir('informations');

        expect(etat.page.url).toBe(
            `${FICHE}?tab=informations&situation_periode=month`,
        );

        choisir('situation');

        expect(etat.page.url).toBe(
            `${FICHE}?tab=situation&situation_periode=month`,
        );
    });

    it("n'écrit rien quand l'URL porte déjà l'onglet cliqué", () => {
        etat.page.url = `${FICHE}?tab=parrain`;
        const { choisir } = monter();

        choisir('parrain');

        expect(etat.replace).not.toHaveBeenCalled();
    });

    it("garde l'onglet quand une redirection serveur ramène l'URL nue de la fiche", async () => {
        etat.page.url = `${FICHE}?tab=parrain`;
        const { onglet } = monter();

        etat.page.url = FICHE;
        await nextTick();

        expect(onglet.value).toBe('parrain');
        expect(etat.page.url).toBe(`${FICHE}?tab=parrain`);
    });

    it("suit une redirection vers une autre fiche (transfert) en gardant l'onglet", async () => {
        etat.page.url = `${FICHE}?tab=equipe`;
        const { onglet } = monter();

        etat.page.url = '/backoffice/vehicules/V2';
        await nextTick();

        expect(onglet.value).toBe('equipe');
        expect(etat.page.url).toBe('/backoffice/vehicules/V2?tab=equipe');
    });

    it("n'ajoute jamais l'onglet à l'URL d'une autre page", async () => {
        etat.page.url = `${FICHE}?tab=situation`;
        monter();

        etat.page.component = 'Ventes/Index';
        etat.page.url = '/backoffice/ventes?vehicule=AI3462';
        await nextTick();

        expect(etat.replace).not.toHaveBeenCalled();
        expect(etat.page.url).toBe('/backoffice/ventes?vehicule=AI3462');
    });

    it("ne rebascule pas sur l'ancien onglet quand une réponse tardive arrive après un clic", async () => {
        etat.page.url = `${FICHE}?tab=situation`;
        const { onglet, choisir } = monter();

        choisir('informations');
        // Réponse d'un filtre lancé avant le clic : son URL porte encore l'ancien onglet.
        etat.page.url = `${FICHE}?tab=situation&situation_periode=month`;
        await nextTick();

        expect(onglet.value).toBe('informations');
        expect(etat.page.url).toBe(
            `${FICHE}?tab=informations&situation_periode=month`,
        );
    });
});

describe('queryDe', () => {
    it("extrait les paramètres d'une URL Inertia", () => {
        expect(queryDe('/backoffice/vehicules/V1?tab=situation&x=1')).toEqual({
            tab: 'situation',
            x: '1',
        });
    });

    it('renvoie un objet vide sans paramètres', () => {
        expect(queryDe('/backoffice/vehicules/V1')).toEqual({});
    });
});
