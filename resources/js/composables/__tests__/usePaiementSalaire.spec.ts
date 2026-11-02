import type { EncaissementPayload } from '@/components/payment/moyensEncaissement';
import type { TresorerieFiche } from '@/types/commission';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { type SalaireAPayer, usePaiementSalaire } from '../usePaiementSalaire';

const { post, toastAdd } = vi.hoisted(() => ({
    post: vi.fn(),
    toastAdd: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({ router: { post } }));
vi.mock('primevue/usetoast', () => ({
    useToast: () => ({ add: toastAdd }),
}));

const TRESORERIE: TresorerieFiche = {
    site_id: 'site-1',
    moyens: [],
    especes_disponibles: true,
    solde_especes: 500_000,
    message: null,
};

const SALAIRE: SalaireAPayer = {
    url: '/backoffice/comptabilite/salaires/ligne-1/payer',
    beneficiaire_nom: 'Alpha DIALLO',
    periode: 'Octobre 2026',
    montant_du: 750_000,
    deja_paye: 0,
    reste: 750_000,
    tresorerie: TRESORERIE,
};

const PAYLOAD: EncaissementPayload = {
    montant: 750_000,
    mode_paiement: 'especes',
};

describe('usePaiementSalaire', () => {
    beforeEach(() => {
        post.mockClear();
        toastAdd.mockClear();
    });

    it('ouvre le dialogue avec le contexte du salaire', () => {
        const paiement = usePaiementSalaire();
        paiement.open(SALAIRE);

        expect(paiement.visible.value).toBe(true);
        expect(paiement.infoRows.value.map((r) => r.label)).toEqual([
            'Bénéficiaire',
            'Période',
            'Montant dû',
            'Déjà payé',
        ]);
    });

    it("n'affiche ni montant dû ni déjà payé pour un paiement global", () => {
        const paiement = usePaiementSalaire();
        paiement.open({
            url: '/backoffice/comptabilite/salaires/employes/e-1/paiements',
            beneficiaire_nom: 'Alpha DIALLO',
            reste: 1_500_000,
            tresorerie: TRESORERIE,
        });

        expect(paiement.infoRows.value.map((r) => r.label)).toEqual([
            'Bénéficiaire',
        ]);
    });

    it("n'ouvre pas et explique pourquoi quand le paiement est impossible", () => {
        const paiement = usePaiementSalaire();
        paiement.open({
            ...SALAIRE,
            tresorerie: { ...TRESORERIE, message: 'Aucun site central.' },
        });

        expect(paiement.visible.value).toBe(false);
        expect(paiement.salaire.value).toBeNull();
        expect(toastAdd).toHaveBeenCalledWith(
            expect.objectContaining({ detail: 'Aucun site central.' }),
        );
    });

    it('envoie le paiement sur la route du salaire, daté du jour local', () => {
        const paiement = usePaiementSalaire();
        paiement.open(SALAIRE);
        paiement.submit(PAYLOAD);

        const [url, donnees] = post.mock.calls[0];
        expect(url).toBe(SALAIRE.url);
        expect(donnees).toMatchObject(PAYLOAD);
        expect(donnees.date_paiement).toMatch(/^\d{4}-\d{2}-\d{2}$/);
        expect(paiement.processing.value).toBe(true);
    });

    it('ne laisse aucune erreur serveur silencieuse', () => {
        const paiement = usePaiementSalaire();
        paiement.open(SALAIRE);
        paiement.submit(PAYLOAD);

        post.mock.calls[0][2].onError({
            montant: 'Solde insuffisant.',
            comptabilisation: 'Paiement non enregistré : compte absent.',
        });

        expect(paiement.errors.value.montant).toBe('Solde insuffisant.');
        expect(toastAdd).toHaveBeenCalledTimes(1);
        expect(toastAdd).toHaveBeenCalledWith(
            expect.objectContaining({
                detail: 'Paiement non enregistré : compte absent.',
            }),
        );
    });

    it('ferme le dialogue une fois le paiement enregistré', () => {
        const paiement = usePaiementSalaire();
        paiement.open(SALAIRE);
        paiement.submit(PAYLOAD);

        const options = post.mock.calls[0][2];
        options.onSuccess();
        options.onFinish();

        expect(paiement.visible.value).toBe(false);
        expect(paiement.processing.value).toBe(false);
    });
});
