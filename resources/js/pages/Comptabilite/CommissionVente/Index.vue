<script setup lang="ts">
import AuditDrawer from '@/components/AuditDrawer.vue';
import ClickableTableRow from '@/components/ClickableTableRow.vue';
import CommissionIndexLayout from '@/components/commission/CommissionIndexLayout.vue';
import type { FilterField } from '@/components/filters/DataFilters.vue';
import PaymentCard from '@/components/payment/PaymentCard.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { usePaiementFiche } from '@/composables/usePaiementFiche';
import AppLayout from '@/layouts/AppLayout.vue';
import { filtrerParMotCle } from '@/lib/tableKeywordSearch';
import { type BreadcrumbItem } from '@/types';
import type { CommissionIndexSummary, FicheAPayer } from '@/types/commission';
import type {
    PeriodeAffichee,
    StatutCommissionResolu,
} from '@/types/commission-status';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    Building2,
    CheckCircle2,
    ExternalLink,
    HandCoins,
    History,
    MoreHorizontal,
    SlidersHorizontal,
    Split,
    Truck,
    User,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed, ref, watch } from 'vue';
import AjusterCommissionDialog from './partials/AjusterCommissionDialog.vue';

interface CreeePart {
    id: string;
    montant: number;
}

interface MotifOption {
    value: string;
    label: string;
}

interface VehiculeInfo {
    id: string | null;
    nom: string;
    immatriculation: string | null;
    type: string | null;
    capacites: { categorie_nom: string; capacite_max: number }[];
    proprietaire_nom: string | null;
    proprietaire_telephone: string | null;
    proprietaire_code_phone_pays: string | null;
}

interface BeneficiaireRow extends StatutCommissionResolu {
    beneficiaire_id: string;
    beneficiaire_nom: string;
    telephone: string | null;
    agence: string | null;
    vehicules: VehiculeInfo[];
    total_brut_cumule: number;
    total_frais: number;
    total_net_cumule: number;
    total_verse: number;
    solde_restant: number;
    remaining_amount: number;
    nb_commandes: number;
    statut_global: string;
    /** V2 uniquement (CommissionKpiBuckets) — absents en Legacy. */
    total_genere?: number;
    en_attente_periode?: number;
    payable?: number;
    /** Parts encore CREEE de ce bénéficiaire — plan de travail des actions Ajuster/Valider. */
    creee_parts: CreeePart[];
    /** Toujours présent, même filtré sur un seul processus — provenance jamais masquée. */
    processus_labels: string[];
    /** Fiche due sur laquelle le bouton Payer enregistre le paiement (null = rien à payer). */
    fiche_a_payer: FicheAPayer | null;
}

interface PeriodeOption {
    code: string;
    label: string;
}

const props = defineProps<{
    beneficiaires: BeneficiaireRow[];
    kpis: {
        nb_livreurs: number;
        total_brut: number;
        total_frais?: number;
        total_net: number;
        total_verse: number;
        solde_total: number;
        /** V2 uniquement (CommissionKpiBuckets) — absents en Legacy. */
        total_genere?: number;
        en_attente_periode?: number;
        payable?: number;
    };
    search: string;
    filtre_statut: string;
    filtre_site_ids: string[];
    filtre_processus: string[];
    processus_options: { value: string; label: string }[];
    selected_periode: string;
    periodes_disponibles: PeriodeOption[];
    periode_courante: string;
    periode_affichee: PeriodeAffichee | null;
    sites: { id: string; nom: string }[];
    motifs: MotifOption[];
    can_payer: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité' },
    {
        title: 'Commissions des livreurs',
        href: '/backoffice/comptabilite/commissions/vente',
    },
];

const search = ref(props.search ?? '');

// Recherche dans le tableau (façon globalFilter PrimeVue) : filtre uniquement les lignes
// affichées, sur les colonnes visibles — les KPI et l'export restent ceux de la période.
const motCle = ref('');

const lignesAffichees = computed(() =>
    filtrerParMotCle(props.beneficiaires, motCle.value, (b) => [
        b.beneficiaire_nom,
        b.telephone,
        ...b.vehicules.flatMap((v) => [v.nom, v.immatriculation]),
        b.agence,
        ...b.processus_labels,
        b.display_label,
        Math.round(b.total_genere ?? b.total_brut_cumule),
        Math.round(b.total_brut_cumule),
        Math.round(b.total_frais),
        Math.round(b.total_net_cumule),
        Math.round(b.total_verse),
        Math.round(b.solde_restant),
    ]),
);

const messageVide = computed(() =>
    motCle.value.trim()
        ? `Aucun livreur ne correspond à « ${motCle.value.trim()} ».`
        : 'Aucune commission trouvée.',
);

const filterFields = computed((): FilterField[] => [
    {
        key: 'processus',
        label: 'Processus',
        type: 'multi-select' as const,
        inline: true,
        options: props.processus_options,
    },
    {
        key: 'statut',
        label: 'Statut',
        type: 'select' as const,
        options: [
            { value: 'creee', label: 'À valider' },
            { value: 'impaye', label: 'Impayé' },
            { value: 'partiel', label: 'Partiel' },
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
    // Aucune sélection = "Tous les processus" (décision produit du 02/09/2026) — jamais un
    // repli implicite sur 'vente'.
    processus: props.filtre_processus ?? [],
}));

const paiement = usePaiementFiche();

const showAudit = ref(false);
const auditBenefId = ref('');
const auditBenefNom = ref('');

const vehiculeDialogVisible = ref(false);
const selectedVehicule = ref<VehiculeInfo | null>(null);

function openVehicule(v: VehiculeInfo) {
    selectedVehicule.value = v;
    vehiculeDialogVisible.value = true;
}

function openAudit(b: BeneficiaireRow) {
    auditBenefId.value = b.beneficiaire_id;
    auditBenefNom.value = b.beneficiaire_nom;
    showAudit.value = true;
}

function peutPayer(b: BeneficiaireRow): boolean {
    return props.can_payer && b.can_pay && b.fiche_a_payer !== null;
}

function openPaiement(b: BeneficiaireRow) {
    if (b.fiche_a_payer) paiement.open(b.fiche_a_payer);
}

function buildParams(): URLSearchParams {
    const params = new URLSearchParams();
    if (props.selected_periode) params.set('periode', props.selected_periode);
    for (const id of props.filtre_site_ids ?? []) {
        params.append('site_ids[]', id);
    }
    if (props.filtre_statut) params.set('statut', props.filtre_statut);
    for (const code of props.filtre_processus ?? []) {
        params.append('processus[]', code);
    }
    if (search.value) params.set('search', search.value);
    return params;
}

function exportExcel() {
    window.open(
        '/backoffice/comptabilite/commissions/vente/export/excel?' +
            buildParams().toString(),
        '_blank',
    );
}

function exportPdf() {
    window.open(
        '/backoffice/comptabilite/commissions/vente/export/pdf?' +
            buildParams().toString(),
        '_blank',
    );
}

const totalGenere = computed(
    () => props.kpis.total_genere ?? props.kpis.total_brut,
);

const totalDepenses = computed(
    () =>
        props.kpis.total_frais ??
        props.beneficiaires.reduce((total, beneficiaire) => {
            return total + beneficiaire.total_frais;
        }, 0),
);

const indexSummary = computed<CommissionIndexSummary>(() => ({
    generated: totalGenere.value,
    expenses: totalDepenses.value,
    netValidated: props.kpis.total_net,
    remaining: props.kpis.solde_total,
    paid: props.kpis.total_verse,
}));

const periodContextLabel = computed(() => {
    if (!props.selected_periode) return 'Toutes les périodes';

    return (
        props.periodes_disponibles.find(
            (periode) => periode.code === props.selected_periode,
        )?.label ?? props.selected_periode
    );
});

const confirm = useConfirm();
const toast = useToast();
const page = usePage();

const selected = ref<Set<string>>(new Set());

const selectableRows = computed(() =>
    lignesAffichees.value.filter((b) => b.creee_parts.length > 0),
);

// Une ligne masquée par la recherche ne reste jamais sélectionnée : « Valider la
// sélection » ne porte que sur ce que l'utilisateur voit.
watch(lignesAffichees, (lignes) => {
    if (selected.value.size === 0) return;
    const visibles = new Set(lignes.map((b) => b.beneficiaire_id));
    const next = new Set([...selected.value].filter((id) => visibles.has(id)));
    if (next.size !== selected.value.size) selected.value = next;
});

const allSelected = computed(
    () =>
        selectableRows.value.length > 0 &&
        selectableRows.value.every((b) =>
            selected.value.has(b.beneficiaire_id),
        ),
);

function toggleRow(b: BeneficiaireRow) {
    const next = new Set(selected.value);
    if (next.has(b.beneficiaire_id)) {
        next.delete(b.beneficiaire_id);
    } else {
        next.add(b.beneficiaire_id);
    }
    selected.value = next;
}

function toggleAll() {
    if (allSelected.value) {
        selected.value = new Set();
        return;
    }
    selected.value = new Set(
        selectableRows.value.map((b) => b.beneficiaire_id),
    );
}

function flashToast(fallback: string) {
    const flash = (page.props as any).flash;
    toast.add({
        severity: flash?.error ? 'warn' : 'success',
        summary: flash?.error ? 'Action impossible' : 'Succès',
        detail: flash?.error ?? flash?.success ?? fallback,
        life: 5000,
    });
}

function validerParts(parts: CreeePart[], label: string) {
    router.post(
        '/backoffice/comptabilite/commissions/ajustements/valider',
        { parts: parts.map((p) => ({ type: 'vente', id: p.id })) },
        {
            preserveScroll: true,
            onSuccess: () => {
                selected.value = new Set();
                flashToast(label);
            },
        },
    );
}

function validerRow(b: BeneficiaireRow) {
    confirm.require({
        message: `Valider la commission de ${b.beneficiaire_nom} (${fmt(b.en_attente_periode ?? 0)}) ?`,
        header: 'Confirmer la validation',
        acceptLabel: 'Valider',
        rejectLabel: 'Annuler',
        accept: () => validerParts(b.creee_parts, 'Commission validée.'),
    });
}

function validerSelection() {
    const rows = selectableRows.value.filter((b) =>
        selected.value.has(b.beneficiaire_id),
    );
    if (rows.length === 0) return;

    const parts = rows.flatMap((b) => b.creee_parts);
    const total = rows.reduce((sum, b) => sum + (b.en_attente_periode ?? 0), 0);

    confirm.require({
        message: `Valider ${rows.length} commission(s) sélectionnée(s) (${fmt(total)}) ?`,
        header: 'Confirmer la validation',
        acceptLabel: 'Valider',
        rejectLabel: 'Annuler',
        accept: () =>
            validerParts(parts, `${rows.length} commission(s) validée(s).`),
    });
}

const showAjusterDialog = ref(false);
const ajusterTarget = ref<BeneficiaireRow | null>(null);
const ajusterProcessing = ref(false);
const ajusterErrors = ref<Record<string, string>>({});

function openAjuster(b: BeneficiaireRow) {
    ajusterTarget.value = b;
    ajusterErrors.value = {};
    showAjusterDialog.value = true;
}

function submitAjuster(payload: {
    montant: number;
    motif: string;
    commentaire: string | null;
}) {
    if (!ajusterTarget.value) return;
    ajusterProcessing.value = true;
    ajusterErrors.value = {};
    router.patch(
        '/backoffice/comptabilite/commissions/ajustements/ajuster',
        {
            parts: ajusterTarget.value.creee_parts.map((p) => ({
                type: 'vente',
                id: p.id,
            })),
            montant: payload.montant,
            motif: payload.motif,
            commentaire: payload.commentaire,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                showAjusterDialog.value = false;
                flashToast('Montant ajusté.');
            },
            onError: (e) => {
                ajusterErrors.value = e as Record<string, string>;
            },
            onFinish: () => {
                ajusterProcessing.value = false;
            },
        },
    );
}

function repartirUrl(b: BeneficiaireRow): string | null {
    if (b.vehicules.length !== 1 || !b.vehicules[0].id) return null;
    const params = props.selected_periode
        ? `?periode=${encodeURIComponent(props.selected_periode)}`
        : '';
    return `/backoffice/comptabilite/commissions/vehicules/${b.vehicules[0].id}/repartir${params}`;
}

function fmt(val: number | null | undefined) {
    return (
        new Intl.NumberFormat('fr-FR')
            .format(Math.round(Math.abs(Number(val ?? 0))))
            .replace(/\u202f/g, '\u00a0') + ' GNF'
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
    <Head title="Commissions des livreurs — Comptabilité" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <CommissionIndexLayout
            saved-filter-scope="commissions-livreurs"
            title="Commissions des livreurs"
            :entity-count="kpis.nb_livreurs"
            entity-label="livreur"
            :period-label="periodContextLabel"
            :period-status="
                selected_periode && periode_affichee
                    ? {
                          status: periode_affichee.statut,
                          label: periode_affichee.statut_label,
                      }
                    : null
            "
            filter-url="/backoffice/comptabilite/commissions/vente"
            :filter-values="currentFilters"
            :filter-fields="filterFields"
            :sites="sites"
            :summary="indexSummary"
            :summary-label-overrides="{
                netValidated: {
                    label: 'Net à payer',
                    ariaLabel: 'Définition du net à payer',
                    tooltip:
                        'Montant actuellement retenu après déduction des dépenses — inclut les commissions pas encore validées. La validation conditionne le paiement, pas cet affichage.',
                },
            }"
            table-title="Détail par livreur"
            v-model:search-query="motCle"
            searchable
            search-placeholder="Rechercher un livreur, véhicule, montant…"
            :result-count="lignesAffichees.length"
            :total-count="beneficiaires.length"
            :empty-message="messageVide"
            @export-excel="exportExcel"
            @export-pdf="exportPdf"
        >
            <template #after-header>
                <div
                    v-if="selected.size > 0"
                    class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-primary/30 bg-primary/5 px-4 py-2.5"
                >
                    <span class="text-sm font-medium">
                        {{ selected.size }} sélectionné{{
                            selected.size > 1 ? 's' : ''
                        }}
                    </span>
                    <div class="flex items-center gap-2">
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="selected = new Set()"
                        >
                            Annuler
                        </Button>
                        <Button size="sm" @click="validerSelection">
                            <CheckCircle2 class="mr-1.5 h-4 w-4" />
                            Valider la sélection
                        </Button>
                    </div>
                </div>
            </template>

            <div class="commission-responsive">
                <table
                    data-testid="livreur-commissions-table"
                    class="commission-table w-full text-sm"
                >
                    <thead>
                        <tr class="border-b bg-muted/50">
                            <th
                                scope="col"
                                class="commission-selection w-10 px-3 py-3"
                            >
                                <Checkbox
                                    :model-value="allSelected"
                                    :disabled="selectableRows.length === 0"
                                    aria-label="Tout sélectionner"
                                    @update:model-value="toggleAll"
                                />
                                <span class="commission-select-label"
                                    >Tout sélectionner</span
                                >
                            </th>
                            <th
                                scope="col"
                                class="commission-identity px-4 py-3 text-left font-semibold text-foreground/70"
                            >
                                Livreur
                            </th>
                            <th
                                scope="col"
                                class="commission-context px-4 py-3 text-left font-semibold text-foreground/70"
                            >
                                Véhicule / Agence
                            </th>
                            <th
                                scope="col"
                                class="commission-calculation px-4 py-3 text-right font-semibold text-foreground/70"
                            >
                                Commissions
                            </th>
                            <th
                                scope="col"
                                class="commission-payment px-4 py-3 text-right font-semibold text-foreground/70"
                            >
                                Paiements
                            </th>
                            <th
                                scope="col"
                                class="commission-actions px-4 py-3 text-left font-semibold text-foreground/70"
                            >
                                Statut / Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <!-- Ne force pas ?processus=vente : la fiche détail affiche par défaut la
                         situation TOUS PROCESSUS confondus du bénéficiaire (décision produit du
                         31/08/2026), même en arrivant depuis cet écran "Commission vente". -->
                        <ClickableTableRow
                            v-for="b in lignesAffichees"
                            :key="b.beneficiaire_id"
                            :href="`/backoffice/comptabilite/commissions/vente/livreurs/${b.beneficiaire_id}`"
                            :aria-label="`Voir le détail de ${b.beneficiaire_nom}`"
                            class="group even:bg-muted/20"
                        >
                            <td
                                class="commission-selection px-3 py-4 align-top"
                                @click.stop
                            >
                                <Checkbox
                                    :model-value="
                                        selected.has(b.beneficiaire_id)
                                    "
                                    :disabled="b.creee_parts.length === 0"
                                    :aria-label="`Sélectionner ${b.beneficiaire_nom}`"
                                    @update:model-value="toggleRow(b)"
                                />
                            </td>
                            <td class="commission-identity px-4 py-4 align-top">
                                <div class="flex items-center gap-2.5">
                                    <User
                                        class="h-4 w-4 shrink-0 text-muted-foreground"
                                    />
                                    <div class="min-w-0">
                                        <p class="font-semibold break-words">
                                            {{ b.beneficiaire_nom }}
                                        </p>
                                        <p
                                            v-if="b.telephone"
                                            class="mt-0.5 text-xs text-muted-foreground"
                                        >
                                            {{ fmtTel(b.telephone) }}
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td
                                class="commission-context space-y-2 px-4 py-4 align-top"
                                @click.stop
                            >
                                <div
                                    v-if="b.vehicules.length"
                                    class="flex items-start gap-1.5 text-sm text-muted-foreground"
                                >
                                    <Truck
                                        class="mt-0.5 h-3.5 w-3.5 shrink-0"
                                    />
                                    <div class="min-w-0 space-y-1.5">
                                        <div
                                            v-for="(v, idx) in b.vehicules"
                                            :key="idx"
                                        >
                                            <button
                                                type="button"
                                                class="flex max-w-full items-start gap-1 text-left font-medium text-primary hover:underline focus-visible:outline focus-visible:outline-primary"
                                                @click="openVehicule(v)"
                                            >
                                                <span
                                                    class="min-w-0 break-words"
                                                    >{{ v.nom }}</span
                                                >
                                                <ExternalLink
                                                    class="mt-1 h-3 w-3 shrink-0"
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
                                <span
                                    v-else
                                    class="text-xs text-muted-foreground"
                                    >—</span
                                >
                                <div
                                    v-if="b.agence"
                                    class="flex items-start gap-1.5 text-xs text-muted-foreground"
                                    title="Agence qui paie : site actuel du véhicule"
                                >
                                    <Building2
                                        class="mt-0.5 h-3.5 w-3.5 shrink-0"
                                    />
                                    <span class="min-w-0 break-words">{{
                                        b.agence
                                    }}</span>
                                </div>
                                <span
                                    v-else
                                    class="text-xs text-muted-foreground"
                                    >—</span
                                >
                                <div
                                    v-if="b.processus_labels.length"
                                    class="flex flex-wrap gap-1"
                                >
                                    <span
                                        v-for="label in b.processus_labels"
                                        :key="label"
                                        class="rounded bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground"
                                    >
                                        {{ label }}
                                    </span>
                                </div>
                                <span
                                    v-else
                                    class="text-xs text-muted-foreground"
                                    >—</span
                                >
                            </td>
                            <td
                                class="commission-calculation px-4 py-4 align-top"
                            >
                                <dl class="commission-amounts">
                                    <div
                                        title="Montant calculé avant validation de la direction"
                                    >
                                        <dt>Généré</dt>
                                        <dd>
                                            {{
                                                fmt(
                                                    b.total_genere ??
                                                        b.total_brut_cumule,
                                                )
                                            }}
                                        </dd>
                                    </div>
                                    <div
                                        title="Montant brut retenu, avant déduction des dépenses"
                                    >
                                        <dt>Brut</dt>
                                        <dd>{{ fmt(b.total_brut_cumule) }}</dd>
                                    </div>
                                    <div
                                        title="Dépenses déduites du montant retenu"
                                    >
                                        <dt>Dépenses</dt>
                                        <dd
                                            class="text-red-600 dark:text-red-400"
                                        >
                                            {{
                                                b.total_frais > 0
                                                    ? '-' + fmt(b.total_frais)
                                                    : '—'
                                            }}
                                        </dd>
                                    </div>
                                </dl>
                            </td>
                            <td class="commission-payment px-4 py-4 align-top">
                                <dl class="commission-amounts">
                                    <div
                                        title="Montant actuellement retenu après dépenses et ajustements — indépendant de la validation de la période"
                                    >
                                        <dt>Net à payer</dt>
                                        <dd>{{ fmt(b.total_net_cumule) }}</dd>
                                    </div>
                                    <div>
                                        <dt>Déjà payé</dt>
                                        <dd>{{ fmt(b.total_verse) }}</dd>
                                    </div>
                                    <div>
                                        <dt>Reste à payer</dt>
                                        <dd class="font-bold text-foreground">
                                            {{ fmt(b.solde_restant) }}
                                        </dd>
                                    </div>
                                </dl>
                            </td>
                            <td
                                class="commission-actions px-4 py-4 align-top"
                                @click.stop
                            >
                                <div
                                    class="flex flex-wrap items-start justify-between gap-2"
                                >
                                    <div
                                        class="flex min-w-0 flex-col items-start gap-2"
                                    >
                                        <StatusDot
                                            :status="b.display_status"
                                            :label="b.display_label"
                                        />
                                        <Button
                                            v-if="b.creee_parts.length > 0"
                                            variant="outline"
                                            size="sm"
                                            class="h-9 px-3 text-xs"
                                            @click="validerRow(b)"
                                        >
                                            Valider
                                        </Button>
                                        <Button
                                            v-else-if="peutPayer(b)"
                                            variant="outline"
                                            size="sm"
                                            class="h-9 px-3 text-xs"
                                            @click="openPaiement(b)"
                                        >
                                            <HandCoins
                                                class="mr-1 h-3.5 w-3.5"
                                            />
                                            Payer
                                        </Button>
                                    </div>
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                :aria-label="`Actions pour ${b.beneficiaire_nom}`"
                                                class="h-9 w-9 shrink-0"
                                            >
                                                <MoreHorizontal
                                                    class="h-4 w-4"
                                                />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            <DropdownMenuItem as-child>
                                                <Link
                                                    :href="`/backoffice/comptabilite/commissions/vente/livreurs/${b.beneficiaire_id}`"
                                                    class="flex w-full cursor-pointer items-center"
                                                >
                                                    Détail
                                                </Link>
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                class="cursor-pointer"
                                                @click="openAudit(b)"
                                            >
                                                <History class="mr-2 h-4 w-4" />
                                                Historique
                                            </DropdownMenuItem>
                                            <template
                                                v-if="b.creee_parts.length > 0"
                                            >
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    class="cursor-pointer"
                                                    @click="validerRow(b)"
                                                >
                                                    <CheckCircle2
                                                        class="mr-2 h-4 w-4"
                                                    />
                                                    Valider
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    class="cursor-pointer"
                                                    @click="openAjuster(b)"
                                                >
                                                    <SlidersHorizontal
                                                        class="mr-2 h-4 w-4"
                                                    />
                                                    Ajuster
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    v-if="repartirUrl(b)"
                                                    as-child
                                                    class="cursor-pointer"
                                                >
                                                    <Link
                                                        :href="repartirUrl(b)!"
                                                    >
                                                        <Split
                                                            class="mr-2 h-4 w-4"
                                                        />
                                                        Répartir
                                                    </Link>
                                                </DropdownMenuItem>
                                            </template>
                                            <template v-if="peutPayer(b)">
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    class="cursor-pointer"
                                                    @click="openPaiement(b)"
                                                >
                                                    <HandCoins
                                                        class="mr-2 h-4 w-4"
                                                    />
                                                    Payer
                                                </DropdownMenuItem>
                                            </template>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>
                            </td>
                        </ClickableTableRow>
                    </tbody>
                </table>
            </div>
        </CommissionIndexLayout>
    </AppLayout>

    <PaymentCard
        v-if="paiement.fiche.value"
        v-model:visible="paiement.visible.value"
        :title="paiement.title.value"
        :info-rows="paiement.infoRows.value"
        sens="decaissement"
        solde-label="Reste à payer"
        :solde="paiement.fiche.value.montant_restant"
        :moyens="paiement.fiche.value.tresorerie.moyens"
        :especes-disponibles="
            paiement.fiche.value.tresorerie.especes_disponibles
        "
        :solde-especes="paiement.fiche.value.tresorerie.solde_especes"
        :processing="paiement.processing.value"
        :errors="paiement.errors.value"
        @submit="paiement.submit"
    />

    <AuditDrawer
        v-model:visible="showAudit"
        :title="`Historique — ${auditBenefNom}`"
        auditable-type="App\Models\Livreur"
        :auditable-id="auditBenefId"
        module="commissions_vente"
    />

    <AjusterCommissionDialog
        v-model:visible="showAjusterDialog"
        :title="
            ajusterTarget
                ? `Ajuster — ${ajusterTarget.beneficiaire_nom}`
                : 'Ajuster'
        "
        :montant-theorique="ajusterTarget?.en_attente_periode ?? 0"
        :motifs="motifs"
        :processing="ajusterProcessing"
        :errors="ajusterErrors"
        @submit="submitAjuster"
    />

    <Dialog
        v-model:visible="vehiculeDialogVisible"
        modal
        header="Détail véhicule"
        :style="{ width: '28rem', maxWidth: 'calc(100vw - 2rem)' }"
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

<style scoped>
.commission-responsive {
    container-type: inline-size;
}

.commission-table {
    table-layout: fixed;
}

.commission-selection {
    width: 3rem;
}

.commission-identity,
.commission-context,
.commission-calculation {
    width: 21%;
}

.commission-payment {
    width: 22%;
}

.commission-select-label {
    display: none;
}

.commission-amounts {
    display: grid;
    gap: 0.625rem;
    font-size: 0.8125rem;
    font-variant-numeric: tabular-nums;
}

.commission-amounts > div {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: baseline;
    column-gap: 0.5rem;
    row-gap: 0.125rem;
}

.commission-amounts dt {
    color: var(--muted-foreground);
    font-size: 0.75rem;
}

.commission-amounts dd {
    margin-left: auto;
    text-align: right;
    white-space: nowrap;
}

.commission-actions :deep(.inline-flex) {
    white-space: normal;
}

@container (max-width: 1049px) {
    .commission-table,
    .commission-table thead,
    .commission-table tbody {
        display: block;
    }

    .commission-table thead tr {
        display: block;
        border: 0;
    }

    .commission-table thead th {
        display: none;
    }

    .commission-table thead .commission-selection {
        display: flex;
        width: 100%;
        align-items: center;
        gap: 0.75rem;
        text-align: left;
    }

    .commission-select-label {
        display: inline;
        font-size: 0.75rem;
        font-weight: 500;
    }

    .commission-table tbody tr {
        position: relative;
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        padding: 1rem;
        gap: 1rem;
    }

    .commission-table tbody td {
        display: block;
        width: auto;
        min-width: 0;
        padding: 0;
    }

    .commission-table tbody .commission-selection {
        position: absolute;
        top: 1rem;
        left: 1rem;
    }

    .commission-table tbody .commission-identity {
        grid-column: 1 / -1;
        padding-left: 2rem;
    }

    .commission-context,
    .commission-actions {
        grid-column: 1 / -1;
    }

    .commission-calculation,
    .commission-payment,
    .commission-actions {
        border-top: 1px solid var(--border);
        padding-top: 0.75rem !important;
    }

    .commission-actions :deep(button) {
        min-width: 2.75rem;
        min-height: 2.75rem;
    }
}

@container (max-width: 449px) {
    .commission-table tbody tr {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>
