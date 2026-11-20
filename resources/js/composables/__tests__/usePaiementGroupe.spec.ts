import {
    LOT_PAIEMENT_GROUPE,
    usePaiementGroupe,
} from '@/composables/usePaiementGroupe';
import type { FicheAPayer } from '@/types/commission';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const toastAdd = vi.fn();
const reload = vi.fn((options?: { onFinish?: () => void }) =>
    options?.onFinish?.(),
);

vi.mock('primevue/usetoast', () => ({ useToast: () => ({ add: toastAdd }) }));
vi.mock('@inertiajs/vue3', () => ({
    router: { reload: (o: never) => reload(o) },
}));

const fiche = (i: number): FicheAPayer => ({
    id: `f${i}`,
    reference: `FICHE-${i}`,
    beneficiaire_type: 'livreur',
    beneficiaire_nom: `Livreur ${i}`,
    periode_reference: 'PAY-202609-P1-LIV',
    periode_debut: '2026-09-01',
    periode_fin: '2026-09-15',
    montant_net: 1000,
    montant_paye: 0,
    montant_restant: 1000,
    tresorerie: {
        site_id: 'matoto',
        moyens: [],
        especes_disponibles: true,
        solde_especes: 1_000_000,
        message: null,
    },
});

const reponse = (ok: boolean, body: unknown) =>
    Promise.resolve({ ok, json: () => Promise.resolve(body) } as Response);

const payload = {
    mode_paiement: 'especes',
    date_paiement: '2026-11-19',
    references: {},
};

describe('usePaiementGroupe', () => {
    beforeEach(() => {
        toastAdd.mockClear();
        reload.mockClear();
    });

    it('envoie par lots, chaque fiche pour son reste, puis recharge la page', async () => {
        const fetchMock = vi.fn((_url: string, init: RequestInit) => {
            const n = JSON.parse(init.body as string).fiches.length;
            return reponse(true, {
                payees: n,
                montant_total: n * 1000,
                deja_payees: [],
            });
        });
        vi.stubGlobal('fetch', fetchMock);
        const termine = vi.fn();
        const groupe = usePaiementGroupe(termine);
        const fiches = Array.from({ length: LOT_PAIEMENT_GROUPE + 5 }, (_, i) =>
            fiche(i),
        );

        groupe.open(fiches);
        await groupe.submit(payload);

        expect(fetchMock).toHaveBeenCalledTimes(2);
        const premier = JSON.parse(fetchMock.mock.calls[0][1].body as string);
        expect(premier.fiches[0]).toEqual({ id: 'f0', montant: 1000 });
        expect(groupe.visible.value).toBe(false);
        expect(toastAdd).toHaveBeenCalledWith(
            expect.objectContaining({ severity: 'success' }),
        );
        expect(termine).toHaveBeenCalled();
    });

    it('garde la fenêtre ouverte avec la raison quand rien n’est enregistré', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => reponse(false, { message: 'Solde insuffisant.' })),
        );
        const groupe = usePaiementGroupe(vi.fn());

        groupe.open([fiche(1)]);
        await groupe.submit(payload);

        expect(groupe.visible.value).toBe(true);
        expect(groupe.erreur.value).toBe('Solde insuffisant.');
        expect(groupe.processing.value).toBe(false);
        expect(reload).not.toHaveBeenCalled();
    });

    it('signale un arrêt en cours de route sans masquer ce qui est déjà payé', async () => {
        let appel = 0;
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                appel++ === 0
                    ? reponse(true, {
                          payees: LOT_PAIEMENT_GROUPE,
                          montant_total: 20_000,
                          deja_payees: [],
                      })
                    : reponse(false, { message: 'Le reste a changé.' }),
            ),
        );
        const groupe = usePaiementGroupe(vi.fn());

        groupe.open(
            Array.from({ length: LOT_PAIEMENT_GROUPE + 1 }, (_, i) => fiche(i)),
        );
        await groupe.submit(payload);

        expect(toastAdd).toHaveBeenCalledWith(
            expect.objectContaining({ severity: 'warn' }),
        );
        expect(reload).toHaveBeenCalled();
    });

    it('envoie une clé par lot, reprise telle quelle pour un renvoi identique', async () => {
        let echec = true;
        const fetchMock = vi.fn((_url: string, init: RequestInit) => {
            const n = JSON.parse(init.body as string).fiches.length;
            return echec
                ? Promise.reject(new TypeError('Failed to fetch'))
                : reponse(true, {
                      payees: n,
                      montant_total: n * 1000,
                      deja_payees: [],
                  });
        });
        vi.stubGlobal('fetch', fetchMock);
        const groupe = usePaiementGroupe(vi.fn());
        const fiches = Array.from({ length: LOT_PAIEMENT_GROUPE + 1 }, (_, i) =>
            fiche(i),
        );
        const cle = (appel: number) =>
            (fetchMock.mock.calls[appel][1].headers as Record<string, string>)[
                'Idempotency-Key'
            ];

        groupe.open(fiches);
        // Coupure réseau : la réponse du 1er lot est perdue, l'utilisateur relance à l'identique.
        await groupe.submit(payload);
        echec = false;
        await groupe.submit(payload);

        expect(cle(0)).toMatch(/^[0-9a-f-]{36}\.0$/);
        expect(cle(1)).toBe(cle(0));
        expect(cle(2)).toBe(cle(0).replace(/\.0$/, '.1'));

        // Paramètres changés : nouvelle opération.
        groupe.open(fiches);
        await groupe.submit({ ...payload, date_paiement: '2026-11-18' });
        expect(cle(3)).not.toBe(cle(0));
    });

    it('n’envoie aucun lot après un lot refusé', async () => {
        const fetchMock = vi.fn((_url: string, init: RequestInit) => {
            const n = JSON.parse(init.body as string).fiches.length;
            return fetchMock.mock.calls.length === 2
                ? reponse(false, { message: 'Solde insuffisant.' })
                : reponse(true, {
                      payees: n,
                      montant_total: n * 1000,
                      deja_payees: [],
                  });
        });
        vi.stubGlobal('fetch', fetchMock);
        const groupe = usePaiementGroupe(vi.fn());

        groupe.open(
            Array.from({ length: LOT_PAIEMENT_GROUPE * 3 }, (_, i) => fiche(i)),
        );
        await groupe.submit(payload);

        // Lot 1 passé, lot 2 refusé (annulé en entier côté serveur), lot 3 jamais envoyé.
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(groupe.progression.value).toBe(33);
        expect(toastAdd).toHaveBeenCalledWith(
            expect.objectContaining({
                severity: 'warn',
                detail: expect.stringContaining(
                    `${LOT_PAIEMENT_GROUPE} paiement(s) déjà enregistré(s)`,
                ),
            }),
        );
    });
});
