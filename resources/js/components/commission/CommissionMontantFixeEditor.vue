<script setup lang="ts">
/**
 * Éditeur du partage Livreur en montants GNF entiers fixes — remplace
 * CommissionShareEditor pour cet usage (équipe de livraison, vente). Plus
 * aucun pourcentage : chaque membre reçoit un montant fixe par unité, dont la
 * somme doit être exactement égale à l'enveloppe du barème (jamais de
 * tolérance flottante — cf. incident CMD-230826-004).
 *
 * CommissionShareEditor.vue reste inchangé et continue de servir l'équipe de
 * gestion (dépôt), hors périmètre de ce refactor.
 */
import InputNumber from 'primevue/inputnumber';
import { computed } from 'vue';

export interface CommissionMontantFixeMembre {
    id: string;
    label: string;
    montant_unitaire: number;
}

const props = defineProps<{
    modelValue: CommissionMontantFixeMembre[];
    enveloppeUnitaire: number;
    readonly?: boolean;
}>();

const emit = defineEmits<{
    'update:modelValue': [CommissionMontantFixeMembre[]];
}>();

function onMontantChange(id: string, val: number | null) {
    const list = props.modelValue.map((m) =>
        m.id === id ? { ...m, montant_unitaire: val ?? 0 } : m,
    );
    emit('update:modelValue', list);
}

const totalAttribue = computed(() =>
    props.modelValue.reduce((s, m) => s + (m.montant_unitaire || 0), 0),
);

const reste = computed(() => props.enveloppeUnitaire - totalAttribue.value);

const etat = computed<'reste' | 'depassement' | 'complet'>(() => {
    if (reste.value > 0) return 'reste';
    if (reste.value < 0) return 'depassement';
    return 'complet';
});

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
                        Membre
                    </th>
                    <th
                        class="px-3 py-2.5 text-right text-xs font-medium text-muted-foreground"
                    >
                        Montant fixe / unité (GNF)
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
                    <td class="px-3 py-2">
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
                            @update:model-value="onMontantChange(m.id, $event)"
                        />
                    </td>
                </tr>
            </tbody>
            <tfoot>
                <tr class="border-t bg-muted/20">
                    <td class="px-3 py-2.5 text-sm font-semibold">Enveloppe</td>
                    <td
                        class="px-3 py-2.5 text-right font-mono text-sm font-semibold"
                    >
                        {{ formatGNF(enveloppeUnitaire) }}
                    </td>
                </tr>
                <tr class="bg-muted/20">
                    <td class="px-3 py-2 text-xs text-muted-foreground">
                        Montant attribué
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
                        {{
                            etat === 'complet'
                                ? 'Répartition complète'
                                : etat === 'reste'
                                  ? 'Reste à attribuer'
                                  : 'Dépassement'
                        }}
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
            </tfoot>
        </table>
    </div>
</template>
