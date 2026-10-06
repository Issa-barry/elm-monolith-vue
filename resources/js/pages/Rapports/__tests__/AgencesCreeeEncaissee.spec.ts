import ListeEncaissements from '@/pages/Rapports/partials/ListeEncaissements.vue';
import ListeFactures from '@/pages/Rapports/partials/ListeFactures.vue';
import type { LigneEncaissement, LigneFacture } from '@/types/rapports';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

// « Créée à » (agence de la commande) et « Encaissée à » (agence qui a reçu l'argent) — ADR 0012.

const facture = (surcharge: Partial<LigneFacture> = {}): LigneFacture => ({
    id: 'f1',
    reference: 'VTE-001',
    date: '2026-09-29',
    client: null,
    agent: 'Moussa',
    site_nom: 'Matoto',
    encaisse_a: 'Matoto',
    montant: 10_800_000,
    encaisse: 10_800_000,
    reste: 0,
    statut: 'payee',
    statut_label: 'Payée',
    ...surcharge,
});

const encaissement = (
    surcharge: Partial<LigneEncaissement> = {},
): LigneEncaissement => ({
    id: 'e1',
    date_encaissement: '2026-09-29',
    saisi_le: '2026-09-29 10:00',
    saisie_differee: false,
    montant: 5_000_000,
    mode_paiement: 'especes',
    operateur_mobile_money: null,
    moyen_libelle: 'Espèces',
    reference_paiement: null,
    facture_id: 'f2',
    facture_reference: 'VTE-002',
    client: null,
    agent: 'Moussa',
    site_nom: 'Matoto',
    encaisse_a: 'Kindia',
    pour_autre_agence: true,
    ...surcharge,
});

const entetes = (wrapper: ReturnType<typeof mount>) =>
    wrapper.findAll('thead th').map((th) => th.text());

describe('Rapport — Créée à / Encaissée à', () => {
    it('remplace la colonne Agence des ventes par Créée à et Encaissée à', () => {
        const wrapper = mount(ListeFactures, {
            props: {
                lignes: [facture({ encaisse_a: 'Kindia' })],
                afficherAgent: true,
                vide: '—',
            },
        });

        expect(entetes(wrapper)).toContain('Créée à');
        expect(entetes(wrapper)).toContain('Encaissée à');
        expect(entetes(wrapper)).not.toContain('Agence');
        expect(wrapper.get('[data-testid="facture-encaissee-a"]').text()).toBe(
            'Kindia',
        );
    });

    it('signale un encaissement fait pour une autre agence, à reverser', () => {
        const wrapper = mount(ListeEncaissements, {
            props: { lignes: [encaissement()], afficherAgent: true, vide: '—' },
        });

        expect(entetes(wrapper)).toEqual(
            expect.arrayContaining(['Créée à', 'Encaissée à']),
        );
        expect(
            wrapper.get('[data-testid="encaissement-encaisse-a"]').text(),
        ).toContain('Kindia');
        expect(
            wrapper
                .get('[data-testid="encaissement-pour-autre-agence"]')
                .text(),
        ).toBe('pour Matoto · à reverser');
    });

    it('n’ajoute aucune mention pour un encaissement dans l’agence de la commande', () => {
        const wrapper = mount(ListeEncaissements, {
            props: {
                lignes: [
                    encaissement({
                        encaisse_a: 'Matoto',
                        pour_autre_agence: false,
                    }),
                ],
                afficherAgent: true,
                vide: '—',
            },
        });

        expect(
            wrapper
                .find('[data-testid="encaissement-pour-autre-agence"]')
                .exists(),
        ).toBe(false);
    });
});
