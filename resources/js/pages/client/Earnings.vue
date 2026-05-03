<script setup lang="ts">
import ClientLayout from '@/layouts/ClientLayout.vue';
import type {
    ActorPayload,
    EarningsPayload,
    EarningsVehiculePayload,
    StatementLine,
    VehiculeOption,
} from '@/types/client-space';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    actor: ActorPayload;
    vehicules: VehiculeOption[];
    earnings: EarningsPayload;
    earnings_by_vehicule: EarningsVehiculePayload[];
    statement: StatementLine[];
    filters: { date_debut: string | null; date_fin: string | null };
}>();

const selectedVehiculeId = ref<string | 'all'>('all');
const dateDebut = ref<string>(props.filters.date_debut ?? '');
const dateFin = ref<string>(props.filters.date_fin ?? '');

const filteredStatement = computed(() => {
    if (selectedVehiculeId.value === 'all') {
        return props.statement;
    }
    return props.statement.filter(
        (line) => line.vehicule_id === selectedVehiculeId.value,
    );
});

function applyFilters() {
    router.get(
        '/client/gains',
        {
            date_debut: dateDebut.value || undefined,
            date_fin: dateFin.value || undefined,
        },
        { preserveScroll: true },
    );
}

function resetFilters() {
    dateDebut.value = '';
    dateFin.value = '';
    router.get('/client/gains', {}, { preserveScroll: true });
}

function formatMoney(value: number): string {
    return `${new Intl.NumberFormat('fr-FR').format(value ?? 0)} GNF`;
}

const hasActiveFilter = computed(
    () => !!props.filters.date_debut || !!props.filters.date_fin,
);
</script>

<template>
    <ClientLayout>
        <Head title="Mon espace - Gains" />

        <div class="space-y-6">
            <div class="space-y-3">
                <div>
                    <h1 class="text-2xl font-semibold">Gains et releve</h1>
                </div>
                <div class="flex flex-wrap justify-end gap-2">
                    <div>
                        <label
                            class="mb-1 block text-xs font-medium text-foreground"
                        >
                            Vehicule
                        </label>
                        <select
                            v-model="selectedVehiculeId"
                            class="w-52 rounded-md border border-border bg-background px-3 py-1.5 text-sm"
                        >
                            <option value="all">Tous les vehicules</option>
                            <option
                                v-for="vehicule in vehicules"
                                :key="vehicule.id"
                                :value="vehicule.id"
                            >
                                {{ vehicule.nom_vehicule }} ({{
                                    vehicule.immatriculation ?? '-'
                                }})
                            </option>
                        </select>
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-xs font-medium text-foreground"
                            >Du</label
                        >
                        <input
                            v-model="dateDebut"
                            type="date"
                            class="rounded-md border border-border bg-background px-3 py-1.5 text-sm"
                            @change="applyFilters"
                        />
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-xs font-medium text-foreground"
                            >Au</label
                        >
                        <input
                            v-model="dateFin"
                            type="date"
                            class="rounded-md border border-border bg-background px-3 py-1.5 text-sm"
                            @change="applyFilters"
                        />
                    </div>
                    <button
                        v-if="hasActiveFilter"
                        type="button"
                        class="rounded-md border border-border px-3 py-1.5 text-xs font-medium text-muted-foreground hover:bg-muted"
                        @click="resetFilters"
                    >
                        Réinitialiser
                    </button>
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-3">
                <div class="rounded-xl border border-border bg-card p-5">
                    <p class="text-sm text-muted-foreground">Gains cumules</p>
                    <p class="mt-2 text-2xl font-semibold text-foreground">
                        {{ formatMoney(earnings.total_earned) }}
                    </p>
                </div>
                <div class="rounded-xl border border-border bg-card p-5">
                    <p class="text-sm text-muted-foreground">Deja verses</p>
                    <p class="mt-2 text-2xl font-semibold text-foreground">
                        {{ formatMoney(earnings.total_paid) }}
                    </p>
                </div>
                <div class="rounded-xl border border-border bg-card p-5">
                    <p class="text-sm text-muted-foreground">Reste à payer</p>
                    <p class="mt-2 text-2xl font-semibold text-foreground">
                        {{ formatMoney(earnings.balance) }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ earnings.operations_count }} operation(s)
                    </p>
                    <p
                        v-if="earnings.frais_depenses_total > 0"
                        class="mt-1 text-xs text-destructive"
                    >
                        dont {{ formatMoney(earnings.frais_depenses_total) }} de
                        frais déduits
                    </p>
                </div>
            </div>

            <div class="rounded-xl border border-border bg-card p-5">
                <h2 class="text-lg font-semibold">Releve des operations</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Historique des commissions generees via vos vehicules.
                </p>

                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr
                                class="border-b border-border text-left text-muted-foreground"
                            >
                                <th class="py-2 pr-4 font-medium">Date</th>
                                <th class="py-2 pr-4 font-medium">Reference</th>
                                <th class="py-2 pr-4 font-medium">Vehicule</th>
                                <th class="py-2 pr-4 font-medium">
                                    Montant net
                                </th>
                                <th class="py-2 pr-4 font-medium">Frais</th>
                                <th class="py-2 pr-4 font-medium">Verse</th>
                                <th class="py-2 pr-4 font-medium">Reste</th>
                                <th class="py-2 pr-0 font-medium">Statut</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="line in filteredStatement"
                                :key="line.id"
                                class="border-b border-border/70"
                            >
                                <td class="py-2 pr-4">
                                    {{ line.date_label ?? '-' }}
                                </td>
                                <td class="py-2 pr-4">{{ line.reference }}</td>
                                <td class="py-2 pr-4">
                                    <p>{{ line.vehicule_nom }}</p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ line.immatriculation ?? '-' }}
                                    </p>
                                </td>
                                <td class="py-2 pr-4">
                                    {{ formatMoney(line.montant_net) }}
                                </td>
                                <td class="py-2 pr-4 text-destructive">
                                    {{
                                        line.frais > 0
                                            ? `- ${formatMoney(line.frais)}`
                                            : '-'
                                    }}
                                </td>
                                <td class="py-2 pr-4">
                                    {{ formatMoney(line.montant_verse) }}
                                </td>
                                <td class="py-2 pr-4">
                                    {{ formatMoney(line.montant_restant) }}
                                </td>
                                <td class="py-2 pr-0">
                                    <span
                                        class="rounded-full bg-secondary px-2 py-1 text-xs"
                                    >
                                        {{ line.statut_label }}
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="filteredStatement.length === 0">
                                <td
                                    colspan="8"
                                    class="py-4 text-center text-muted-foreground"
                                >
                                    Aucune operation trouvee.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="rounded-xl border border-border bg-card p-5">
                <h2 class="text-lg font-semibold">Solde par vehicule</h2>

                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr
                                class="border-b border-border text-left text-muted-foreground"
                            >
                                <th class="py-2 pr-4 font-medium">Vehicule</th>
                                <th class="py-2 pr-4 font-medium">
                                    Immatriculation
                                </th>
                                <th class="py-2 pr-4 font-medium">Gains</th>
                                <th
                                    class="py-2 pr-4 font-medium text-destructive"
                                >
                                    Frais
                                </th>
                                <th class="py-2 pr-4 font-medium">Verses</th>
                                <th class="py-2 pr-0 font-medium">
                                    Reste à payer
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in earnings_by_vehicule"
                                :key="row.vehicule_id"
                                class="border-b border-border/70"
                            >
                                <td class="py-2 pr-4">
                                    {{ row.nom_vehicule }}
                                </td>
                                <td class="py-2 pr-4">
                                    {{ row.immatriculation ?? '-' }}
                                </td>
                                <td class="py-2 pr-4">
                                    {{ formatMoney(row.total_earned) }}
                                </td>
                                <td class="py-2 pr-4 text-destructive">
                                    {{
                                        row.frais_depenses > 0
                                            ? `- ${formatMoney(row.frais_depenses)}`
                                            : '-'
                                    }}
                                </td>
                                <td class="py-2 pr-4">
                                    {{ formatMoney(row.total_paid) }}
                                </td>
                                <td class="py-2 pr-0">
                                    {{ formatMoney(row.balance) }}
                                </td>
                            </tr>
                            <tr v-if="earnings_by_vehicule.length === 0">
                                <td
                                    colspan="6"
                                    class="py-4 text-center text-muted-foreground"
                                >
                                    Aucun vehicule partenaire detecte pour ce
                                    compte.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </ClientLayout>
</template>
