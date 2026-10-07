import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';
import PrecommandeActions, {
    type PrecommandeData,
} from '../PrecommandeActions.vue';

vi.mock('@inertiajs/vue3', () => ({ router: { post: vi.fn() } }));
vi.mock('primevue/usetoast', () => ({ useToast: () => ({ add: vi.fn() }) }));

function largeurEcran(bureau: boolean) {
    window.matchMedia = vi.fn().mockImplementation((query: string) => ({
        matches: bureau,
        media: query,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        addListener: vi.fn(),
        removeListener: vi.fn(),
        onchange: null,
        dispatchEvent: vi.fn(),
    }));
}

const precommande: PrecommandeData = {
    livraison: false,
    etapes: [
        { cle: 'reservee', libelle: 'Créée' },
        { cle: 'a_preparer', libelle: 'Préparation en cours' },
        { cle: 'preparee', libelle: 'Prête au retrait' },
        { cle: 'retiree', libelle: 'Retirée' },
        { cle: 'cloturee', libelle: 'Clôturée' },
    ],
    etape_courante: 'preparee',
    stock_reserve: true,
    facture_statut_label: 'Créée',
    montants: {
        total: 20000,
        acomptes: 2000,
        rembourse: 0,
        encaisse_net: 2000,
        reste: 18000,
        trop_percu: 0,
    },
    lignes: [],
    can_lancer_preparation: false,
    can_valider_preparation: false,
    can_valider_retrait: true,
    can_confirmer_livraison: false,
    can_rembourser: false,
    can_annuler: true,
    can_changer_mode_remise: true,
    vehicules_livraison: [],
    annulation_renforcee: false,
    annulation_code_requis: false,
    decaissement: null,
};

// Même structure que la fiche : la cible de l'en-tête précède la carte Précommande.
const Fiche = defineComponent({
    setup: () => () =>
        h('div', [
            h('header', [h('div', { id: 'precommande-actions' })]),
            h(PrecommandeActions, {
                commandeId: 'c1',
                reference: 'VTE-051026-003',
                statut: 'preparee',
                precommande,
            }),
        ]),
});

describe('PrecommandeActions — emplacement des actions', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('place les actions dans l’en-tête de la fiche sur bureau, comme une vente', async () => {
        largeurEcran(true);
        const fiche = mount(Fiche, {
            attachTo: document.body,
            global: {
                stubs: { Dialog: true, PaymentCard: true, Select: true },
            },
        });
        await nextTick();

        const enTete = document.querySelector('header #precommande-actions');
        expect(enTete?.textContent).toContain('Valider le retrait');
        expect(enTete?.textContent).toContain('Passer en livraison');
        expect(enTete?.textContent).toContain('Annuler la précommande');
        fiche.unmount();
    });

    it('garde les actions dans la carte sur mobile, où l’en-tête bureau est masqué', async () => {
        largeurEcran(false);
        const fiche = mount(Fiche, {
            attachTo: document.body,
            global: {
                stubs: { Dialog: true, PaymentCard: true, Select: true },
            },
        });
        await nextTick();

        expect(
            document.querySelector('header #precommande-actions')?.textContent,
        ).toBe('');
        expect(
            fiche.get('[data-testid="precommande-actions"]').text(),
        ).toContain('Valider le retrait');
        fiche.unmount();
    });

    it('marque l’étape active de la frise, celle du statut de l’en-tête', async () => {
        largeurEcran(true);
        const fiche = mount(Fiche, {
            attachTo: document.body,
            global: {
                stubs: { Dialog: true, PaymentCard: true, Select: true },
            },
        });
        await nextTick();

        const active = fiche.get('[aria-current="step"]');
        expect(active.text()).toBe('Prête au retrait');
        expect(
            fiche.get('[data-testid="precommande-frise"]').text(),
        ).not.toContain('Facturation');
        fiche.unmount();
    });
});
