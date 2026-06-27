<script setup lang="ts">
import ClientLayout from '@/layouts/ClientLayout.vue';
import type {
    EarningsVehiculePayload,
    StatementLine,
    VehiculeOption,
} from '@/types/client-space';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    vehicule: VehiculeOption;
    summary: EarningsVehiculePayload;
    statement: StatementLine[];
    filters: { date_debut: string | null; date_fin: string | null };
}>();

const periodLabel = computed(() => {
    if (props.filters.date_debut && props.filters.date_fin) {
        return `Du ${formatDate(props.filters.date_debut)} au ${formatDate(props.filters.date_fin)}`;
    }
    if (props.filters.date_debut) {
        return `Depuis ${formatDate(props.filters.date_debut)}`;
    }
    if (props.filters.date_fin) {
        return `Jusqu'au ${formatDate(props.filters.date_fin)}`;
    }

    return 'Toutes les periodes';
});

function formatDate(value: string): string {
    return new Intl.DateTimeFormat('fr-FR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(new Date(value));
}

function formatMoney(value: number): string {
    return `${new Intl.NumberFormat('fr-FR').format(value ?? 0)} GNF`;
}

function statusClass(statut: string): string {
    if (statut === 'paye') {
        return 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300';
    }
    if (statut === 'partiel') {
        return 'bg-amber-500/15 text-amber-700 dark:text-amber-300';
    }

    return 'bg-zinc-500/15 text-zinc-700 dark:text-zinc-300';
}

function goBack(): void {
    if (typeof window !== 'undefined' && window.history.length > 1) {
        window.history.back();

        return;
    }

    window.location.href = '/client/dashboard';
}
</script>

<template>
    <ClientLayout>
        <Head title="Mon espace - Solde vehicule" />

        <div class="space-y-5">
            <div class="flex items-center justify-between gap-3">
                <button
                    type="button"
                    class="inline-flex items-center gap-1 rounded-md border border-border px-3 py-1.5 text-sm font-medium text-foreground transition-colors hover:bg-muted"
                    @click="goBack"
                >
                    <ArrowLeft class="h-4 w-4" />
                    Retour
                </button>

                <Link
                    href="/client/dashboard"
                    class="text-xs text-muted-foreground underline-offset-2 hover:underline"
                >
                    Tableau de bord
                </Link>
            </div>

            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-xs font-medium text-muted-foreground uppercase">
                    Solde vehicule
                </p>
                <h1 class="mt-1 text-xl font-semibold text-foreground">
                    {{ vehicule.nom_vehicule }}
                </h1>
                <p class="mt-0.5 text-sm text-muted-foreground">
                    {{ vehicule.immatriculation ?? '-' }}
                </p>
                <p class="mt-2 text-xs text-muted-foreground">
                    {{ periodLabel }}
                </p>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div class="rounded-xl border border-border bg-card p-3">
                    <p class="text-xs text-muted-foreground">Gains</p>
                    <p class="mt-1 text-lg font-semibold text-foreground">
                        {{ formatMoney(summary.total_earned) }}
                    </p>
                </div>
                <div class="rounded-xl border border-border bg-card p-3">
                    <p class="text-xs text-muted-foreground">Dépenses</p>
                    <p class="mt-1 text-lg font-semibold text-destructive">
                        {{
                            summary.frais_depenses > 0
                                ? `- ${formatMoney(summary.frais_depenses)}`
                                : '-'
                        }}
                    </p>
                </div>
                <div class="rounded-xl border border-border bg-card p-3">
                    <p class="text-xs text-muted-foreground">Verses</p>
                    <p class="mt-1 text-lg font-semibold text-foreground">
                        {{ formatMoney(summary.total_paid) }}
                    </p>
                </div>
                <div class="rounded-xl border border-border bg-card p-3">
                    <p class="text-xs text-muted-foreground">Reste a payer</p>
                    <p class="mt-1 text-lg font-semibold text-foreground">
                        {{ formatMoney(summary.balance) }}
                    </p>
                </div>
            </div>

            <div class="rounded-xl border border-border bg-card p-4">
                <h2 class="text-base font-semibold text-foreground">
                    Operations detaillees
                </h2>

                <div
                    v-if="statement.length === 0"
                    class="mt-3 text-sm text-muted-foreground"
                >
                    Aucune operation trouvee pour ce vehicule.
                </div>

                <div v-else class="mt-3 space-y-3">
                    <div
                        v-for="line in statement"
                        :key="line.id"
                        class="rounded-lg border border-border p-3"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p
                                    class="truncate text-sm font-semibold text-foreground"
                                >
                                    {{ line.reference }}
                                </p>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    {{ line.date_label ?? '-' }}
                                </p>
                            </div>
                            <span
                                class="inline-flex shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium"
                                :class="statusClass(line.statut)"
                            >
                                {{ line.statut_label }}
                            </span>
                        </div>

                        <div
                            class="mt-3 grid grid-cols-2 gap-x-3 gap-y-2 text-xs"
                        >
                            <div>
                                <p class="text-muted-foreground">Montant net</p>
                                <p class="font-medium text-foreground">
                                    {{ formatMoney(line.montant_net) }}
                                </p>
                            </div>
                            <div>
                                <p class="text-muted-foreground">Dépenses</p>
                                <p class="font-medium text-destructive">
                                    {{
                                        line.frais > 0
                                            ? `- ${formatMoney(line.frais)}`
                                            : '-'
                                    }}
                                </p>
                            </div>
                            <div>
                                <p class="text-muted-foreground">Verse</p>
                                <p class="font-medium text-foreground">
                                    {{ formatMoney(line.montant_verse) }}
                                </p>
                            </div>
                            <div>
                                <p class="text-muted-foreground">Reste</p>
                                <p class="font-medium text-foreground">
                                    {{ formatMoney(line.montant_restant) }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </ClientLayout>
</template>
