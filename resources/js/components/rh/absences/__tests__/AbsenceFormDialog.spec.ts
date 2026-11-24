import AbsenceFormDialog from '@/components/rh/absences/AbsenceFormDialog.vue';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, nextTick, reactive } from 'vue';

const envoi = vi.hoisted(() => ({
    post: vi.fn(),
    transform: vi.fn(),
    errors: {} as Record<string, string>,
}));

vi.mock('@inertiajs/vue3', () => ({
    useForm: (data: Record<string, unknown>) => {
        const form = reactive({
            ...data,
            errors: envoi.errors,
            processing: false,
            clearErrors: vi.fn(),
            transform(fn: (d: Record<string, unknown>) => unknown) {
                envoi.transform(fn);
                return form;
            },
            post: envoi.post,
        });
        return form;
    },
}));

const Dialog = defineComponent({
    props: ['visible', 'header'],
    template: '<div v-if="visible"><h2>{{ header }}</h2><slot /></div>',
});
const Select = defineComponent({
    props: ['modelValue', 'options'],
    emits: ['update:modelValue'],
    template:
        '<select :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value)"><option value=""></option><option v-for="o in options" :key="o.value" :value="o.value">{{ o.label }}</option></select>',
});

const MOTIFS = [
    { value: 'm1', label: 'Maladie' },
    { value: 'm2', label: 'Motif familial' },
];

const monter = (props: Record<string, unknown> = {}) =>
    mount(AbsenceFormDialog, {
        props: { visible: true, absence: null, motifs: MOTIFS, ...props },
        global: { stubs: { Dialog, Select } },
    });

describe('AbsenceFormDialog', () => {
    beforeEach(() => {
        envoi.post.mockReset();
        envoi.transform.mockReset();
        for (const cle of Object.keys(envoi.errors)) delete envoi.errors[cle];
    });

    it('présente les champs dans l’ordre : salarié, motif, dates, commentaire, justificatif', () => {
        const w = monter({
            salaries: [{ value: 'e1', label: 'Alpha DIALLO' }],
        });
        const html = w.html();
        const ordre = [
            'absence-salarie',
            'absence-motif',
            'absence-debut',
            'absence-fin',
            'absence-commentaire',
            'absence-justificatif',
        ].map((id) => html.indexOf(`data-testid="${id}"`));
        expect(ordre.every((pos) => pos >= 0)).toBe(true);
        expect([...ordre].sort((a, b) => a - b)).toEqual(ordre);
        // Le libellé est « Motif », jamais « Type d’absence ».
        expect(w.text()).toContain('Motif');
        expect(w.text()).not.toMatch(/type d.absence/i);
    });

    it('affiche la durée calculée et reprend la date de début comme fin', async () => {
        const w = monter({ salarieFixe: { id: 'e1', nom: 'Alpha DIALLO' } });
        await w.get('[data-testid="absence-debut"]').setValue('2026-11-10');
        await nextTick();
        expect(
            (w.get('[data-testid="absence-fin"]').element as HTMLInputElement)
                .value,
        ).toBe('2026-11-10');
        expect(w.get('[data-testid="absence-duree"]').text()).toContain(
            '1 jour',
        );

        await w.get('[data-testid="absence-fin"]').setValue('2026-11-12');
        expect(w.get('[data-testid="absence-duree"]').text()).toContain(
            '3 jours',
        );
    });

    it('n’envoie rien sans motif et envoie la saisie complète, commentaire et justificatif facultatifs', async () => {
        const w = monter({ salarieFixe: { id: 'e1', nom: 'Alpha DIALLO' } });
        await w.get('[data-testid="absence-debut"]').setValue('2026-11-10');
        await w.get('[data-testid="absence-fin"]').setValue('2026-11-12');

        const bouton = w.get('[data-testid="absence-enregistrer"]');
        expect(bouton.attributes('disabled')).toBeDefined();

        await w.get('[data-testid="absence-motif"]').setValue('m1');
        expect(bouton.attributes('disabled')).toBeUndefined();
        await w.get('form').trigger('submit');

        expect(envoi.post).toHaveBeenCalledWith(
            '/backoffice/absences',
            expect.objectContaining({ forceFormData: true }),
        );
    });

    it('modifie en PUT simulé, sans renvoyer le salarié', async () => {
        const w = monter({
            absence: {
                id: 'a1',
                employe_id: 'e1',
                salarie: 'Alpha DIALLO',
                motif_id: 'm1',
                motif: 'Maladie',
                date_debut: '2026-11-10',
                date_fin: '2026-11-12',
                commentaire: null,
                a_justificatif: false,
                justificatif_nom: null,
            },
        });
        expect(w.text()).toContain('Modifier l’absence');
        await w.get('form').trigger('submit');

        expect(envoi.post).toHaveBeenCalledWith(
            '/backoffice/absences/a1',
            expect.anything(),
        );
        const transformation = envoi.transform.mock.calls[0][0];
        const envoye = transformation({
            employe_id: 'e1',
            motif_absence_id: 'm1',
        });
        expect(envoye).toEqual({ motif_absence_id: 'm1', _method: 'put' });
    });

    it('signale un chevauchement et propose de voir l’absence existante', async () => {
        envoi.errors.chevauchement =
            'Ce salarié a déjà une absence sur cette période (Maladie, du 10/11/2026 au 12/11/2026).';
        envoi.errors.absence_existante = 'a-existante';
        const w = monter({ salarieFixe: { id: 'e1', nom: 'Alpha DIALLO' } });

        expect(w.get('[data-testid="absence-chevauchement"]').text()).toContain(
            'déjà une absence',
        );
        await w.get('[data-testid="absence-voir-existante"]').trigger('click');
        expect(w.emitted('voir-absence')).toEqual([['a-existante']]);
    });
});
