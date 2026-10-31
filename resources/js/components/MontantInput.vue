<script setup lang="ts">
/**
 * Champ de montant commun : séparateurs de milliers pendant la frappe (« 750 000 »), collage d'un
 * montant déjà formaté accepté, curseur conservé. Le v-model reçoit un nombre (null si vide),
 * jamais le texte affiché. Les attributs (id, placeholder, data-testid, class…) vont au champ.
 */
import {
    analyserSaisieMontant,
    formaterMontantSaisie,
} from '@/lib/montantSaisie';
import { cn } from '@/lib/utils';
import { computed, ref, useAttrs, watch } from 'vue';

defineOptions({ inheritAttrs: false });

const attrs = useAttrs();
const attributsChamp = computed(() => {
    const { class: _classe, ...reste } = attrs;
    return reste;
});

const props = withDefaults(
    defineProps<{
        modelValue?: number | string | null;
        /** Nombre de décimales acceptées (0 pour les montants en GNF). */
        decimales?: number;
        /** Unité affichée dans le champ, ex. « GNF » ; aucune si vide. */
        unite?: string;
        invalid?: boolean;
    }>(),
    { modelValue: null, decimales: 0, unite: '', invalid: false },
);

const emit = defineEmits<{ 'update:modelValue': [number | null] }>();

const affichage = ref(formaterMontantSaisie(props.modelValue, props.decimales));
const champ = ref<HTMLInputElement | null>(null);

function valeurAffichee(): number | null {
    return analyserSaisieMontant(affichage.value, props.decimales).valeur;
}

// Valeur changée par le formulaire (préremplissage, remise à zéro) : on réaffiche.
watch(
    () => props.modelValue,
    (valeur) => {
        const attendue =
            valeur === null || valeur === '' || valeur === undefined
                ? null
                : Number(valeur);
        if (attendue !== valeurAffichee()) {
            affichage.value = formaterMontantSaisie(valeur, props.decimales);
        }
    },
);

function chiffres(texte: string): number {
    return texte.replace(/\D/g, '').length;
}

/** Position juste après le n-ième chiffre du texte affiché. */
function positionApresChiffres(texte: string, n: number): number {
    if (n <= 0) return 0;
    let vus = 0;
    for (let i = 0; i < texte.length; i++) {
        if (/\d/.test(texte[i]) && ++vus === n) return i + 1;
    }
    return texte.length;
}

function onInput(event: Event) {
    const el = event.target as HTMLInputElement;
    const curseur = el.selectionStart ?? el.value.length;
    const chiffresAvant = chiffres(el.value.slice(0, curseur));
    const auBout = curseur >= el.value.length;

    const saisie = analyserSaisieMontant(el.value, props.decimales);
    affichage.value = saisie.affichage;
    el.value = saisie.affichage;

    const position = auBout
        ? saisie.affichage.length
        : positionApresChiffres(saisie.affichage, chiffresAvant);
    if (document.activeElement === el) {
        el.setSelectionRange(position, position);
    }

    emit('update:modelValue', saisie.valeur);
}

defineExpose({ focus: () => champ.value?.focus() });
</script>

<template>
    <div class="relative w-full">
        <input
            ref="champ"
            v-bind="attributsChamp"
            :value="affichage"
            type="text"
            :inputmode="decimales > 0 ? 'decimal' : 'numeric'"
            autocomplete="off"
            :aria-invalid="invalid || undefined"
            :class="
                cn(
                    'flex h-10 w-full min-w-0 rounded-md border border-input bg-background px-3 py-2 text-sm tabular-nums shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground disabled:cursor-not-allowed disabled:opacity-50',
                    'focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50',
                    'aria-invalid:border-destructive aria-invalid:ring-destructive/20',
                    unite ? 'pr-14' : '',
                    attrs.class as string,
                )
            "
            @input="onInput"
        />
        <span
            v-if="unite"
            class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-xs font-medium text-muted-foreground"
            >{{ unite }}</span
        >
    </div>
</template>
