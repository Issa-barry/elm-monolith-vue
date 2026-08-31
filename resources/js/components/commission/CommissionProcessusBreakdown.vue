<script setup lang="ts">
import { formatGNF } from '@/lib/utils';
import type { CommissionProcessusBreakdownRow } from '@/types/commission';
import { computed } from 'vue';

const props = defineProps<{
    rows: CommissionProcessusBreakdownRow[];
}>();

const total = computed(() =>
    props.rows.reduce((sum, r) => sum + r.total_genere, 0),
);
</script>

<template>
    <div
        class="rounded-xl border bg-card p-4"
        data-testid="commission-processus-breakdown"
    >
        <p
            class="mb-3 text-xs font-semibold tracking-wider text-muted-foreground uppercase"
        >
            Répartition par processus
        </p>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div
                v-for="row in rows"
                :key="row.code"
                class="rounded-lg border bg-muted/20 px-3 py-2"
            >
                <p class="text-xs text-muted-foreground">{{ row.label }}</p>
                <p class="mt-0.5 text-sm font-semibold tabular-nums">
                    {{ formatGNF(row.total_genere) }}
                </p>
            </div>
            <div
                class="rounded-lg border border-primary/30 bg-primary/5 px-3 py-2"
            >
                <p class="text-xs text-muted-foreground">Total commissions</p>
                <p class="mt-0.5 text-sm font-bold tabular-nums">
                    {{ formatGNF(total) }}
                </p>
            </div>
        </div>
    </div>
</template>
