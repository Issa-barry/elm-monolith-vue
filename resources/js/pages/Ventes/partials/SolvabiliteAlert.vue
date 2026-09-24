<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';

defineProps<{
    status: string;
    derogation: boolean;
    montant: string;
    plafond: string;
    nombreFactures: number;
}>();

defineEmits<{ voirFactures: [] }>();
</script>

<template>
    <section
        aria-label="Situation des factures"
        class="@container mt-3 min-w-0 rounded-xl border border-amber-200 bg-amber-50 p-3 text-slate-900 dark:border-amber-800 dark:bg-amber-950/30 dark:text-slate-100"
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <StatusDot
                :status="status"
                :label="
                    `${status === 'impaye' ? 'Factures impayées' : 'Paiement partiel'} (${nombreFactures})`
                "
                class="text-sm font-semibold whitespace-normal text-amber-950 dark:text-amber-100"
            />
            <button
                type="button"
                class="inline-flex min-h-8 shrink-0 items-center justify-center rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-xs font-medium text-amber-900 transition-colors hover:bg-amber-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-700 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100 dark:hover:bg-amber-900 dark:focus-visible:outline-amber-400"
                @click="$emit('voirFactures')"
            >
                Voir les factures
            </button>
        </div>

        <dl
            class="mt-3 grid gap-x-3 gap-y-2 text-center"
            :class="derogation ? 'grid-cols-2' : 'grid-cols-1'"
        >
            <div>
                <dt class="text-sm text-slate-700 dark:text-slate-300">
                    Dette actuelle
                </dt>
                <dd
                    class="mt-0.5 text-base font-semibold tabular-nums @min-[26rem]:text-lg"
                >
                    {{ montant }}
                </dd>
            </div>
            <div v-if="derogation">
                <dt class="text-sm text-slate-700 dark:text-slate-300">
                    Plafond dérogatoire
                </dt>
                <dd
                    class="mt-0.5 text-base font-semibold tabular-nums @min-[26rem]:text-lg"
                >
                    {{ plafond }}
                </dd>
            </div>
        </dl>
    </section>
</template>
