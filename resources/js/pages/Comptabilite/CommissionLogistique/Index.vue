<script setup lang="ts">
import AuditDrawer from '@/components/AuditDrawer.vue';
import ClickableTableRow from '@/components/ClickableTableRow.vue';
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import PaymentDialogCompact from '@/components/PaymentDialogCompact.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Download,
    ExternalLink,
    FileText,
    HandCoins,
    History,
    MoreHorizontal,
    Truck,
    User,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import { computed, ref } from 'vue';

interface VehiculeInfo {
    nom: string;
    immatriculation: string | null;
    type: string | null;
    capacite_packs: number | null;
    proprietaire_nom: string | null;
    proprietaire_telephone: string | null;
    proprietaire_code_phone_pays: string | null;
}

interface LivreurRow {
    livreur_id: string;
    nom: string;
    telephone: string | null;
    vehicules: VehiculeInfo[];
    agence: string | null;
    frais_depenses: number;
    impaye: number;
    paye: number;
}

interface Kpis {
    nb_livreurs: number;
    total_impaye: number;
    total_paye: number;
}

interface PeriodeOption {
    code: string;
    label: string;
}

const props = defineProps<{
    livreurs: LivreurRow[];
    kpis: Kpis;
    search: string;
    filtre_statut: string;
    filtre_site_ids: string[];
    selected_periode: string;
    periodes_disponibles: PeriodeOption[];
    sites: { id: string; nom: string }[];
    can_payer: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Comptabilité', href: '/comptabilite' },
    {
        title: 'Commissions logistique',
        href: '/comptabilite/commissions/logistique',
    },
];

const search = ref(props.search ?? '');

const filterFields = computed((): FilterField[] => [
    {
        key: 'statut',
        label: 'Statut',
        type: 'select' as const,
        options: [
            { value: 'impaye', label: 'Impayé' },
            { value: 'paye', label: 'Payé' },
        ],
    },
    {
        key: 'periode',
        label: 'Période',
        type: 'select' as const,
        options: props.periodes_disponibles.map((p) => ({
            value: p.code,
            label: p.label,
        })),
    },
]);

const currentFilters = computed(() => ({
    site_ids: props.filtre_site_ids ?? [],
    statut: props.filtre_statut ?? '',
    periode: props.selected_periode ?? '',
}));

const kpiTotalBrut = computed(() =>
    props.livreurs.reduce(
        (s, l) => s + l.impaye + l.paye + l.frais_depenses,
        0,
    ),
);
const kpiTotalFrais = computed(() =>
    props.livreurs.reduce((s, l) => s + l.frais_depenses, 0),
);
const kpiTotalNet = computed(() =>
    props.livreurs.reduce((s, l) => s + l.impaye + l.paye, 0),
);
const kpiTotalPaye = computed(() =>
    props.livreurs.reduce((s, l) => s + l.paye, 0),
);
const kpiTotalReste = computed(() =>
    props.livreurs.reduce((s, l) => s + l.impaye, 0),
);

const showPaiementDialog = ref(false);
const selectedLivreur = ref<LivreurRow | null>(null);
const paiementProcessing = ref(false);
const paiementErrors = ref<Record<string, string>>({});

const showAudit = ref(false);
const auditLivreurId = ref('');
const auditLivreurNom = ref('');

const vehiculeDialogVisible = ref(false);
const selectedVehicule = ref<VehiculeInfo | null>(null);

function openVehicule(v: VehiculeInfo) {
    selectedVehicule.value = v;
    vehiculeDialogVisible.value = true;
}

function openAudit(l: LivreurRow) {
    auditLivreurId.value = l.livreur_id;
    auditLivreurNom.value = l.nom;
    showAudit.value = true;
}

function openPaiement(livreur: LivreurRow) {
    selectedLivreur.value = livreur;
    showPaiementDialog.value = true;
}

function handlePaiementSubmit(payload: {
    montant: number;
    mode_paiement: string;
}) {
    if (!selectedLivreur.value) return;
    paiementProcessing.value = true;
    paiementErrors.value = {};
    router.post(
        `/comptabilite/commissions/logistique/livreurs/${selectedLivreur.value.livreur_id}/paiements`,
        payload,
        {
            preserveScroll: true,
            onSuccess: () => {
                showPaiementDialog.value = false;
            },
            onError: (e) => {
                paiementErrors.value = e as Record<string, string>;
            },
            onFinish: () => {
                paiementProcessing.value = false;
            },
        },
    );
}

function livreurStatuts(l: LivreurRow) {
    const badges = [];
    if (l.impaye > 0) badges.push({ label: 'Impayé', dotClass: 'bg-red-500' });
    if (l.paye > 0) badges.push({ label: 'Payé', dotClass: 'bg-emerald-500' });
    return badges;
}

function buildParams(): URLSearchParams {
    const params = new URLSearchParams();
    if (props.selected_periode) params.set('periode', props.selected_periode);
    for (const id of props.filtre_site_ids ?? []) {
        params.append('site_ids[]', id);
    }
    if (props.filtre_statut) params.set('statut', props.filtre_statut);
    if (search.value) params.set('search', search.value);
    return params;
}

function exportExcel() {
    window.open(
        '/comptabilite/commissions/logistique/export/excel?' +
            buildParams().toString(),
        '_blank',
    );
}

function exportPdf() {
    window.open(
        '/comptabilite/commissions/logistique/export/pdf?' +
            buildParams().toString(),
        '_blank',
    );
}

function fmt(val: number | null | undefined) {
    return (
        new Intl.NumberFormat('fr-FR').format(
            Math.round(Math.abs(Number(val ?? 0))),
        ) + ' GNF'
    );
}

function fmtTel(tel: string | null | undefined): string {
    if (!tel) return '—';
    const digits = tel.replace(/\s/g, '');
    if (digits.startsWith('+')) {
        const cc = digits.slice(0, 4);
        const rest = digits.slice(4).replace(/(\d{3})(?=\d)/g, '$1 ');
        return cc + ' ' + rest;
    }
    return digits.replace(/(\d{3})(?=\d)/g, '$1 ');
}
</script>

<template>
    <Head title="Commissions logistique — Comptabilité" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Commission livreur logistique
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ kpis.nb_livreurs }} livreur{{
                            kpis.nb_livreurs !== 1 ? 's' : ''
                        }}
                        avec commissions
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg border bg-card px-3 py-2 text-sm hover:bg-muted/50"
                        @click="exportExcel"
                    >
                        <Download class="h-4 w-4" />
                        Excel
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg border bg-card px-3 py-2 text-sm hover:bg-muted/50"
                        @click="exportPdf"
                    >
                        <FileText class="h-4 w-4" />
                        PDF
                    </button>
                </div>
            </div>

            <!-- KPIs -->
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Total cumulé</p>
                    <p
                        class="mt-2 text-2xl font-bold text-foreground tabular-nums"
                    >
                        {{ fmt(kpiTotalBrut) }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ livreurs.length }} livreur{{
                            livreurs.length !== 1 ? 's' : ''
                        }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Dépenses</p>
                    <p
                        class="mt-2 text-2xl font-bold text-red-600 tabular-nums dark:text-red-400"
                    >
                        {{
                            kpiTotalFrais > 0
                                ? '-' + fmt(kpiTotalFrais)
                                : fmt(0)
                        }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Net à payer</p>
                    <p
                        class="mt-2 text-2xl font-bold text-foreground tabular-nums"
                    >
                        {{ fmt(kpiTotalNet) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Déjà payé</p>
                    <p
                        class="mt-2 text-2xl font-bold text-foreground tabular-nums"
                    >
                        {{ fmt(kpiTotalPaye) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Reste à payer</p>
                    <p
                        class="mt-2 text-2xl font-bold text-foreground tabular-nums"
                    >
                        {{ fmt(kpiTotalReste) }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ livreurs.filter((l) => l.impaye > 0).length }}
                        impayé{{
                            livreurs.filter((l) => l.impaye > 0).length !== 1
                                ? 's'
                                : ''
                        }}
                    </p>
                </div>
            </div>

            <!-- Filtres -->
            <DataFilters
                url="/comptabilite/commissions/logistique"
                :values="currentFilters"
                :fields="filterFields"
                :sites="sites"
                :result-count="livreurs.length"
                search-key="search"
                search-placeholder="Rechercher un livreur, téléphone, véhicule..."
                v-model:search="search"
            />

            <!-- Tableau -->
            <div class="overflow-hidden rounded-xl border bg-card shadow-sm">
                <div v-if="livreurs.length > 0" class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b bg-muted/40">
                                <th
                                    class="px-5 py-3.5 text-left font-medium text-muted-foreground"
                                >
                                    Livreur
                                </th>
                                <th
                                    class="px-5 py-3.5 text-left font-medium text-muted-foreground"
                                >
                                    Véhicule(s)
                                </th>
                                <th
                                    class="px-5 py-3.5 text-left font-medium text-muted-foreground"
                                >
                                    Agence
                                </th>
                                <th
                                    class="px-5 py-3.5 text-right font-medium text-muted-foreground"
                                >
                                    Total cumulé
                                </th>
                                <th
                                    class="px-5 py-3.5 text-right font-medium text-muted-foreground"
                                >
                                    Dépenses
                                </th>
                                <th
                                    class="px-5 py-3.5 text-right font-medium text-muted-foreground"
                                >
                                    Net à payer
                                </th>
                                <th
                                    class="px-5 py-3.5 text-right font-medium text-muted-foreground"
                                >
                                    Déjà payé
                                </th>
                                <th
                                    class="px-5 py-3.5 text-right font-medium text-muted-foreground"
                                >
                                    Reste à payer
                                </th>
                                <th
                                    class="px-5 py-3.5 text-left font-medium text-muted-foreground"
                                >
                                    Statut
                                </th>
                                <th class="w-10 px-4 py-3.5" />
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <ClickableTableRow
                                v-for="l in livreurs"
                                :key="l.livreur_id"
                                :href="`/comptabilite/commissions/logistique/livreurs/${l.livreur_id}`"
                                :aria-label="`Voir le détail de ${l.nom}`"
                                class="even:bg-muted/20"
                            >
                                <td class="px-5 py-3">
                                    <div class="flex items-center gap-2.5">
                                        <User
                                            class="h-4 w-4 shrink-0 text-muted-foreground"
                                        />
                                        <div>
                                            <p class="font-semibold">
                                                {{ l.nom }}
                                            </p>
                                            <p
                                                v-if="l.telephone"
                                                class="mt-0.5 text-xs text-muted-foreground"
                                            >
                                                {{ fmtTel(l.telephone) }}
                                            </p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-3" @click.stop>
                                    <div
                                        v-if="l.vehicules.length"
                                        class="flex items-start gap-1.5 text-sm text-muted-foreground"
                                    >
                                        <Truck
                                            class="mt-0.5 h-3.5 w-3.5 shrink-0"
                                        />
                                        <div>
                                            <div
                                                v-for="(v, idx) in l.vehicules"
                                                :key="idx"
                                            >
                                                <button
                                                    type="button"
                                                    class="flex items-center gap-1 font-medium text-primary hover:underline focus:outline-none"
                                                    @click="openVehicule(v)"
                                                >
                                                    {{ v.nom }}
                                                    <ExternalLink
                                                        class="h-3 w-3 shrink-0"
                                                    />
                                                </button>
                                                <span
                                                    v-if="v.immatriculation"
                                                    class="block text-xs text-muted-foreground/80"
                                                    >{{
                                                        v.immatriculation
                                                    }}</span
                                                >
                                            </div>
                                        </div>
                                    </div>
                                    <span
                                        v-else
                                        class="text-xs text-muted-foreground"
                                        >—</span
                                    >
                                </td>
                                <td class="px-5 py-3 text-sm">
                                    <span v-if="l.agence">{{ l.agence }}</span>
                                    <span v-else class="text-muted-foreground"
                                        >—</span
                                    >
                                </td>
                                <td
                                    class="px-5 py-3 text-right text-muted-foreground tabular-nums"
                                >
                                    {{
                                        fmt(
                                            l.impaye +
                                                l.paye +
                                                l.frais_depenses,
                                        )
                                    }}
                                </td>
                                <td
                                    class="px-5 py-3 text-right text-red-600 tabular-nums dark:text-red-400"
                                >
                                    {{
                                        l.frais_depenses > 0
                                            ? '-' + fmt(l.frais_depenses)
                                            : '—'
                                    }}
                                </td>
                                <td
                                    class="px-5 py-3 text-right text-muted-foreground tabular-nums"
                                >
                                    {{ fmt(l.impaye + l.paye) }}
                                </td>
                                <td
                                    class="px-5 py-3 text-right text-muted-foreground tabular-nums"
                                >
                                    {{ fmt(l.paye) }}
                                </td>
                                <td
                                    class="px-5 py-3 text-right font-bold tabular-nums"
                                >
                                    {{ fmt(l.impaye) }}
                                </td>
                                <td class="px-5 py-3">
                                    <div class="flex flex-col gap-1">
                                        <StatusDot
                                            v-for="s in livreurStatuts(l)"
                                            :key="s.label"
                                            :label="s.label"
                                            :dot-class="s.dotClass"
                                            class="text-xs text-muted-foreground"
                                        />
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right" @click.stop>
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                class="h-7 w-7"
                                            >
                                                <MoreHorizontal
                                                    class="h-4 w-4"
                                                />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            <DropdownMenuItem as-child>
                                                <Link
                                                    :href="`/comptabilite/commissions/logistique/livreurs/${l.livreur_id}`"
                                                    class="flex w-full cursor-pointer items-center"
                                                >
                                                    Détail
                                                </Link>
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                class="cursor-pointer"
                                                @click="openAudit(l)"
                                            >
                                                <History class="mr-2 h-4 w-4" />
                                                Historique
                                            </DropdownMenuItem>
                                            <template
                                                v-if="can_payer && l.impaye > 0"
                                            >
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    class="cursor-pointer"
                                                    @click="openPaiement(l)"
                                                >
                                                    <HandCoins
                                                        class="mr-2 h-4 w-4"
                                                    />
                                                    Payer
                                                </DropdownMenuItem>
                                            </template>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </td>
                            </ClickableTableRow>
                        </tbody>
                    </table>
                </div>
                <div
                    v-else
                    class="flex flex-col items-center gap-3 py-16 text-muted-foreground"
                >
                    <HandCoins class="h-12 w-12 opacity-30" />
                    <p class="text-sm">
                        Aucune commission trouvée pour ce filtre.
                    </p>
                </div>
            </div>
        </div>
    </AppLayout>

    <PaymentDialogCompact
        v-model:visible="showPaiementDialog"
        :title="selectedLivreur ? `Payer — ${selectedLivreur.nom}` : 'Payer'"
        :solde="selectedLivreur?.impaye ?? 0"
        :processing="paiementProcessing"
        :errors="paiementErrors"
        @submit="handlePaiementSubmit"
    />

    <AuditDrawer
        v-model:visible="showAudit"
        :title="`Historique — ${auditLivreurNom}`"
        auditable-type="App\Models\Livreur"
        :auditable-id="auditLivreurId"
        module="commissions_logistique"
    />

    <Dialog
        v-model:visible="vehiculeDialogVisible"
        modal
        header="Détail véhicule"
        :style="{ width: '28rem' }"
    >
        <div class="space-y-3 px-1 py-2">
            <div class="flex justify-between">
                <span class="text-sm text-muted-foreground">Nom</span>
                <span class="text-sm font-medium">{{
                    selectedVehicule?.nom ?? '—'
                }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-sm text-muted-foreground"
                    >Immatriculation</span
                >
                <span class="text-sm font-medium">{{
                    selectedVehicule?.immatriculation ?? '—'
                }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-sm text-muted-foreground">Type</span>
                <span class="text-sm font-medium">{{
                    selectedVehicule?.type ?? '—'
                }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-sm text-muted-foreground">Capacité</span>
                <span class="text-sm font-medium">
                    {{
                        selectedVehicule?.capacite_packs != null
                            ? selectedVehicule.capacite_packs + ' packs'
                            : '—'
                    }}
                </span>
            </div>
            <div class="border-t pt-3">
                <p
                    class="mb-2 text-xs font-medium tracking-wider text-muted-foreground uppercase"
                >
                    Propriétaire
                </p>
                <div class="flex justify-between">
                    <span class="text-sm text-muted-foreground">Nom</span>
                    <span class="text-sm font-medium">{{
                        selectedVehicule?.proprietaire_nom ?? '—'
                    }}</span>
                </div>
                <div
                    v-if="selectedVehicule?.proprietaire_telephone"
                    class="mt-2 flex justify-between"
                >
                    <span class="text-sm text-muted-foreground">Téléphone</span>
                    <span class="text-sm font-medium">
                        {{ fmtTel(selectedVehicule.proprietaire_telephone) }}
                    </span>
                </div>
            </div>
        </div>
        <template #footer>
            <Button
                variant="outline"
                size="sm"
                @click="vehiculeDialogVisible = false"
                >Fermer</Button
            >
        </template>
    </Dialog>
</template>
