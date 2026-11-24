import AbsencesSalarie from '@/pages/Employes/partials/AbsencesSalarie.vue';
import type { AbsenceRow } from '@/types/absence';
import { shallowMount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@/composables/useFlashToast', () => ({ useFlashToast: vi.fn() }));

const absence = (surcharge: Partial<AbsenceRow> = {}): AbsenceRow => ({
    id: 'a1',
    employe_id: 'e1',
    salarie: 'Alpha DIALLO',
    matricule: '001',
    agence: 'Matoto',
    motif_id: 'm1',
    motif: 'Maladie',
    date_debut: '2026-11-10',
    date_fin: '2026-11-12',
    date_debut_label: '10/11/2026',
    date_fin_label: '12/11/2026',
    duree_jours: 3,
    commentaire: 'Certificat transmis',
    a_justificatif: true,
    justificatif_nom: 'certificat.pdf',
    statut: 'enregistree',
    etat: 'terminee',
    etat_label: 'Terminée',
    auteur: 'Issa BARRY',
    saisie_le: '10/11/2026 08:00',
    peut_modifier: true,
    peut_annuler: true,
    ...surcharge,
});

const monter = (absences: AbsenceRow[], peutSaisir = true) =>
    shallowMount(AbsencesSalarie, {
        props: {
            employeId: 'e1',
            employeNom: 'Alpha DIALLO',
            absences,
            options: { peut_saisir: peutSaisir, motifs: [] },
        },
        global: { renderStubDefaultSlot: true },
    });

describe('Bloc Absences de la fiche salarié', () => {
    it('affiche motif, dates, durée, statut et commentaire', () => {
        const w = monter([absence()]);
        const texte = w.text();
        expect(texte).toContain('Maladie');
        expect(texte).toContain('Du 10/11/2026 au 12/11/2026');
        expect(texte).toContain('3 jours');
        expect(texte).toContain('Certificat transmis');
        expect(w.findComponent({ name: 'StatusDot' }).props()).toMatchObject({
            status: 'terminee',
            label: 'Terminée',
        });
    });

    it('montre les absences annulées et un état vide explicite', () => {
        expect(
            monter([absence({ statut: 'annulee', etat: 'annulee' })])
                .findAll('[data-testid="employe-absence"]')
                .at(0)
                ?.classes(),
        ).toContain('opacity-60');
        expect(monter([]).text()).toContain('Aucune absence enregistrée.');
    });

    it('n’affiche « Nouvelle absence » que si le serveur l’accepte', () => {
        expect(
            monter([], true)
                .find('[data-testid="employe-absence-nouvelle"]')
                .exists(),
        ).toBe(true);
        expect(
            monter([], false)
                .find('[data-testid="employe-absence-nouvelle"]')
                .exists(),
        ).toBe(false);
    });

    it('reste compacte : 5 absences puis « Afficher … de plus »', () => {
        const liste = Array.from({ length: 7 }, (_, i) =>
            absence({ id: `a${i}` }),
        );
        const w = monter(liste);
        expect(w.findAll('[data-testid="employe-absence"]')).toHaveLength(5);
        expect(w.text()).toContain('Afficher 2 absences de plus');
    });
});
