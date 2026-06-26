<script setup lang="ts">
import { formatGNF } from '@/lib/utils';
import type { CommissionExpenseRow } from '@/types/commission';
import { HandCoins } from 'lucide-vue-next';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        rows: CommissionExpenseRow[];
        emptyMessage?: string;
    }>(),
    {
        emptyMessage: 'Aucune dépense pour cette période.',
    },
);

const total = computed(() =>
    props.rows.reduce((sum, r) => sum + r.montant, 0),
);
</script>

<template>
    <table v-if="props.rows.length > 0" class="w-full text-sm">
        <thead>
            <tr class="border-b bg-muted/40">
                <th
                    class="px-4 py-3 text-left font-medium text-muted-foreground"
                >
                    Date
                </th>
                <th
                    class="px-4 py-3 text-left font-medium text-muted-foreground"
                >
                    Type
                </th>
                <th
                    class="px-4 py-3 text-left font-medium text-muted-foreground"
                >
                    Commentaire
                </th>
                <th
                    class="px-4 py-3 text-left font-medium text-muted-foreground"
                >
                    Saisi par
                </th>
                <th
                    class="px-4 py-3 text-left font-medium text-muted-foreground"
                >
                    Validé par
                </th>
                <th
                    class="px-4 py-3 text-right font-medium text-muted-foreground"
                >
                    Montant
                </th>
            </tr>
        </thead>
        <tbody class="divide-y">
            <tr v-for="row in props.rows" :key="row.id" class="hover:bg-muted/10">
                <td class="px-4 py-3 text-xs text-muted-foreground">
                    {{ row.date ?? '—' }}
                </td>
                <td class="px-4 py-3 text-sm">{{ row.type }}</td>
                <td class="px-4 py-3 text-xs text-muted-foreground">
                    {{ row.commentaire ?? '—' }}
                </td>
                <td class="px-4 py-3 text-xs text-muted-foreground">
                    {{ row.saisi_par ?? '—' }}
                </td>
                <td class="px-4 py-3 text-xs text-muted-foreground">
                    {{ row.validateur ?? '—' }}
                </td>
                <td
                    class="px-4 py-3 text-right font-medium text-red-600 tabular-nums dark:text-red-400"
                >
                    -{{ formatGNF(row.montant) }}
                </td>
            </tr>
        </tbody>
        <tfoot>
            <tr class="border-t-2 bg-muted/50 text-xs font-bold">
                <td class="px-4 py-2.5 tracking-wide uppercase" colspan="5">
                    Total dépenses
                </td>
                <td
                    class="px-4 py-2.5 text-right text-red-600 tabular-nums dark:text-red-400"
                >
                    -{{ formatGNF(total) }}
                </td>
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
