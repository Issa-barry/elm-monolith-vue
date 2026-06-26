<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { formatGNF } from '@/lib/utils';
import type { CommissionDetailRow } from '@/types/commission';
import { HandCoins } from 'lucide-vue-next';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        rows: CommissionDetailRow[];
        emptyMessage?: string;
    }>(),
    {
        emptyMessage: 'Aucune commission pour cette période.',
    },
);

const totals = computed(() => ({
    montant: props.rows.reduce((sum, r) => sum + r.montant, 0),
    paye: props.rows.reduce((sum, r) => sum + r.paye, 0),
    reste: props.rows.reduce((sum, r) => sum + r.reste, 0),
}));
</script>

<template>
    <table v-if="props.rows.length > 0" class="w-full text-sm">
        <thead>
            <tr class="border-b bg-muted/40">
                <th
                    class="px-4 py-3 text-left font-medium text-muted-foreground"
                >
                    Référence
                </th>
                <th
                    class="px-4 py-3 text-left font-medium text-muted-foreground"
                >
                    Date
                </th>
                <th
                    class="px-4 py-3 text-left font-medium text-muted-foreground"
                >
                    Véhicule
                </th>
                <th
                    class="px-4 py-3 text-right font-medium text-muted-foreground"
                >
                    Montant
                </th>
                <th
                    class="px-4 py-3 text-right font-medium text-muted-foreground"
                >
                    Payé
                </th>
                <th
                    class="px-4 py-3 text-right font-medium text-muted-foreground"
                >
                    Reste
                </th>
                <th
                    class="px-4 py-3 text-left font-medium text-muted-foreground"
                >
                    Statut
                </th>
            </tr>
        </thead>
        <tbody class="divide-y">
            <tr
                v-for="row in props.rows"
                :key="row.id ?? row.commission_id"
                class="hover:bg-muted/10"
            >
                <td class="px-4 py-3 font-mono text-xs">
                    {{ row.reference ?? '—' }}
                </td>
                <td class="px-4 py-3 text-xs text-muted-foreground">
                    {{ row.date ?? '—' }}
                </td>
                <td class="px-4 py-3 text-xs text-muted-foreground">
                    {{ row.vehicule?.nom ?? '—' }}
                    <span
                        v-if="row.vehicule?.immatriculation"
                        class="block text-muted-foreground/70"
                        >{{ row.vehicule.immatriculation }}</span
                    >
                </td>
                <td class="px-4 py-3 text-right font-medium tabular-nums">
                    {{ formatGNF(row.montant) }}
                </td>
                <td
                    class="px-4 py-3 text-right text-emerald-600 tabular-nums dark:text-emerald-400"
                >
                    {{ formatGNF(row.paye) }}
                </td>
                <td class="px-4 py-3 text-right tabular-nums">
                    {{ formatGNF(row.reste) }}
                </td>
                <td class="px-4 py-3">
                    <StatusDot
                        v-if="row.statut"
                        :label="row.statut"
                        :dot-class="row.statut_dot_class ?? undefined"
                        class="text-xs text-muted-foreground"
                    />
                    <span v-else>—</span>
                </td>
            </tr>
        </tbody>
        <tfoot>
            <tr class="border-t-2 bg-muted/50 text-xs font-bold">
                <td class="px-4 py-2.5 tracking-wide uppercase" colspan="3">
                    Total
                </td>
                <td class="px-4 py-2.5 text-right tabular-nums">
                    {{ formatGNF(totals.montant) }}
                </td>
                <td
                    class="px-4 py-2.5 text-right text-emerald-600 tabular-nums dark:text-emerald-400"
                >
                    {{ formatGNF(totals.paye) }}
                </td>
                <td class="px-4 py-2.5 text-right tabular-nums">
                    {{ formatGNF(totals.reste) }}
                </td>
                <td></td>
            </tr>
        </tfoot>
    </table>
    <div
        v-else
        class="flex flex-col items-center gap-3 py-12 text-muted-foreground"
    >
        <HandCoins class="h-10 w-10 opacity-30" />
        <p class="text-sm">{{ props.emptyMessage }}</p>
    </div>
</template>
