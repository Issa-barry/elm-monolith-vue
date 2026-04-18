<script setup lang="ts">
import ClientLayout from '@/layouts/ClientLayout.vue';
import type {
    ActorPayload,
    EarningsPayload,
    EarningsVehiculePayload,
    StatementLine,
    VehiculeOption,
} from '@/types/client-space';
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    actor: ActorPayload;
    vehicules: VehiculeOption[];
    earnings: EarningsPayload;
    earnings_by_vehicule: EarningsVehiculePayload[];
    statement: StatementLine[];
}>();

const selectedVehiculeId = ref<number | 'all'>('all');

const filteredStatement = computed(() => {
    if (selectedVehiculeId.value === 'all') {
        return props.statement;
    }

    return props.statement.filter(
        (line) => line.vehicule_id === selectedVehiculeId.value,
    );
});

function formatMoney(value: number): string {
    return `${new Intl.NumberFormat('fr-FR').format(value ?? 0)} GNF`;
}
</script>

<template>
    <ClientLayout>
        <Head title="Mon espace - Gains" />

        <div class="space-y-6">
            <div>
                <h1 class="text-2xl font-semibold">Gains et releve</h1>
                <p class="mt-1 text-muted-foreground">
                    Detail des commissions par operation et par vehicule.
                </p>
            </div>

            <div class="rounded-xl border border-border bg-card p-5">
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        v-for="profile in actor.profiles"
                        :key="profile"
                        class="rounded-full bg-primary/10 px-3 py-1 text-xs font-medium text-primary"
                    >
                        {{ profile }}
                    </span>
                    <span class="text-sm text-muted-foreground">
                        {{
                            actor.organization_name
                                ? `Organisation: ${actor.organization_name}`
                                : 'Organisation non rattachee'
                        }}
                    </span>
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
                </div>
            </div>

            <div class="rounded-xl border border-border bg-card p-5">
                <h2 class="text-lg font-semibold">Releve des operations</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Historique des commissions generees via vos vehicules.
                </p>

                <div class="mt-4 max-w-xs">
                    <label class="text-sm font-medium text-foreground"
                        >Filtrer par vehicule</label
                    >
                    <select
                        v-model="selectedVehiculeId"
                        class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
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

                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr
                                class="border-b border-border text-left text-muted-foreground"
                            >
                                <th class="py-2 pr-4 font-medium">Date</th>
                                <th class="py-2 pr-4 font-medium">Reference</th>
                                <th class="py-2 pr-4 font-medium">Vehicule</th>
                                <th class="py-2 pr-4 font-medium">Montant net</th>
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
                                    {{ line.frais > 0 ? `- ${formatMoney(line.frais)}` : '-' }}
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
                                <th class="py-2 pr-4 font-medium">Verses</th>
                                <th class="py-2 pr-0 font-medium">Reste à payer</th>
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
                                <td class="py-2 pr-4">
                                    {{ formatMoney(row.total_paid) }}
                                </td>
                                <td class="py-2 pr-0">
                                    {{ formatMoney(row.balance) }}
                                </td>
                            </tr>
                            <tr v-if="earnings_by_vehicule.length === 0">
                                <td
                                    colspan="5"
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
