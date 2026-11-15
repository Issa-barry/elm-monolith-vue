import KpiCard from '@/components/KpiCard.vue';
import RemunerationSalarie, {
    type Remuneration,
} from '@/pages/Employes/partials/RemunerationSalarie.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const remuneration = (surcharge: Partial<Remuneration> = {}): Remuneration => ({
    date: '2026-11-13',
    hors_emploi: null,
    contrat: {
        id: 'C1',
        type_contrat_label: 'CDI',
        date_debut: '2026-09-01',
        date_fin: null,
    },
    salaire_base: 1000000,
    primes: [
        {
            id: 'P1',
            libelle: 'Transport',
            categorie_label: 'Prime',
            montant: 500000,
            detail: 'Montant mensuel',
        },
        {
            id: 'P2',
            libelle: 'Vacance',
            categorie_label: 'Prime',
            montant: 200000,
            detail: 'Montant mensuel',
        },
    ],
    total_primes: 700000,
    total: 1700000,
    avantages_nature: [],
    total_avantages_nature: 0,
    non_comptees: [],
    ...surcharge,
});

const monter = (r: Remuneration) =>
    mount(RemunerationSalarie, {
        props: { remuneration: r },
        global: { stubs: { InfoTooltip: true } },
    });

describe('Rémunération mensuelle totale de la fiche salarié', () => {
    it('met en évidence le total, le salaire de base et les primes', () => {
        const wrapper = monter(remuneration());

        expect(wrapper.find('[data-slot="kpi-value"]').text()).toBe(
            '1 700 000',
        );
        expect(
            wrapper.find('[data-testid="remuneration-salaire-base"]').text(),
        ).toBe('1 000 000 GNF');
        expect(
            wrapper.find('[data-testid="remuneration-total-primes"]').text(),
        ).toBe('700 000 GNF');
        expect(wrapper.text()).toContain('Primes récurrentes (2)');
    });

    it('replie le détail du calcul et le déplie à la demande', async () => {
        const wrapper = monter(
            remuneration({
                non_comptees: [
                    {
                        id: 'P3',
                        libelle: 'Fin d’année',
                        raison: 'Ponctuelle : versée une seule fois, en 12/2026.',
                    },
                ],
            }),
        );

        expect(
            wrapper.find('[data-testid="remuneration-detail"]').exists(),
        ).toBe(false);
        await wrapper
            .find('[data-testid="remuneration-detail-toggle"]')
            .trigger('click');

        const detail = wrapper.find('[data-testid="remuneration-detail"]');
        expect(detail.text()).toContain('Contrat CDI depuis le 01/09/2026');
        expect(detail.text()).toContain('+ 500 000 GNF');
        expect(
            wrapper.find('[data-testid="remuneration-non-comptees"]').text(),
        ).toContain('Fin d’année');
    });

    it('sans contrat, affiche l’absence de salaire de base sans inventer de total', () => {
        const wrapper = monter(
            remuneration({
                contrat: null,
                salaire_base: null,
                total: null,
            }),
        );

        expect(wrapper.find('[data-slot="kpi-value"]').text()).toBe('—');
        expect(
            wrapper.find('[data-testid="remuneration-salaire-base"]').text(),
        ).toBe('Aucun contrat');
        expect(wrapper.findComponent(KpiCard).props('warning')).toBe(true);
    });

    it('affiche les avantages en nature à part, hors du total versé', () => {
        const wrapper = monter(
            remuneration({
                avantages_nature: [
                    {
                        id: 'A1',
                        libelle: 'Logement de fonction',
                        categorie_label: 'Avantage en nature',
                        montant: 400000,
                        detail: 'Montant mensuel',
                    },
                ],
                total_avantages_nature: 400000,
            }),
        );

        expect(
            wrapper
                .find('[data-testid="remuneration-avantages-nature"]')
                .text(),
        ).toBe('400 000 GNF');
        expect(wrapper.find('[data-slot="kpi-value"]').text()).toBe(
            '1 700 000',
        );
    });

    it('salarié sorti : message seul, sans détail', () => {
        const wrapper = monter(
            remuneration({
                hors_emploi:
                    'Salarié sorti le 31/10/2026 : plus aucune rémunération en cours.',
                contrat: null,
                salaire_base: null,
                primes: [],
                total_primes: 0,
                total: null,
            }),
        );

        expect(wrapper.text()).toContain('Salarié sorti le 31/10/2026');
        expect(
            wrapper.find('[data-testid="remuneration-detail-toggle"]').exists(),
        ).toBe(false);
    });
});
