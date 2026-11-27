import LignesIndicateursApollo from '@/components/dashboard/shared/LignesIndicateursApollo.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const texte = (s: string) => s.replace(/\s+/g, ' ').trim();

describe('LignesIndicateursApollo', () => {
    const wrapper = mount(LignesIndicateursApollo, {
        props: {
            prefixe: 'essai',
            lignes: [
                {
                    id: 'etat',
                    titre: 'État actuel',
                    precision: 'à ce jour',
                    cartes: [
                        {
                            id: 'a',
                            titre: 'Carte A',
                            definition: 'Définition A',
                            icone: 'pi-box',
                            valeur: '12',
                            detail: 'Détail A',
                        },
                        {
                            id: 'b',
                            titre: 'Carte B',
                            definition: 'Définition B',
                            icone: 'pi-exclamation-triangle',
                            valeur: '3',
                            detail: '3 alertes',
                            ton: 'avertissement',
                        },
                    ],
                },
            ],
        },
    });

    it('affiche le titre de section suivi de sa précision', () => {
        expect(
            texte(wrapper.find('[data-testid="essai-ligne-etat"]').text()),
        ).toBe('État actuel · à ce jour');
    });

    it('rend chaque carte avec sa définition, son icône, sa valeur et son détail', () => {
        const carte = wrapper.find('[data-testid="kpi-a"]');

        expect(carte.find('[title]').attributes('title')).toBe('Définition A');
        expect(carte.find('[data-slot="icone"]').classes()).toContain('pi-box');
        expect(
            carte.find('[data-slot="icone"]').attributes('aria-hidden'),
        ).toBe('true');
        expect(carte.find('[data-slot="valeur"]').text()).toBe('12');
        expect(carte.find('[data-slot="detail"]').text()).toBe('Détail A');
    });

    it('colore le détail en ambre seulement pour un avertissement', () => {
        const neutre = wrapper.find(
            '[data-testid="kpi-a"] [data-slot="detail"]',
        );
        const alerte = wrapper.find(
            '[data-testid="kpi-b"] [data-slot="detail"]',
        );

        expect(neutre.classes()).toContain('text-[var(--p-text-muted-color)]');
        expect(alerte.classes()).toContain('text-amber-600');
        expect(alerte.classes()).not.toContain(
            'text-[var(--p-text-muted-color)]',
        );
    });
});
