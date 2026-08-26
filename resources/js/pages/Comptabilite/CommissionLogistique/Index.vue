<script setup lang="ts">
import AuditDrawer from '@/components/AuditDrawer.vue';
import ClickableTableRow from '@/components/ClickableTableRow.vue';
import CommissionIndexLayout from '@/components/commission/CommissionIndexLayout.vue';
import PeriodeStatusBanner from '@/components/commission/PeriodeStatusBanner.vue';
import type { FilterField } from '@/components/filters/DataFilters.vue';
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
import type { CommissionIndexSummary } from '@/types/commission';
import type {
    PeriodeAffichee,
    StatutCommissionResolu,
} from '@/types/commission-status';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ExternalLink,
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
    capacites: { categorie_nom: string; capacite_max: number }[];
    proprietaire_nom: string | null;
    proprietaire_telephone: string | null;
    proprietaire_code_phone_pays: string | null;
}

interface LivreurRow extends StatutCommissionResolu {
    livreur_id: string;
    nom: string;
    telephone: string | null;
    vehicules: VehiculeInfo[];
    agence: string | null;
    frais_depenses: number;
    impaye: number;
    paye: number;
    remaining_amount: number;
    fiche_id: string | null;
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
    periode_affichee: PeriodeAffichee | null;
    sites: { id: string; nom: string }[];
    can_payer: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité' },
    {
        title: 'Commissions logistique',
        href: '/backoffice/comptabilite/commissions/logistique',
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

const periodContextLabel = computed(() => {
    if (!props.selected_periode) return 'Toutes les périodes';

    return (
        props.periodes_disponibles.find(
            (periode) => periode.code === props.selected_periode,
        )?.label ?? props.selected_periode
    );
});

const indexSummary = computed<CommissionIndexSummary>(() => ({
    generated: kpiTotalBrut.value,
    expenses: kpiTotalFrais.value,
    netValidated: kpiTotalNet.value,
    remaining: kpiTotalReste.value,
    paid: kpiTotalPaye.value,
}));

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
        `/backoffice/comptabilite/commissions/logistique/livreurs/${selectedLivreur.value.livreur_id}/paiements`,
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
        '/backoffice/comptabilite/commissions/logistique/export/excel?' +
            buildParams().toString(),
        '_blank',
    );
}

function exportPdf() {
    window.open(
        '/backoffice/comptabilite/commissions/logistique/export/pdf?' +
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
        <CommissionIndexLayout
            title="Commission livreur logistique"
            :entity-count="kpis.nb_livreurs"
            entity-label="livreur"
            :period-label="periodContextLabel"
            filter-url="/backoffice/comptabilite/commissions/logistique"
            :filter-values="currentFilters"
            :filter-fields="filterFields"
            :sites="sites"
            :summary="indexSummary"
            table-title="Détail par livreur"
            :result-count="livreurs.length"
            empty-message="Aucune commission trouvée pour ce filtre."
            @export-excel="exportExcel"
            @export-pdf="exportPdf"
        >
            <template #after-header>
                <PeriodeStatusBanner :periode="periode_affichee" />
            </template>

            <table class="w-full min-w-[1320px] text-sm">
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
                        :href="`/backoffice/comptabilite/commissions/logistique/livreurs/${l.livreur_id}`"
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
                                <Truck class="mt-0.5 h-3.5 w-3.5 shrink-0" />
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
                                            >{{ v.immatriculation }}</span
                                        >
                                    </div>
                                </div>
                            </div>
                            <span v-else class="text-xs text-muted-foreground"
                                >—</span
                            >
                        </td>
                        <td class="px-5 py-3 text-sm">
                            <span v-if="l.agence">{{ l.agence }}</span>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td
                            class="px-5 py-3 text-right text-muted-foreground tabular-nums"
                        >
                            {{ fmt(l.impaye + l.paye + l.frais_depenses) }}
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
                        <td class="px-5 py-3 text-right font-bold tabular-nums">
                            {{ fmt(l.impaye) }}
                        </td>
                        <td class="px-5 py-3">
                            <StatusDot
                                :status="l.display_status"
                                :label="l.display_label"
                                class="text-xs text-muted-foreground"
                            />
                        </td>
                        <td class="px-4 py-3 text-right" @click.stop>
                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="h-7 w-7"
                                    >
                                        <MoreHorizontal class="h-4 w-4" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem as-child>
                                        <Link
                                            :href="`/backoffice/comptabilite/commissions/logistique/livreurs/${l.livreur_id}`"
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
                                    <template v-if="can_payer && l.can_pay">
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                            class="cursor-pointer"
                                            @click="openPaiement(l)"
                                        >
                                            <HandCoins class="mr-2 h-4 w-4" />
                                            Payer
                                        </DropdownMenuItem>
                                    </template>
                                    <template v-else-if="l.fiche_id">
                                        <!-- Une fiche existe déjà pour ce livreur : le paiement
                                                direct est verrouillé côté backend
                                                (PeriodePayabilityChecker::assertPartsNotClaimedByFiche),
                                                seule la fiche permet encore de payer/consulter le solde. -->
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem as-child>
                                            <Link
                                                :href="`/backoffice/comptabilite/fiches/${l.fiche_id}`"
                                                class="flex w-full cursor-pointer items-center"
                                            >
                                                <HandCoins
                                                    class="mr-2 h-4 w-4"
                                                />
                                                Voir la fiche de paiement
                                            </Link>
                                        </DropdownMenuItem>
                                    </template>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </td>
                    </ClickableTableRow>
                </tbody>
            </table>
        </CommissionIndexLayout>
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
                    <template v-if="!selectedVehicule?.capacites.length">
                        —
                    </template>
                    <template v-else>
                        <span
                            v-for="(c, i) in selectedVehicule.capacites"
                            :key="c.categorie_nom"
                        >
                            {{ i > 0 ? ' · ' : '' }}{{ c.categorie_nom }} :
                            {{ c.capacite_max }}
                        </span>
                    </template>
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
