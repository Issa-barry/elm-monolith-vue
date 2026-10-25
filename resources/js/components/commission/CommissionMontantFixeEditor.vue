<script setup lang="ts">
/**
 * Éditeur du partage Livreur en montants GNF entiers fixes — remplace
 * CommissionShareEditor pour cet usage (équipe de livraison, vente). Plus
 * aucun pourcentage : chaque membre reçoit un montant fixe par unité, dont la
 * somme doit être exactement égale à l'enveloppe du barème (jamais de
 * tolérance flottante — cf. incident CMD-230826-004).
 *
 * Parrain du véhicule (ADR 0035) : bénéficiaire de la même enveloppe, affiché à
 * part (identité gérée dans l'onglet Parrain) — sa part entre dans le total.
 * Les totaux suivent la frappe, pas seulement la sortie du champ. La somme reste
 * revérifiée par le serveur.
 *
 * CommissionShareEditor.vue reste inchangé et continue de servir l'équipe de
 * gestion (dépôt), hors périmètre de ce refactor.
 */
import InputNumber from 'primevue/inputnumber';
import { computed, ref } from 'vue';

export interface CommissionMontantFixeMembre {
    id: string;
    label: string;
    montant_unitaire: number;
}

const props = defineProps<{
    modelValue: CommissionMontantFixeMembre[];
    enveloppeUnitaire: number;
    readonly?: boolean;
    /** Parrain du véhicule rémunéré sur ce partage, null sinon (aucune ligne affichée). */
    parrain?: { nom: string } | null;
    partParrain?: number | null;
}>();

const emit = defineEmits<{
    'update:modelValue': [CommissionMontantFixeMembre[]];
    'update:partParrain': [number];
}>();

const avecParrain = computed(() => !!props.parrain);

/**
 * Un seul livreur et un parrain : la répartition est entièrement déterminée par le barème —
 * l'utilisateur saisit une part, l'autre se calcule (barème − part saisie). Avec plusieurs
 * livreurs, rien n'est recalculé d'office : leurs montants restent ceux saisis. Le calcul ne vaut
 * jamais confirmation d'une part nulle du parrain (PARRAIN-022, confirmée à part).
 */
const calculAuto = computed(
    () =>
        avecParrain.value &&
        props.modelValue.length === 1 &&
        !props.readonly &&
        props.enveloppeUnitaire > 0,
);
/** Champ saisi en dernier : l'autre est affiché comme calculé. */
const champSaisi = ref<'livreur' | 'parrain' | null>(null);
const alerteLimite = ref<string | null>(null);

/** Part entière entre 0 et le barème ; un dépassement est ramené au barème et signalé. */
function borner(val: number | null): number {
    const v = Math.max(0, Math.round(val ?? 0));
    if (v > props.enveloppeUnitaire) {
        alerteLimite.value = `La part ne peut pas dépasser ${formatGNF(props.enveloppeUnitaire)}. Elle a été ramenée à ${formatGNF(props.enveloppeUnitaire)}.`;
        return props.enveloppeUnitaire;
    }
    // La valeur plafonnée revient au blur : le message reste tant qu'une part plus basse n'est
    // pas saisie.
    if (v < props.enveloppeUnitaire) {
        alerteLimite.value = null;
    }
    return v;
}

function onMontantChange(id: string, val: number | null) {
    if (calculAuto.value) {
        const livreur = borner(val);
        champSaisi.value = 'livreur';
        emit('update:modelValue', [
            { ...props.modelValue[0], montant_unitaire: livreur },
        ]);
        emit('update:partParrain', props.enveloppeUnitaire - livreur);
        return;
    }

    const list = props.modelValue.map((m) =>
        m.id === id ? { ...m, montant_unitaire: val ?? 0 } : m,
    );
    emit('update:modelValue', list);
}

function onPartParrainChange(val: number | null) {
    if (calculAuto.value) {
        const parrain = borner(val);
        champSaisi.value = 'parrain';
        emit('update:partParrain', parrain);
        emit('update:modelValue', [
            {
                ...props.modelValue[0],
                montant_unitaire: props.enveloppeUnitaire - parrain,
            },
        ]);
        return;
    }

    emit('update:partParrain', val ?? 0);
}
const montantParrain = computed(() =>
    avecParrain.value ? (props.partParrain ?? 0) : 0,
);

const totalAttribue = computed(
    () =>
        props.modelValue.reduce((s, m) => s + (m.montant_unitaire || 0), 0) +
        montantParrain.value,
);

const reste = computed(() => props.enveloppeUnitaire - totalAttribue.value);

const etat = computed<'reste' | 'depassement' | 'complet'>(() => {
    if (reste.value > 0) return 'reste';
    if (reste.value < 0) return 'depassement';
    return 'complet';
});

const libelleEtat = computed(() => {
    if (etat.value === 'complet') return 'Répartition équilibrée';
    if (etat.value === 'reste') return 'Reste à répartir';
    return 'Dépassement du barème — réduisez une part';
});

/**
 * Répartit `barème − part du parrain` entre les membres au prorata de leurs
 * montants actuels (arrondi au GNF inférieur, reliquat au premier membre —
 * le chauffeur), comme la proposition de la reconfiguration des barèmes.
 * Rien n'est enregistré : les montants restent modifiables.
 */
function ajusterAuProrata() {
    const cible = Math.max(0, props.enveloppeUnitaire - montantParrain.value);
    const total = props.modelValue.reduce(
        (s, m) => s + (m.montant_unitaire || 0),
        0,
    );
    if (props.modelValue.length === 0) return;

    const parts = props.modelValue.map((m) =>
        total > 0
            ? Math.floor(((m.montant_unitaire || 0) * cible) / total)
            : Math.floor(cible / props.modelValue.length),
    );
    parts[0] += cible - parts.reduce((s, p) => s + p, 0);

    emit(
        'update:modelValue',
        props.modelValue.map((m, i) => ({ ...m, montant_unitaire: parts[i] })),
    );
}

function formatGNF(val: number): string {
    return `${new Intl.NumberFormat('fr-FR').format(Math.abs(val))} GNF`;
}

defineExpose({
    valide: computed(() => etat.value === 'complet'),
});
</script>

<template>
    <div class="overflow-hidden rounded-lg border">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b bg-muted/40">
                    <th
                        class="px-3 py-2.5 text-left text-xs font-medium text-muted-foreground"
                    >
                        Bénéficiaire
                    </th>
                    <th
                        class="px-3 py-2.5 text-right text-xs font-medium text-muted-foreground"
                    >
                        GNF / pack
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="m in modelValue"
                    :key="m.id"
                    class="border-b last:border-b-0"
                >
                    <td class="px-3 py-2 text-sm">{{ m.label }}</td>
                    <td class="w-40 px-3 py-2">
                        <InputNumber
                            :model-value="m.montant_unitaire || null"
                            placeholder="0"
                            :min="0"
                            :max-fraction-digits="0"
                            :disabled="readonly"
                            class="w-full"
                            :data-testid="`partage-livreur-montant-${m.id}`"
                            :input-style="{
                                textAlign: 'right',
                                width: '100%',
                            }"
                            @input="
                                onMontantChange(
                                    m.id,
                                    $event.value as number | null,
                                )
                            "
                            @update:model-value="onMontantChange(m.id, $event)"
                        />
                        <p
                            v-if="calculAuto && champSaisi === 'parrain'"
                            class="mt-1 text-right text-[11px] text-muted-foreground"
                            data-testid="partage-livreur-calcule"
                        >
                            Calculé : barème − part du parrain
                        </p>
                    </td>
                </tr>
            </tbody>
            <tbody v-if="parrain" class="border-t border-dashed">
                <tr>
                    <td
                        colspan="2"
                        class="px-3 pt-2.5 pb-1 text-xs font-medium tracking-wider text-muted-foreground uppercase"
                    >
                        Parrain du véhicule
                    </td>
                </tr>
                <tr>
                    <td class="px-3 pb-2 text-sm">
                        <p class="font-medium">{{ parrain.nom }}</p>
                        <p class="text-xs text-muted-foreground">
                            Identité définie dans l'onglet Parrain
                        </p>
                    </td>
                    <td class="w-40 px-3 pb-2">
                        <InputNumber
                            :model-value="partParrain ?? null"
                            placeholder="0"
                            :min="0"
                            :max-fraction-digits="0"
                            :disabled="readonly"
                            class="w-full"
                            data-testid="partage-parrain-montant"
                            :input-style="{
                                textAlign: 'right',
                                width: '100%',
                            }"
                            @input="
                                onPartParrainChange(
                                    $event.value as number | null,
                                )
                            "
                            @update:model-value="onPartParrainChange($event)"
                        />
                        <p
                            v-if="calculAuto && champSaisi === 'livreur'"
                            class="mt-1 text-right text-[11px] text-muted-foreground"
                            data-testid="partage-parrain-calcule"
                        >
                            Calculé : barème − part du livreur
                        </p>
                    </td>
                </tr>
                <tr v-if="calculAuto">
                    <td
                        colspan="2"
                        class="px-3 pb-2.5 text-xs text-muted-foreground"
                        data-testid="partage-calcul-auto"
                    >
                        Un seul livreur : saisissez la part du parrain ou celle
                        du livreur, l'autre est calculée pour que le total reste
                        égal au barème.
                    </td>
                </tr>
                <tr v-if="alerteLimite">
                    <td
                        colspan="2"
                        class="px-3 pb-2.5 text-xs font-medium text-orange-600"
                        data-testid="partage-alerte-limite"
                    >
                        {{ alerteLimite }}
                    </td>
                </tr>
            </tbody>
            <tfoot>
                <tr class="border-t bg-muted/20">
                    <td class="px-3 py-2.5 text-sm font-semibold">Barème</td>
                    <td
                        class="px-3 py-2.5 text-right font-mono text-sm font-semibold"
                    >
                        {{ formatGNF(enveloppeUnitaire) }}
                    </td>
                </tr>
                <tr class="bg-muted/20">
                    <td class="px-3 py-2 text-xs text-muted-foreground">
                        Total réparti
                    </td>
                    <td
                        class="px-3 py-2 text-right font-mono text-xs text-muted-foreground"
                    >
                        {{ formatGNF(totalAttribue) }}
                    </td>
                </tr>
                <tr class="bg-muted/20">
                    <td
                        class="px-3 py-2.5 text-sm font-semibold"
                        :class="{
                            'text-emerald-600': etat === 'complet',
                            'text-orange-600': etat === 'reste',
                            'text-destructive': etat === 'depassement',
                        }"
                        data-testid="partage-livreur-etat"
                    >
                        {{ libelleEtat }}
                    </td>
                    <td
                        class="px-3 py-2.5 text-right font-mono text-sm font-semibold"
                        :class="{
                            'text-emerald-600': etat === 'complet',
                            'text-orange-600': etat === 'reste',
                            'text-destructive': etat === 'depassement',
                        }"
                    >
                        {{ etat === 'complet' ? '0 GNF' : formatGNF(reste) }}
                    </td>
                </tr>
                <tr v-if="parrain && !readonly" class="bg-muted/20">
                    <td colspan="2" class="px-3 pb-2.5 text-right">
                        <button
                            type="button"
                            class="text-xs font-medium text-primary hover:underline"
                            data-testid="partage-ajuster-prorata"
                            @click="ajusterAuProrata"
                        >
                            Ajuster l'équipe au prorata
                        </button>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
</template>
