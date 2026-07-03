<script setup lang="ts">
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useClickableTableRow } from '@/composables/useClickableTableRow';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import ReceptionDialog from '@/pages/Logistique/partials/ReceptionDialog.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ChevronRight,
    MoreHorizontal,
    PackageSearch,
    Pencil,
    Plus,
    Search,
    Truck,
    XCircle,
} from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import { useToast } from 'primevue/usetoast';
import { computed, ref } from 'vue';

// ── Types ─────────────────────────────────────────────────────────────────────

interface LigneReception {
    id: number;
    produit_nom: string;
    quantite_chargee: number | null;
    quantite_recue: number | null;
    ecart_type: string | null;
    ecart_motif: string | null;
}

interface Transfert {
    id: number;
    reference: string;
    site_source_nom: string | null;
    site_destination_nom: string | null;
    vehicule_nom: string | null;
    immatriculation: string | null;
    equipe_nom: string | null;
    statut: string;
    statut_label: string;
    statut_dot_class: string;
    date_depart_prevue: string | null;
    date_arrivee_prevue: string | null;
    date_depart_reelle: string | null;
    date_arrivee_reelle: string | null;
    commission_statut: string | null;
    commission_statut_label: string | null;
    is_brouillon: boolean;
    is_cloture: boolean;
    is_terminal: boolean;
    is_annule: boolean;
    is_editable: boolean;
    can_annuler: boolean;
    can_valider_reception: boolean;
    created_at: string;
    lignes_reception: LigneReception[];
}

// Kpis : champs optionnels — Transferts et Réceptions n'ont pas les mêmes compteurs
interface Kpis {
    brouillons?: number;
    en_chargement?: number;
    en_transit?: number;
    en_attente?: number;
    clotures_mois?: number;
}

interface StatutOption {
    value: string;
    label: string;
}

interface SiteOption {
    id: number;
    nom: string;
}

// ── Props ─────────────────────────────────────────────────────────────────────

const props = defineProps<{
    transferts: Transfert[];
    kpis: Kpis;
    statuts: StatutOption[];
    sites: SiteOption[];
    filtre_statut: string | null;
    filtre_depart_site_ids: string[];
    filtre_arrivee_site_ids: string[];
    vue: 'transferts' | 'receptions';
    can_create: boolean;
    types_ecart: StatutOption[];
    is_admin: boolean;
    user_site_ids: string[];
}>();

const { can } = usePermissions();
const toast = useToast();

const { onRowClick, bodyRowPt } = useClickableTableRow<Transfert>(
    (transfert) => `/backoffice/logistique/${transfert.id}`,
);

// ── Breadcrumbs ───────────────────────────────────────────────────────────────

const breadcrumbs = computed((): BreadcrumbItem[] => [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Logistique', href: '/backoffice/logistique/transferts' },
    {
        title: props.vue === 'receptions' ? 'Réceptions' : 'Transferts',
        href:
            props.vue === 'receptions'
                ? '/backoffice/logistique/receptions'
                : '/backoffice/logistique/transferts',
    },
]);

// ── Filtres desktop ───────────────────────────────────────────────────────────

function indexUrl(): string {
    return props.vue === 'receptions'
        ? '/backoffice/logistique/receptions'
        : '/backoffice/logistique/transferts';
}

const siteOptions = computed(() =>
    props.sites.map((s) => ({ value: s.id, label: s.nom })),
);

const mobileSearch = ref('');

const filterFields = computed<FilterField[]>(() => [
    {
        key: 'statut',
        label: 'Statut',
        type: 'select',
        options: props.statuts.map((s) => ({ value: s.value, label: s.label })),
        placeholder: 'Tous les statuts',
    },
    {
        key: 'depart_site_ids',
        label: 'Site de départ',
        type: 'multi-select',
        inline: true,
        options: siteOptions.value,
        placeholder: 'Site départ',
        disabled: !props.is_admin && props.vue === 'transferts',
    },
    {
        key: 'arrivee_site_ids',
        label: "Site d'arrivée",
        type: 'multi-select',
        inline: true,
        options: siteOptions.value,
        placeholder: 'Site arrivée',
        disabled: !props.is_admin && props.vue === 'receptions',
    },
]);

const filterValues = computed(() => ({
    statut: props.filtre_statut ?? '',
    depart_site_ids:
        !props.is_admin && props.vue === 'transferts'
            ? props.user_site_ids
            : (props.filtre_depart_site_ids ?? []),
    arrivee_site_ids:
        !props.is_admin && props.vue === 'receptions'
            ? props.user_site_ids
            : (props.filtre_arrivee_site_ids ?? []),
}));

const filteredTransferts = computed(() => props.transferts);

// ── Filtre mobile ─────────────────────────────────────────────────────────────

const mobileFiltered = computed(() => {
    const q = mobileSearch.value.toLowerCase().trim();
    if (!q) return props.transferts;
    return props.transferts.filter(
        (t) =>
            t.reference.toLowerCase().includes(q) ||
            (t.site_source_nom &&
                t.site_source_nom.toLowerCase().includes(q)) ||
            (t.site_destination_nom &&
                t.site_destination_nom.toLowerCase().includes(q)),
    );
});

// ── Étiquette prochaine étape ─────────────────────────────────────────────────

const ETAPE_SUIVANTE: Record<string, string> = {
    brouillon: 'Démarrer le chargement',
    chargement: 'Valider le chargement',
    transit: 'Valider la réception',
};

function labelSuivant(statut: string): string {
    return ETAPE_SUIVANTE[statut] ?? '';
}

// ── Avancer direct (brouillon → chargement, sans dialog) ─────────────────────

const avancementEnCours = ref<number | null>(null);

function avancerDirect(t: Transfert) {
    if (avancementEnCours.value) return;
    avancementEnCours.value = t.id;
    router.post(
        `/backoffice/logistique/${t.id}/statut/avancer`,
        {},
        {
            preserveScroll: true,
            onSuccess: () =>
                toast.add({
                    severity: 'success',
                    summary: 'Statut mis à jour',
                    life: 3000,
                }),
            onError: () =>
                toast.add({
                    severity: 'error',
                    summary: 'Erreur',
                    detail: 'Impossible de mettre à jour le statut.',
                    life: 4000,
                }),
            onFinish: () => {
                avancementEnCours.value = null;
            },
        },
    );
}

// ── Dialog réception (depuis la liste) ───────────────────────────────────────

const receptionDialogVisible = ref(false);
const receptionDialogTransfert = ref<Transfert | null>(null);

function ouvrirReceptionDialog(t: Transfert) {
    receptionDialogTransfert.value = t;
    receptionDialogVisible.value = true;
}

// ── Commission statut dot class ───────────────────────────────────────────────

const commStatutDot: Record<string, string> = {
    en_attente: 'bg-red-500',
    partiellement_versee: 'bg-amber-500',
    versee: 'bg-emerald-500',
    annulee: 'bg-zinc-400 dark:bg-zinc-500',
};
</script>

<template>
    <Head
        :title="
            vue === 'receptions'
                ? 'Réceptions — Logistique'
                : 'Transferts — Logistique'
        "
    />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- ── MOBILE VIEW ─────────────────────────────────────────────────── -->
        <div class="flex flex-col sm:hidden">
            <!-- Sticky header -->
            <div
                class="sticky top-0 z-10 flex items-center justify-between border-b bg-background px-4 py-3"
            >
                <Link
                    href="/backoffice/dashboard"
                    class="flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft class="h-5 w-5" />
                </Link>
                <span class="text-base font-semibold">
                    {{ vue === 'receptions' ? 'Réceptions' : 'Transferts' }}
                </span>
                <Link
                    v-if="can_create && vue === 'transferts'"
                    href="/backoffice/logistique/creer"
                >
                    <Button size="sm" class="h-8 px-3 text-xs">
                        <Plus class="mr-1 h-3.5 w-3.5" />
                        Nouveau
                    </Button>
                </Link>
                <div v-else class="w-8" />
            </div>

            <!-- Search -->
            <div class="border-b px-4 py-2">
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <input
                        v-model="mobileSearch"
                        type="text"
                        placeholder="Référence, site…"
                        class="h-9 w-full rounded-md border border-input bg-background pr-3 pl-8 text-sm placeholder:text-muted-foreground focus:ring-1 focus:ring-ring focus:outline-none"
                    />
                </div>
            </div>

            <!-- Card list -->
            <div class="divide-y">
                <Link
                    v-for="t in mobileFiltered"
                    :key="t.id"
                    :href="`/backoffice/logistique/${t.id}`"
                    class="flex items-start justify-between gap-3 px-4 py-3 hover:bg-muted/10 active:bg-muted/20"
                >
                    <div class="min-w-0 flex-1">
                        <p
                            class="font-mono text-sm font-semibold tracking-wide text-primary"
                        >
                            {{ t.reference }}
                        </p>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            {{ t.site_source_nom ?? '—' }} →
                            {{ t.site_destination_nom ?? '—' }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ t.vehicule_nom ?? '—' }}
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <div class="flex flex-col items-end gap-1.5">
                            <StatusDot
                                :label="t.statut_label"
                                :dot-class="t.statut_dot_class"
                                class="text-xs text-muted-foreground"
                            />
                            <span
                                class="text-xs text-muted-foreground tabular-nums"
                                >{{ t.created_at }}</span
                            >
                        </div>
                        <ChevronRight
                            class="h-4 w-4 shrink-0 text-muted-foreground/50"
                        />
                    </div>
                </Link>
            </div>

            <!-- Empty state -->
            <div
                v-if="mobileFiltered.length === 0"
                class="flex flex-col items-center gap-3 py-16 text-muted-foreground"
            >
                <Truck class="h-10 w-10 opacity-30" />
                <p class="text-sm">
                    {{
                        vue === 'receptions'
                            ? 'Aucune réception trouvée.'
                            : 'Aucun transfert trouvé.'
                    }}
                </p>
                <Link
                    v-if="can_create && vue === 'transferts'"
                    href="/backoffice/logistique/creer"
                >
                    <Button variant="outline" size="sm">
                        <Plus class="mr-2 h-4 w-4" />
                        Créer le premier transfert
                    </Button>
                </Link>
            </div>
        </div>

        <!-- ── DESKTOP VIEW ────────────────────────────────────────────────── -->
        <div class="hidden flex-col gap-6 p-6 sm:flex">
            <!-- En-tête -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        {{ vue === 'receptions' ? 'Réceptions' : 'Transferts' }}
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ transferts.length }}
                        {{ vue === 'receptions' ? 'réception' : 'transfert'
                        }}{{ transferts.length !== 1 ? 's' : '' }}
                    </p>
                </div>
                <Link
                    v-if="can_create && vue === 'transferts'"
                    href="/backoffice/logistique/creer"
                >
                    <Button>
                        <Plus class="mr-2 h-4 w-4" />
                        Nouveau transfert
                    </Button>
                </Link>
            </div>

            <!-- KPI cards — Transferts -->
            <div
                v-if="vue === 'transferts'"
                class="grid grid-cols-2 gap-4 lg:grid-cols-3"
            >
                <div
                    class="rounded-xl border bg-card p-4 shadow-sm"
                    :class="
                        (kpis.brouillons ?? 0) > 0
                            ? 'border-zinc-300 dark:border-zinc-700'
                            : ''
                    "
                >
                    <p
                        class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        Brouillons
                    </p>
                    <p
                        class="mt-1 text-2xl font-bold text-zinc-600 tabular-nums dark:text-zinc-400"
                    >
                        {{ kpis.brouillons ?? 0 }}
                    </p>
                </div>
                <div
                    class="rounded-xl border bg-card p-4 shadow-sm"
                    :class="
                        (kpis.en_chargement ?? 0) > 0
                            ? 'border-amber-200 dark:border-amber-900'
                            : ''
                    "
                >
                    <p
                        class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        En chargement
                    </p>
                    <p
                        class="mt-1 text-2xl font-bold text-amber-600 tabular-nums dark:text-amber-400"
                    >
                        {{ kpis.en_chargement ?? 0 }}
                    </p>
                </div>
                <div
                    class="rounded-xl border bg-card p-4 shadow-sm"
                    :class="
                        (kpis.en_transit ?? 0) > 0
                            ? 'border-blue-200 dark:border-blue-900'
                            : ''
                    "
                >
                    <p
                        class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        Livraison en cours
                    </p>
                    <p
                        class="mt-1 text-2xl font-bold text-blue-600 tabular-nums dark:text-blue-400"
                    >
                        {{ kpis.en_transit ?? 0 }}
                    </p>
                </div>
            </div>

            <!-- KPI cards — Réceptions -->
            <div v-else class="grid grid-cols-2 gap-4">
                <div
                    class="rounded-xl border bg-card p-4 shadow-sm"
                    :class="
                        (kpis.en_attente ?? 0) > 0
                            ? 'border-teal-200 dark:border-teal-900'
                            : ''
                    "
                >
                    <p
                        class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        En cours / à réceptionner
                    </p>
                    <p
                        class="mt-1 text-2xl font-bold text-teal-600 tabular-nums dark:text-teal-400"
                    >
                        {{ kpis.en_attente ?? 0 }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p
                        class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        Clôturés ce mois
                    </p>
                    <p
                        class="mt-1 text-2xl font-bold text-emerald-600 tabular-nums dark:text-emerald-400"
                    >
                        {{ kpis.clotures_mois ?? 0 }}
                    </p>
                </div>
            </div>

            <!-- Filtres -->
            <DataFilters
                :url="indexUrl()"
                :values="filterValues"
                :fields="filterFields"
                :result-count="filteredTransferts.length"
                :hide-agence-selector="true"
            />

            <!-- Tableau -->
            <div class="overflow-hidden rounded-xl border bg-card">
                <DataTable
                    :value="filteredTransferts"
                    :paginator="filteredTransferts.length > 20"
                    :rows="20"
                    data-key="id"
                    striped-rows
                    removable-sort
                    class="text-sm"
                    table-class="w-full"
                    :pt="{ bodyRow: bodyRowPt }"
                    @row-click="onRowClick"
                >
                    <!-- Référence -->
                    <Column
                        field="reference"
                        header="Référence"
                        sortable
                        style="min-width: 180px"
                    >
                        <template #body="{ data }">
                            <Link
                                :href="`/backoffice/logistique/${data.id}`"
                                class="font-mono text-sm font-semibold tracking-wide hover:underline"
                            >
                                {{ data.reference }}
                            </Link>
                        </template>
                    </Column>

                    <!-- Site départ -->
                    <Column header="Site départ" style="min-width: 140px">
                        <template #body="{ data }">
                            <span class="text-sm font-medium">{{
                                data.site_source_nom ?? '—'
                            }}</span>
                        </template>
                    </Column>

                    <!-- Site arrivée -->
                    <Column header="Site arrivée" style="min-width: 140px">
                        <template #body="{ data }">
                            <span class="text-sm font-medium">{{
                                data.site_destination_nom ?? '—'
                            }}</span>
                        </template>
                    </Column>

                    <!-- Véhicule -->
                    <Column
                        field="vehicule_nom"
                        header="Véhicule"
                        style="min-width: 140px"
                    >
                        <template #body="{ data }">
                            <span class="text-muted-foreground">
                                {{ data.vehicule_nom ?? '—' }}
                                <span
                                    v-if="data.immatriculation"
                                    class="ml-1 font-mono text-xs"
                                    >({{ data.immatriculation }})</span
                                >
                            </span>
                        </template>
                    </Column>

                    <!-- Date départ -->
                    <Column
                        field="date_depart_prevue"
                        header="Départ prévu"
                        sortable
                        style="width: 130px"
                    >
                        <template #body="{ data }">
                            <span class="text-muted-foreground tabular-nums">
                                {{ data.date_depart_prevue ?? '—' }}
                            </span>
                        </template>
                    </Column>

                    <!-- Statut transfert -->
                    <Column
                        field="statut"
                        header="Statut"
                        sortable
                        style="width: 140px"
                    >
                        <template #body="{ data }">
                            <StatusDot
                                :label="data.statut_label"
                                :dot-class="data.statut_dot_class"
                                class="text-muted-foreground"
                            />
                        </template>
                    </Column>

                    <!-- Commission -->
                    <Column header="Commission" style="width: 160px">
                        <template #body="{ data }">
                            <StatusDot
                                v-if="data.commission_statut"
                                :label="data.commission_statut_label ?? '—'"
                                :dot-class="
                                    commStatutDot[data.commission_statut] ??
                                    'bg-zinc-400'
                                "
                                class="text-muted-foreground"
                            />
                            <span v-else class="text-xs text-muted-foreground"
                                >—</span
                            >
                        </template>
                    </Column>

                    <!-- Actions -->
                    <Column header="" style="width: 56px">
                        <template #body="{ data }">
                            <div class="flex justify-end">
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="h-8 w-8"
                                        >
                                            <MoreHorizontal class="h-4 w-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent
                                        align="end"
                                        class="w-44"
                                    >
                                        <DropdownMenuItem as-child>
                                            <Link
                                                :href="`/backoffice/logistique/${data.id}`"
                                                class="flex w-full cursor-pointer items-center gap-2"
                                            >
                                                <PackageSearch
                                                    class="h-4 w-4"
                                                />
                                                Voir
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            v-if="
                                                data.is_editable &&
                                                can('logistique.update')
                                            "
                                            as-child
                                        >
                                            <Link
                                                :href="`/backoffice/logistique/${data.id}/editer`"
                                                class="flex w-full cursor-pointer items-center gap-2"
                                            >
                                                <Pencil class="h-4 w-4" />
                                                Modifier
                                            </Link>
                                        </DropdownMenuItem>
                                        <!-- Brouillon → Chargement : action directe sans redirection -->
                                        <DropdownMenuItem
                                            v-if="
                                                data.is_brouillon &&
                                                can('logistique.update')
                                            "
                                            class="cursor-pointer text-blue-600 focus:text-blue-600"
                                            :disabled="
                                                avancementEnCours === data.id
                                            "
                                            @click="avancerDirect(data)"
                                        >
                                            <Truck class="h-4 w-4" />
                                            {{
                                                avancementEnCours === data.id
                                                    ? 'En cours…'
                                                    : labelSuivant(data.statut)
                                            }}
                                        </DropdownMenuItem>
                                        <!-- CHARGEMENT → TRANSIT : validation chargement (page de détail) -->
                                        <DropdownMenuItem
                                            v-if="
                                                data.statut === 'chargement' &&
                                                can('logistique.update')
                                            "
                                            class="cursor-pointer text-blue-600 focus:text-blue-600"
                                            as-child
                                        >
                                            <Link
                                                :href="`/backoffice/logistique/${data.id}`"
                                                class="flex w-full cursor-pointer items-center gap-2"
                                            >
                                                <Truck class="h-4 w-4" />
                                                {{ labelSuivant(data.statut) }}
                                            </Link>
                                        </DropdownMenuItem>
                                        <!-- TRANSIT → RECEPTION : uniquement si utilisateur du site destination -->
                                        <DropdownMenuItem
                                            v-if="
                                                data.statut === 'transit' &&
                                                data.can_valider_reception
                                            "
                                            class="cursor-pointer text-blue-600 focus:text-blue-600"
                                            @click="ouvrirReceptionDialog(data)"
                                        >
                                            <Truck class="h-4 w-4" />
                                            {{ labelSuivant(data.statut) }}
                                        </DropdownMenuItem>
                                        <!-- Annuler : uniquement BROUILLON ou CHARGEMENT, site source seulement -->
                                        <DropdownMenuItem
                                            v-if="data.can_annuler"
                                            class="cursor-pointer text-amber-600 focus:text-amber-600"
                                            as-child
                                        >
                                            <Link
                                                method="post"
                                                :href="`/backoffice/logistique/${data.id}/statut/annuler`"
                                                class="flex w-full cursor-pointer items-center gap-2"
                                            >
                                                <XCircle class="h-4 w-4" />
                                                Annuler
                                            </Link>
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </template>
                    </Column>

                    <!-- État vide -->
                    <template #empty>
                        <div
                            class="flex flex-col items-center gap-3 py-16 text-muted-foreground"
                        >
                            <Truck class="h-12 w-12 opacity-30" />
                            <p class="text-sm">
                                {{
                                    vue === 'receptions'
                                        ? 'Aucune réception trouvée.'
                                        : 'Aucun transfert trouvé.'
                                }}
                            </p>
                            <Link
                                v-if="can_create && vue === 'transferts'"
                                href="/backoffice/logistique/creer"
                            >
                                <Button variant="outline" size="sm">
                                    <Plus class="mr-2 h-4 w-4" />
                                    Créer le premier transfert
                                </Button>
                            </Link>
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>

        <!-- Dialog réception partagé -->
        <ReceptionDialog
            v-if="receptionDialogTransfert"
            v-model:visible="receptionDialogVisible"
            :transfert-id="receptionDialogTransfert.id"
            :lignes="receptionDialogTransfert.lignes_reception"
            :types-ecart="types_ecart"
        />
    </AppLayout>
</template>
