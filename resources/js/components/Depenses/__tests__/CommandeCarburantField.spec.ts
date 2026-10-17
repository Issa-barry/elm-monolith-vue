import CommandeCarburantField, {
    type CommandeCandidate,
} from '@/components/Depenses/CommandeCarburantField.vue';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

const vente: CommandeCandidate = {
    type: 'vente',
    id: 'v1',
    reference: 'VTE-161026-002',
    code_abrege: '16-002',
    nature_label: 'Vente',
    date: '2026-10-16',
    statut: 'livree',
    statut_label: 'Livrée',
    detail: 'Thierno',
    depenses_liees: 0,
};
const transfert: CommandeCandidate = {
    ...vente,
    type: 'transfert',
    id: 't1',
    reference: 'TRF-161026-001',
    code_abrege: '16-001',
    nature_label: 'Transfert logistique',
    detail: 'Matoto → Kipé',
};

function repondre(
    candidats: CommandeCandidate[],
    message: string | null = null,
) {
    const fetchMock = vi.fn().mockResolvedValue({
        ok: true,
        json: async () => ({ candidats, message }),
    });
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

function monter(props: Record<string, unknown> = {}) {
    return mount(CommandeCarburantField, {
        props: {
            vehiculeId: 'veh-1',
            dateDepense: '2026-10-16',
            siteId: 'site-1',
            selection: null,
            sansCommande: false,
            motif: '',
            ...props,
        },
        attachTo: document.body,
        global: {
            stubs: {
                IconField: { template: '<div><slot /></div>' },
                InputIcon: { template: '<span><slot /></span>' },
                InputText: {
                    props: ['modelValue'],
                    emits: ['update:modelValue'],
                    template:
                        '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
                },
                StatusDot: {
                    props: ['status', 'label'],
                    template: '<span>{{ label }}</span>',
                },
            },
        },
    });
}

const resultats = (wrapper: ReturnType<typeof monter>) =>
    wrapper.findAll('[role="option"]').map((o) => o.attributes('data-cle'));

afterEach(() => {
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
});

describe('CommandeCarburantField', () => {
    it('charge les commandes du véhicule, du jour et de l’agence', async () => {
        const fetchMock = repondre([vente]);
        monter();
        await flushPromises();

        const url = fetchMock.mock.calls[0][0] as string;
        expect(url).toContain('vehicule_id=veh-1');
        expect(url).toContain('date_depense=2026-10-16');
        expect(url).toContain('site_id=site-1');
    });

    it('affiche toutes les commandes dès le focus du champ de recherche', async () => {
        repondre([vente, transfert]);
        const wrapper = monter();
        await flushPromises();

        await wrapper.find('#dep-commande').trigger('focus');

        expect(resultats(wrapper)).toEqual(['vente:v1', 'transfert:t1']);
    });

    it('filtre pendant la saisie par code abrégé, référence ou client', async () => {
        repondre([vente, transfert]);
        const wrapper = monter();
        await flushPromises();
        const champ = wrapper.find('#dep-commande');

        await champ.setValue('16-001');
        expect(resultats(wrapper)).toEqual(['transfert:t1']);

        await champ.setValue('vte-161026');
        expect(resultats(wrapper)).toEqual(['vente:v1']);

        await champ.setValue('thierno');
        expect(resultats(wrapper)).toEqual(['vente:v1']);

        await champ.setValue('zzz');
        expect(resultats(wrapper)).toEqual([]);
        expect(
            wrapper.find('[data-testid="commande-carburant-resultats"]').text(),
        ).toContain('Aucun résultat pour');
    });

    it('cliquer un résultat choisit la commande, sans choix automatique avant', async () => {
        repondre([vente]);
        const wrapper = monter();
        await flushPromises();
        expect(wrapper.emitted('update:selection')).toBeUndefined();

        await wrapper.find('#dep-commande').trigger('focus');
        await wrapper.find('[data-cle="vente:v1"]').trigger('click');

        expect(wrapper.emitted('update:selection')?.at(-1)).toEqual([vente]);
    });

    it('choisit au clavier (flèches + Entrée)', async () => {
        repondre([vente, transfert]);
        const wrapper = monter();
        await flushPromises();
        const champ = wrapper.find('#dep-commande');

        await champ.trigger('focus');
        await champ.trigger('keydown', { key: 'ArrowDown' });
        await champ.trigger('keydown', { key: 'Enter' });

        expect(wrapper.emitted('update:selection')?.at(-1)).toEqual([
            transfert,
        ]);
    });

    it('affiche le message du serveur quand le véhicule n’a aucune commande', async () => {
        repondre(
            [],
            'Aucune commande de ce véhicule le 17/10/2026 pour l’agence Matoto.',
        );
        const wrapper = monter();
        await flushPromises();

        expect(
            wrapper.find('[data-testid="commande-carburant-message"]').text(),
        ).toContain('Aucune commande de ce véhicule');
    });

    it('conserve la commande déjà rattachée à l’ouverture de la modification', async () => {
        repondre([]);
        const wrapper = monter({ selection: vente });
        await flushPromises();

        expect(wrapper.emitted('update:selection')).toBeUndefined();
        expect(
            wrapper.find('[data-testid="commande-carburant-selection"]').text(),
        ).toContain('VTE-161026-002');
    });

    it('retire la commande quand l’agence change et qu’elle n’y figure plus', async () => {
        const fetchMock = repondre([vente]);
        const wrapper = monter({ selection: vente });
        await flushPromises();

        fetchMock.mockResolvedValue({
            ok: true,
            json: async () => ({ candidats: [], message: null }),
        });
        await wrapper.setProps({ siteId: 'site-2' });
        await flushPromises();

        expect(fetchMock.mock.calls.at(-1)?.[0]).toContain('site_id=site-2');
        expect(wrapper.emitted('update:selection')?.at(-1)).toEqual([null]);
    });

    it('garde la commande si elle reste rattachable', async () => {
        repondre([vente]);
        const wrapper = monter({ selection: vente });
        await flushPromises();

        await wrapper.setProps({ siteId: 'site-1b' });
        await flushPromises();

        expect(wrapper.emitted('update:selection')).toBeUndefined();
    });

    it('sans agence, n’envoie pas de filtre agence', async () => {
        const fetchMock = repondre([]);
        monter({ siteId: '' });
        await flushPromises();

        expect(fetchMock.mock.calls[0][0]).not.toContain('site_id');
    });

    it('déclarer une exception retire la commande et demande le motif', async () => {
        repondre([vente]);
        const wrapper = monter({ selection: vente });
        await flushPromises();

        await wrapper.setProps({ sansCommande: true });
        await flushPromises();

        expect(wrapper.emitted('update:selection')?.at(-1)).toEqual([null]);
        expect(wrapper.find('#dep-motif-sans-commande').exists()).toBe(true);
    });
});
