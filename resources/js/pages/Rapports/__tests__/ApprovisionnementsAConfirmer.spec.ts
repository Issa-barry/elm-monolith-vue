import ApprovisionnementsAConfirmer, {
    type ApprovisionnementAConfirmer,
} from '@/pages/Rapports/partials/ApprovisionnementsAConfirmer.vue';
import { router } from '@inertiajs/vue3';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';

// « Ma situation » — espèces remises à l'agent, en attente de SA confirmation (ADR 0018).

vi.mock('@inertiajs/vue3', () => ({ router: { post: vi.fn() } }));

// Fenêtre rendue en place (sans téléportation) : le contenu suit `open`.
const passeplat = defineComponent({
    setup:
        (_, { slots }) =>
        () =>
            h('div', slots.default?.()),
});
const DialogEnPlace = defineComponent({
    props: { open: Boolean },
    setup:
        (props, { slots }) =>
        () =>
            props.open ? h('div', slots.default?.()) : null,
});

const approvisionnement = (
    surcharge: Partial<ApprovisionnementAConfirmer> = {},
): ApprovisionnementAConfirmer => ({
    id: 'm1',
    reference: 'MVT-2026-00042',
    montant: 2_000_000,
    statut: 'envoye',
    statut_label: 'Envoyé',
    agence: 'Matoto',
    caisse_origine: 'Caisse Matoto',
    caisse_destination: 'Caisse Moussa',
    remis_par: 'Ousmane Camara',
    envoye_le: '2026-10-04T10:32:00+00:00',
    motif: 'Paiement commissions',
    peut_contester: true,
    ...surcharge,
});

const monter = (liste: ApprovisionnementAConfirmer[]) =>
    mount(ApprovisionnementsAConfirmer, {
        props: { approvisionnements: liste },
        global: {
            stubs: {
                Dialog: DialogEnPlace,
                DialogContent: passeplat,
                DialogHeader: passeplat,
                DialogTitle: passeplat,
                DialogDescription: passeplat,
                DialogFooter: passeplat,
                StatusDot: true,
            },
        },
    });

describe('Ma situation — espèces à confirmer', () => {
    beforeEach(() => {
        vi.mocked(router.post).mockReset();
    });

    it('liste chaque approvisionnement avec le montant, le remettant et le motif', () => {
        const wrapper = monter([approvisionnement()]);
        const ligne = wrapper.get('[data-testid="approvisionnement-ligne"]');

        expect(wrapper.text()).toContain('Espèces à confirmer (1)');
        expect(ligne.text()).toContain('Ousmane Camara');
        expect(ligne.text()).toContain('Caisse Matoto → Caisse Moussa');
        expect(ligne.text()).toContain('Paiement commissions');
    });

    it('ne propose « Contester » que sur un approvisionnement encore contestable', () => {
        const wrapper = monter([
            approvisionnement(),
            approvisionnement({
                id: 'm2',
                statut: 'conteste',
                statut_label: 'Contesté',
                peut_contester: false,
            }),
        ]);
        const [envoye, conteste] = wrapper.findAll(
            '[data-testid="approvisionnement-ligne"]',
        );

        expect(
            envoye.find('[data-testid="approvisionnement-contester"]').exists(),
        ).toBe(true);
        expect(
            conteste
                .find('[data-testid="approvisionnement-contester"]')
                .exists(),
        ).toBe(false);
        expect(
            conteste
                .find('[data-testid="approvisionnement-recevoir"]')
                .exists(),
        ).toBe(true);
    });

    it('confirme la réception sur la route du mouvement, une seule fois même en double clic', async () => {
        const wrapper = monter([approvisionnement()]);

        await wrapper
            .get('[data-testid="approvisionnement-recevoir"]')
            .trigger('click');
        const valider = wrapper.get(
            '[data-testid="approvisionnement-valider"]',
        );
        await valider.trigger('click');
        await valider.trigger('click');

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(vi.mocked(router.post).mock.calls[0][0]).toBe(
            '/backoffice/comptabilite/tresorerie/mouvements/m1/recevoir',
        );
    });

    it('exige un motif pour contester', async () => {
        const wrapper = monter([approvisionnement()]);

        await wrapper
            .get('[data-testid="approvisionnement-contester"]')
            .trigger('click');
        await wrapper
            .get('[data-testid="approvisionnement-valider"]')
            .trigger('click');

        expect(router.post).not.toHaveBeenCalled();
        expect(
            wrapper.get('[data-testid="approvisionnement-erreur"]').text(),
        ).toContain('Le motif est obligatoire');

        await wrapper.get('textarea').setValue('Rien reçu');
        await wrapper
            .get('[data-testid="approvisionnement-valider"]')
            .trigger('click');

        expect(vi.mocked(router.post).mock.calls[0][0]).toBe(
            '/backoffice/comptabilite/tresorerie/mouvements/m1/contester',
        );
        expect(vi.mocked(router.post).mock.calls[0][1]).toEqual({
            motif: 'Rien reçu',
        });
    });
});
