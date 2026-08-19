<script setup lang="ts">
/**
 * Composant générique de partage — Montant (GNF) + Part (%) toujours visibles
 * ensemble, saisie de l'un ou l'autre indifféremment, auto-complétion du
 * dernier bénéficiaire non touché. Réutilisé pour l'équipe de livraison
 * (véhicule) ET l'équipe de gestion (dépôt) — jamais deux implémentations
 * dupliquées (cf. conception cible §I, §0.3).
 *
 * `enveloppeMontant` est indicatif : la vraie source de vérité reste
 * `part_pourcentage`, seule valeur persistée. Le montant n'est qu'une aide de
 * saisie dérivée de ce montant de référence (le montant réel dépend de la
 * catégorie vendue à chaque vente, jamais fixe pour un couple donné).
 */
import InputNumber from 'primevue/inputnumber';
import { computed, ref } from 'vue';

export interface CommissionShareMembre {
    id: string;
    label: string;
    part_pourcentage: number;
}

/**
 * Ligne informative non éditable affichée AVANT les bénéficiaires du partage
 * (ex : le propriétaire d'un véhicule, dont le montant vient directement d'un
 * autre barème et ne participe jamais au partage à 100 % ci-dessous) — garde
 * le repère visuel d'un tableau unique "tous les bénéficiaires", sans jamais
 * entrer dans le calcul du total/de la validité du partage.
 */
export interface CommissionShareReadonlyRow {
    badge?: string;
    label: string;
    montant: number | null;
    pct: number | null;
}

const props = withDefaults(
    defineProps<{
        modelValue: CommissionShareMembre[];
        enveloppeMontant: number | null;
        readonly?: boolean;
        readonlyRow?: CommissionShareReadonlyRow | null;
        totalLabel?: string;
    }>(),
    { totalLabel: 'Total' },
);

const emit = defineEmits<{
    'update:modelValue': [CommissionShareMembre[]];
}>();

const touchedIds = ref<Set<string>>(new Set());

function toMontant(pct: number): number {
    if (!props.enveloppeMontant) return 0;
    return Math.round((pct / 100) * props.enveloppeMontant);
}

function toPct(montant: number): number {
    if (!props.enveloppeMontant || props.enveloppeMontant <= 0) return 0;
    return parseFloat(((montant / props.enveloppeMontant) * 100).toFixed(2));
}

/**
 * Complète automatiquement le seul bénéficiaire restant non saisi avec le
 * reliquat (100 % - somme des autres) — jamais s'il reste 2+ bénéficiaires
 * non saisis (répartition ambiguë), jamais si le reliquat est négatif.
 */
function recomputeAutoFill(
    list: CommissionShareMembre[],
    editedId: string,
): CommissionShareMembre[] {
    touchedIds.value.add(editedId);
    if (list.length < 2) return list;

    const untouched = list.filter((m) => !touchedIds.value.has(m.id));
    if (untouched.length !== 1) return list;

    const cible = untouched[0];
    const sommeAutres = list
        .filter((m) => m.id !== cible.id)
        .reduce((s, m) => s + (m.part_pourcentage || 0), 0);
    const reste = 100 - sommeAutres;
    if (reste < 0) return list;

    return list.map((m) =>
        m.id === cible.id
            ? { ...m, part_pourcentage: parseFloat(reste.toFixed(2)) }
            : m,
    );
}

function onPctChange(id: string, val: number | null) {
    const nv = val ?? 0;
    let list = props.modelValue.map((m) =>
        m.id === id ? { ...m, part_pourcentage: nv } : m,
    );
    list = recomputeAutoFill(list, id);
    emit('update:modelValue', list);
}

function onMontantChange(id: string, val: number | null) {
    const pct = toPct(val ?? 0);
    let list = props.modelValue.map((m) =>
        m.id === id ? { ...m, part_pourcentage: pct } : m,
    );
    list = recomputeAutoFill(list, id);
    emit('update:modelValue', list);
}

const total = computed(() =>
    props.modelValue.reduce((s, m) => s + (m.part_pourcentage || 0), 0),
);
const totalMontant = computed(() =>
    props.modelValue.reduce((s, m) => s + toMontant(m.part_pourcentage), 0),
);
const valide = computed(
    () => props.modelValue.length > 0 && Math.abs(total.value - 100) < 0.01,
);

function formatMontantReadonly(montant: number | null): string {
    return montant === null ? '—' : `${new Intl.NumberFormat('fr-FR').format(montant)} GNF`;
}

defineExpose({ valide });
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
                        Montant (GNF)
                    </th>
                    <th
                        class="w-32 px-3 py-2.5 text-right text-xs font-medium text-muted-foreground"
                    >
                        Part (%)
                    </th>
                </tr>
            </thead>
            <tbody>
                <!-- Ligne informative (ex: propriétaire) — jamais éditable, jamais
                     comptée dans le total/la validité du partage ci-dessous. -->
                <tr v-if="readonlyRow" class="border-b bg-primary/5">
                    <td class="px-3 py-2 text-sm">
                        <span
                            v-if="readonlyRow.badge"
                            class="mr-1.5 inline-flex items-center rounded-full bg-primary px-2 py-0.5 text-[10px] font-semibold tracking-wide text-primary-foreground uppercase"
                            >{{ readonlyRow.badge }}</span
                        >
                        <span class="font-medium text-primary">{{
                            readonlyRow.label
                        }}</span>
                    </td>
                    <td class="px-3 py-2 text-right font-mono text-sm">
                        {{ formatMontantReadonly(readonlyRow.montant) }}
                    </td>
                    <td class="px-3 py-2 text-right font-mono text-sm">
                        {{
                            readonlyRow.pct !== null
                                ? `${readonlyRow.pct} %`
                                : '—'
                        }}
                    </td>
                </tr>
                <tr
                    v-for="m in modelValue"
                    :key="m.id"
                    class="border-b last:border-b-0"
                >
                    <td class="px-3 py-2 text-sm">{{ m.label }}</td>
                    <td class="px-3 py-2">
                        <InputNumber
                            :model-value="toMontant(m.part_pourcentage) || null"
                            placeholder="0"
                            :min="0"
                            :max-fraction-digits="0"
                            :disabled="readonly || !enveloppeMontant"
                            class="w-full"
                            :input-style="{
                                textAlign: 'right',
                                width: '100%',
                            }"
                            @update:model-value="onMontantChange(m.id, $event)"
                        />
                    </td>
                    <td class="px-3 py-2">
                        <InputNumber
                            :model-value="m.part_pourcentage || null"
                            placeholder="0 %"
                            :min="0"
                            :max="100"
                            :max-fraction-digits="2"
                            suffix=" %"
                            :disabled="readonly"
                            class="w-full"
                            :input-style="{
                                textAlign: 'right',
                                width: '100%',
                            }"
                            @update:model-value="onPctChange(m.id, $event)"
                        />
                    </td>
                </tr>
            </tbody>
            <tfoot>
                <tr class="border-t bg-muted/20">
                    <td class="px-3 py-2.5 text-sm font-semibold">
                        {{ totalLabel }}
                    </td>
                    <td
                        class="px-3 py-2.5 text-right font-mono text-sm font-semibold"
                        :class="valide ? 'text-emerald-600' : 'text-destructive'"
                    >
                        {{ enveloppeMontant ? `${totalMontant} GNF` : '—' }}
                    </td>
                    <td class="px-3 py-2.5 text-right font-mono text-xs">
                        <span
                            v-if="valide"
                            class="font-semibold text-emerald-600"
                            >✓ 100 %</span
                        >
                        <span v-else class="text-destructive"
                            >{{ total.toFixed(2) }} %</span
                        >
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
    <p v-if="!valide" class="mt-2 text-xs text-destructive">
        Le partage doit totaliser 100 %.
    </p>
</template>
