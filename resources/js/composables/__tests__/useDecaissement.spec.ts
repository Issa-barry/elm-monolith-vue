import type { EncaissementPayload } from '@/components/payment/moyensEncaissement';
import { useDecaissement } from '@/composables/useDecaissement';
import { ENTETE_CLE_OPERATION } from '@/lib/cleOperation';
import type { TresorerieFiche } from '@/types/commission';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({ router: { post } }));
vi.mock('primevue/usetoast', () => ({ useToast: () => ({ add: vi.fn() }) }));

const TRESORERIE: TresorerieFiche = {
    site_id: 'site-1',
    moyens: [],
    especes_disponibles: true,
    solde_especes: 500_000,
    message: null,
};

const URL = '/backoffice/comptabilite/fiches/f1/paiements';
const PARTIEL: EncaissementPayload = {
    montant: 50_000,
    mode_paiement: 'especes',
};

const cleEnvoyee = (appel: number): string =>
    post.mock.calls[appel][2].headers[ENTETE_CLE_OPERATION];

describe('useDecaissement — idempotence (ADR 0069)', () => {
    beforeEach(() => post.mockClear());

    it('renvoie la même clé pour le même paiement, une nouvelle pour un paiement corrigé', () => {
        const decaissement = useDecaissement();
        decaissement.peutOuvrir(TRESORERIE);

        decaissement.envoyer(URL, PARTIEL);
        decaissement.envoyer(URL, PARTIEL);
        decaissement.envoyer(URL, { ...PARTIEL, montant: 60_000 });

        expect(cleEnvoyee(0)).toMatch(/^[0-9a-f-]{36}$/);
        expect(cleEnvoyee(1)).toBe(cleEnvoyee(0));
        expect(cleEnvoyee(2)).not.toBe(cleEnvoyee(0));
    });

    it('change de clé à chaque ouverture : deux paiements voulus restent deux paiements', () => {
        const decaissement = useDecaissement();

        decaissement.peutOuvrir(TRESORERIE);
        decaissement.envoyer(URL, PARTIEL);
        decaissement.peutOuvrir(TRESORERIE);
        decaissement.envoyer(URL, PARTIEL);

        expect(cleEnvoyee(1)).not.toBe(cleEnvoyee(0));
    });
});
