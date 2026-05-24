<script setup lang="ts">
import MobileVehiculeBalancesList from '@/components/client/MobileVehiculeBalancesList.vue';
import KpiCardsResponsive from '@/components/dashboard/shared/KpiCardsResponsive.vue';
import ClientLayout from '@/layouts/ClientLayout.vue';
import type {
    DashboardFiltersPayload,
    EarningsPayload,
    EarningsVehiculePayload,
    VehiculeOption,
} from '@/types/client-space';
import type { KpiWidgetItem } from '@/types/kpi-widgets';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { SlidersHorizontal, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

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
const userPhone = computed(() => user.value?.telephone?.trim() || '-');

const ROLE_LABELS: Record<string, string> = {
    super_admin: 'Super administrateur',
    admin_entreprise: 'Administrateur entreprise',
    manager: 'Manager',
    commerciale: 'Commercial',
    comptable: 'Comptable',
    client: 'Client',
};

const userRoleLabel = computed(() => {
    const firstRole = (page.props.auth.roles as string[])?.[0];
    if (!firstRole) {
        return 'Partenaire';
    }

    return ROLE_LABELS[firstRole] ?? firstRole.replaceAll('_', ' ');
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
const isQrZoomOpen = ref(false);
const isMobileFiltersOpen = ref(false);

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

const kpiItems = computed<KpiWidgetItem[]>(() => [
    {
        id: 'gains-cumules',
        title: 'Gains cumules',
        value: formatMoney(props.earnings.total_earned),
        subtitle: periodLabel.value,
    },
    {
        id: 'deja-verses',
        title: 'Deja verses',
        value: formatMoney(props.earnings.total_paid),
        subtitle: periodLabel.value,
    },
    {
        id: 'reste-a-payer',
        title: 'Reste a payer',
        value: formatMoney(props.earnings.balance),
        subtitle: `${props.earnings.operations_count} operation(s) - ${periodLabel.value}`,
        note:
            props.earnings.frais_depenses_total > 0
                ? `dont ${formatMoney(props.earnings.frais_depenses_total)} de frais deduits`
                : undefined,
        noteClass:
            props.earnings.frais_depenses_total > 0
                ? 'text-destructive'
                : undefined,
    },
]);

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

function onPeriodDraftChange(period: DashboardFiltersPayload['period']) {
    selectedPeriod.value = period;
    if (period !== 'custom') {
        dateDebut.value = '';
        dateFin.value = '';
    }
}

function onCustomDateDraftChange() {
    if (selectedPeriod.value !== 'custom') {
        selectedPeriod.value = 'custom';
    }
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

function openQrZoom() {
    isQrZoomOpen.value = true;
}

function closeQrZoom() {
    isQrZoomOpen.value = false;
}

function openMobileFilters() {
    isMobileFiltersOpen.value = true;
}

function closeMobileFilters() {
    isMobileFiltersOpen.value = false;
}

function applyFiltersFromMobile() {
    applyFilters();
    closeMobileFilters();
}

function resetFiltersFromMobile() {
    resetFilters();
    closeMobileFilters();
}

function onKeydown(event: KeyboardEvent) {
    if (event.key !== 'Escape') {
        return;
    }

    if (isMobileFiltersOpen.value) {
        closeMobileFilters();
        return;
    }

    if (isQrZoomOpen.value) {
        closeQrZoom();
    }
}

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <ClientLayout>
        <Head title="Mon espace - Accueil" />

        <div class="space-y-8">
            <div class="flex items-center gap-4">
                <button
                    type="button"
                    class="group relative shrink-0 rounded-md focus-visible:ring-2 focus-visible:ring-primary/60 focus-visible:outline-none"
                    @click="openQrZoom"
                >
                    <img
                        src="/client/qr-code"
                        alt="QR code utilisateur"
                        class="h-24 w-24 object-contain transition-transform duration-200 group-hover:scale-105"
                    />
                    <span
                        class="pointer-events-none absolute -right-1 -bottom-1 rounded-full border border-border bg-background px-1.5 py-0.5 text-[10px] text-muted-foreground"
                    >
                        Scanner
                    </span>
                </button>
                <div>
                    <h1 class="text-2xl font-semibold">
                        {{ userFullName }}
                    </h1>
                    <p class="mt-1 text-muted-foreground">
                        {{ userPhone }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ userRoleLabel }}
                    </p>
                </div>
            </div>

            <div class="md:hidden">
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground transition-colors hover:bg-muted"
                    @click="openMobileFilters"
                >
                    <SlidersHorizontal class="h-4 w-4" />
                    Filtres
                    <span
                        v-if="hasActiveFilters"
                        class="rounded-full bg-primary px-2 py-0.5 text-[10px] font-semibold text-primary-foreground"
                    >
                        actifs
                    </span>
                </button>
            </div>

            <div
                class="hidden rounded-xl border border-border bg-card p-4 md:block"
            >
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

            <KpiCardsResponsive :items="kpiItems" breakpoint="md" />

            <div class="-mx-4 md:hidden">
                <h2 class="px-4 text-lg font-semibold">Solde par vehicule</h2>
                <div class="mt-2">
                    <MobileVehiculeBalancesList
                        :rows="earnings_by_vehicule"
                        :date-debut="dateDebut"
                        :date-fin="dateFin"
                    />
                </div>
            </div>

            <div
                class="hidden rounded-xl border border-border bg-card p-5 md:block"
            >
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

        <div
            v-if="isQrZoomOpen"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
            @click.self="closeQrZoom"
        >
            <div
                class="w-full max-w-md rounded-xl border border-border bg-background p-4 shadow-2xl"
            >
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-foreground">
                        QR code partenaire
                    </h3>
                    <button
                        type="button"
                        class="rounded-md border border-border px-2 py-1 text-xs text-muted-foreground transition-colors hover:bg-muted"
                        @click="closeQrZoom"
                    >
                        Fermer
                    </button>
                </div>
                <div class="flex justify-center">
                    <img
                        src="/client/qr-code"
                        alt="QR code utilisateur agrandi"
                        class="h-[min(80vh,36rem)] w-[min(80vh,36rem)] object-contain"
                    />
                </div>
                <p class="mt-3 text-center text-xs text-muted-foreground">
                    Présentez ce code au scanner. Cliquez en dehors ou appuyez sur Echap pour fermer.
                </p>
            </div>
        </div>

        <div
            v-if="isMobileFiltersOpen"
            class="fixed inset-0 z-50 bg-black/40 md:hidden"
            @click.self="closeMobileFilters"
        >
            <aside
                class="absolute top-0 right-0 flex h-full w-[88vw] max-w-sm flex-col border-l border-border bg-background shadow-2xl"
                @click.stop
            >
                <div
                    class="flex items-center justify-between border-b border-border px-4 py-3"
                >
                    <h3 class="text-sm font-semibold text-foreground">
                        Filtres
                    </h3>
                    <button
                        type="button"
                        class="rounded-md border border-border p-1 text-muted-foreground transition-colors hover:bg-muted"
                        @click="closeMobileFilters"
                    >
                        <X class="h-4 w-4" />
                        <span class="sr-only">Fermer</span>
                    </button>
                </div>

                <div class="flex-1 space-y-4 overflow-y-auto px-4 py-4">
                    <div class="space-y-1">
                        <label class="text-xs font-medium text-muted-foreground"
                            >Periode</label
                        >
                        <select
                            v-model="selectedPeriod"
                            class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                            @change="
                                onPeriodDraftChange(
                                    selectedPeriod as DashboardFiltersPayload['period'],
                                )
                            "
                        >
                            <option
                                v-for="option in periodOptions"
                                :key="`mobile-${option.value}`"
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
                        >
                            <option value="all">Tous les vehicules</option>
                            <option
                                v-for="vehicule in vehicules"
                                :key="`mobile-${vehicule.id}`"
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
                        >
                            <option value="all">Tous les statuts</option>
                            <option
                                v-for="status in status_options"
                                :key="`mobile-${status.value}`"
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
                            @change="onCustomDateDraftChange"
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
                            @change="onCustomDateDraftChange"
                        />
                    </div>
                </div>

                <div class="border-t border-border px-4 py-3">
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="w-full rounded-md border border-border px-3 py-2 text-sm font-medium text-foreground transition-colors hover:bg-muted"
                            @click="resetFiltersFromMobile"
                        >
                            Reinitialiser
                        </button>
                        <button
                            type="button"
                            class="w-full rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground transition-opacity hover:opacity-90"
                            @click="applyFiltersFromMobile"
                        >
                            Appliquer
                        </button>
                    </div>
                </div>
            </aside>
        </div>
    </ClientLayout>
</template>
