<script setup lang="ts">
import ClientLayout from '@/layouts/ClientLayout.vue';
import type {
    DashboardFiltersPayload,
    EarningsPayload,
    EarningsVehiculePayload,
    VehiculeOption,
} from '@/types/client-space';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface StatusOption {
    value: string;
    label: string;
}

const props = defineProps<{
    earnings: EarningsPayload;
    earnings_by_vehicule: EarningsVehiculePayload[];
    vehicules: VehiculeOption[];
    filters: DashboardFiltersPayload;
    status_options: StatusOption[];
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);
const userFullName = computed(() => {
    const prenom = (user.value?.prenom ?? '').trim();
    const nom = (user.value?.nom ?? '').trim();
    const fullName = [prenom, nom].filter(Boolean).join(' ');

    return fullName || user.value?.name || 'Mon compte';
});
const userInitials = computed(() => {
    const parts = userFullName.value.split(/\s+/).filter(Boolean);

    if (parts.length === 0) {
        return '--';
    }

    if (parts.length === 1) {
        return parts[0].slice(0, 2).toUpperCase();
    }

    return `${parts[0][0] ?? ''}${parts[1][0] ?? ''}`.toUpperCase();
});

const periodOptions: Array<{
    value: DashboardFiltersPayload['period'];
    label: string;
}> = [
    { value: '7j', label: '7j' },
    { value: '30j', label: '30j' },
    { value: 'ce_mois', label: 'Ce mois' },
    { value: 'mois_passe', label: 'Mois passe' },
    { value: 'custom', label: 'Personnalise' },
];

const selectedPeriod = ref<DashboardFiltersPayload['period']>(
    props.filters.period ?? 'ce_mois',
);
const selectedVehiculeId = ref(props.filters.vehicule_id ?? 'all');
const selectedStatus = ref(props.filters.statut ?? 'all');
const dateDebut = ref(props.filters.date_debut ?? '');
const dateFin = ref(props.filters.date_fin ?? '');

const hasActiveFilters = computed(
    () =>
        selectedPeriod.value !== 'ce_mois' ||
        selectedVehiculeId.value !== 'all' ||
        selectedStatus.value !== 'all',
);

const periodLabel = computed(() => {
    switch (selectedPeriod.value) {
        case '7j':
            return '7 derniers jours';
        case 'ce_mois':
            return 'Ce mois';
        case 'mois_passe':
            return 'Mois passe';
        case 'custom':
            if (dateDebut.value && dateFin.value) {
                return `Du ${formatDateFr(dateDebut.value)} au ${formatDateFr(dateFin.value)}`;
            }
            if (dateDebut.value) {
                return `Depuis ${formatDateFr(dateDebut.value)}`;
            }
            if (dateFin.value) {
                return `Jusqu'au ${formatDateFr(dateFin.value)}`;
            }

            return 'Periode personnalisee';
        default:
            return '30 derniers jours';
    }
});

function formatDateFr(value: string): string {
    return new Intl.DateTimeFormat('fr-FR', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(new Date(value));
}

function formatMoney(value: number): string {
    return `${new Intl.NumberFormat('fr-FR').format(value ?? 0)} GNF`;
}

function applyFilters() {
    router.get(
        '/client/dashboard',
        {
            period: selectedPeriod.value,
            date_debut:
                selectedPeriod.value === 'custom'
                    ? dateDebut.value || undefined
                    : undefined,
            date_fin:
                selectedPeriod.value === 'custom'
                    ? dateFin.value || undefined
                    : undefined,
            vehicule_id:
                selectedVehiculeId.value === 'all'
                    ? undefined
                    : selectedVehiculeId.value,
            statut:
                selectedStatus.value === 'all'
                    ? undefined
                    : selectedStatus.value,
        },
        { preserveScroll: true, preserveState: true, replace: true },
    );
}

function onPeriodChange(period: DashboardFiltersPayload['period']) {
    selectedPeriod.value = period;
    if (period !== 'custom') {
        dateDebut.value = '';
        dateFin.value = '';
    }
    applyFilters();
}

function onCustomDateChange() {
    if (selectedPeriod.value !== 'custom') {
        selectedPeriod.value = 'custom';
    }
    applyFilters();
}

function resetFilters() {
    selectedPeriod.value = 'ce_mois';
    selectedVehiculeId.value = 'all';
    selectedStatus.value = 'all';
    dateDebut.value = '';
    dateFin.value = '';
    router.get(
        '/client/dashboard',
        {},
        { preserveScroll: true, replace: true },
    );
}
</script>

<template>
    <ClientLayout>
        <Head title="Mon espace - Accueil" />

        <div class="space-y-8">
            <div class="flex items-center gap-4">
                <div
                    class="flex h-16 w-16 items-center justify-center rounded-full bg-primary text-2xl font-semibold text-primary-foreground"
                >
                    {{ userInitials }}
                </div>
                <div>
                    <h1 class="text-2xl font-semibold">
                        {{ userFullName }}
                    </h1>
                    <p class="mt-1 text-muted-foreground">
                        Bienvenue dans votre espace partenaire.
                    </p>
                </div>
            </div>

            <div class="rounded-xl border border-border bg-card p-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm font-medium text-foreground">Filtres</p>
                    <button
                        v-if="hasActiveFilters"
                        type="button"
                        class="rounded-md border border-border px-3 py-1.5 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted"
                        @click="resetFilters"
                    >
                        Reinitialiser
                    </button>
                </div>

                <div class="mt-3 grid gap-3 md:grid-cols-2 lg:grid-cols-5">
                    <div class="space-y-1">
                        <label class="text-xs font-medium text-muted-foreground"
                            >Periode</label
                        >
                        <select
                            v-model="selectedPeriod"
                            class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                            @change="
                                onPeriodChange(
                                    selectedPeriod as DashboardFiltersPayload['period'],
                                )
                            "
                        >
                            <option
                                v-for="option in periodOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-medium text-muted-foreground"
                            >Vehicule</label
                        >
                        <select
                            v-model="selectedVehiculeId"
                            class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                            @change="applyFilters"
                        >
                            <option value="all">Tous les vehicules</option>
                            <option
                                v-for="vehicule in vehicules"
                                :key="vehicule.id"
                                :value="String(vehicule.id)"
                            >
                                {{ vehicule.nom_vehicule }} ({{
                                    vehicule.immatriculation ?? '-'
                                }})
                            </option>
                        </select>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-medium text-muted-foreground"
                            >Statut paiement</label
                        >
                        <select
                            v-model="selectedStatus"
                            class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                            @change="applyFilters"
                        >
                            <option value="all">Tous les statuts</option>
                            <option
                                v-for="status in status_options"
                                :key="status.value"
                                :value="status.value"
                            >
                                {{ status.label }}
                            </option>
                        </select>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-medium text-muted-foreground"
                            >Du</label
                        >
                        <input
                            v-model="dateDebut"
                            type="date"
                            class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                            @change="onCustomDateChange"
                        />
                    </div>
                    <div class="space-y-1">
                        <label class="text-xs font-medium text-muted-foreground"
                            >Au</label
                        >
                        <input
                            v-model="dateFin"
                            type="date"
                            class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                            @change="onCustomDateChange"
                        />
                    </div>
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-3">
                <div class="rounded-xl border border-border bg-card p-5">
                    <p class="text-sm text-muted-foreground">Gains cumules</p>
                    <p class="mt-2 text-2xl font-semibold text-foreground">
                        {{ formatMoney(earnings.total_earned) }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ periodLabel }}
                    </p>
                </div>
                <div class="rounded-xl border border-border bg-card p-5">
                    <p class="text-sm text-muted-foreground">Deja verses</p>
                    <p class="mt-2 text-2xl font-semibold text-foreground">
                        {{ formatMoney(earnings.total_paid) }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ periodLabel }}
                    </p>
                </div>
                <div class="rounded-xl border border-border bg-card p-5">
                    <p class="text-sm text-muted-foreground">Reste a payer</p>
                    <p class="mt-2 text-2xl font-semibold text-foreground">
                        {{ formatMoney(earnings.balance) }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ earnings.operations_count }} operation(s) -
                        {{ periodLabel }}
                    </p>
                    <p
                        v-if="earnings.frais_depenses_total > 0"
                        class="mt-1 text-xs text-destructive"
                    >
                        dont {{ formatMoney(earnings.frais_depenses_total) }} de
                        frais deduits
                    </p>
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

            <div
                v-if="earnings.operations_count === 0"
                class="rounded-xl border border-dashed border-border bg-card p-5"
            >
                <p class="text-sm font-medium text-foreground">
                    Aucune operation sur la periode choisie.
                </p>
                <p class="mt-1 text-sm text-muted-foreground">
                    Ajustez les filtres ou consultez le releve detaille.
                </p>
                <Link
                    href="/client/gains"
                    class="mt-3 inline-flex rounded-md border border-border px-3 py-1.5 text-sm font-medium text-foreground transition-colors hover:bg-muted"
                >
                    Voir tous les gains
                </Link>
            </div>
        </div>
    </ClientLayout>
</template>
