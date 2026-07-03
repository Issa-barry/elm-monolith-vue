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
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useClickableTableRow } from '@/composables/useClickableTableRow';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CheckCircle,
    ChevronRight,
    HandCoins,
    History,
    MoreHorizontal,
    Pencil,
    Plus,
    Search,
    ShoppingCart,
    Trash2,
    XCircle,
} from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Dialog from 'primevue/dialog';
import InputNumber from 'primevue/inputnumber';
import Select from 'primevue/select';
import Textarea from 'primevue/textarea';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed, ref } from 'vue';

// ── Types ─────────────────────────────────────────────────────────────────────
interface Commande {
    id: number;
    reference: string;
    statut: string;
    statut_label: string;
    total_commande: number;
    vehicule_nom: string | null;
    vehicule_immatriculation: string | null;
    chauffeur_nom: string | null;
    client_nom: string | null;
    client_telephone: string | null;
    site_nom: string | null;
    facture_id: number | null;
    facture_statut: string | null;
    facture_statut_label: string | null;
    facture_montant_encaisse: number | null;
    facture_montant_restant: number | null;
    encaissements: {
        id: number;
        montant: number;
        date_encaissement: string;
        heure: string | null;
        mode_paiement_label: string;
        created_by: string | null;
    }[];
    created_at: string;
    is_annulee: boolean;
    is_brouillon: boolean;
    can_modifier: boolean;
    can_confirmer: boolean;
    can_annuler: boolean;
}

interface Totaux {
    total_montant: number;
    nb_total: number;
    total_a_encaisser: number;
    deja_paye: number;
    nb_cloturees: number;
    montant_cloturees: number;
}

interface SiteOption {
    id: string;
    nom: string;
}

interface Filters {
    site_ids: string[];
    date_debut: string | null;
    date_fin: string | null;
    statut_facture: string | null;
    statut_commission: string | null;
    vehicule: string | null;
    proprietaire: string | null;
    livreur: string | null;
    numero_commande: string | null;
    client: string | null;
}

// ── Props ─────────────────────────────────────────────────────────────────────
const props = defineProps<{
    commandes: Commande[];
    totaux: Totaux;
    periode: string;
    statuts_actifs: string[];
    sites: SiteOption[];
    is_admin: boolean;
    filters: Filters;
}>();

const { can } = usePermissions();
const confirm = useConfirm();
const toast = useToast();

const { onRowClick, bodyRowPt } = useClickableTableRow<Commande>(
    (commande) => `/backoffice/ventes/${commande.id}`,
);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Ventes', href: '/backoffice/ventes' },
];

// ── Options statique ──────────────────────────────────────────────────────────
const filtresStatut = [
    { value: 'brouillon', label: 'Brouillon' },
    { value: 'a_charger', label: 'À charger' },
    { value: 'chargement_en_cours', label: 'Chargement en cours' },
    { value: 'livraison_en_cours', label: 'En livraison' },
    { value: 'livree', label: 'Livrée' },
    { value: 'cloturee', label: 'Clôturée' },
    { value: 'annulee', label: 'Annulée' },
];

const filtresStatutFacture = [
    { value: '', label: 'Tous' },
    { value: 'creee', label: 'Créée' },
    { value: 'impayee', label: 'Impayée' },
    { value: 'partiel', label: 'Partiellement payée' },
    { value: 'payee', label: 'Soldée' },
    { value: 'annulee', label: 'Annulée' },
];

const filtresStatutCommission = [
    { value: '', label: 'Tous' },
    { value: 'creee', label: 'Créée' },
    { value: 'impaye', label: 'Impayée' },
    { value: 'partiel', label: 'Partiellement payée' },
    { value: 'paye', label: 'Payée' },
];

// ── Filtres ───────────────────────────────────────────────────────────────────

const mobileSearch = ref('');

const filterFields: FilterField[] = [
    {
        key: 'statuts',
        label: 'Statut commande',
        type: 'multi-select',
        options: filtresStatut,
        placeholder: 'Tous les statuts',
        inline: true,
    },
    {
        key: 'statut_facture',
        label: 'Statut facture',
        type: 'select',
        options: filtresStatutFacture,
    },
    {
        key: 'statut_commission',
        label: 'Statut commission',
        type: 'select',
        options: filtresStatutCommission,
    },
    {
        key: 'date',
        label: 'Période',
        type: 'date-range',
        startKey: 'date_debut',
        endKey: 'date_fin',
    },
    {
        key: 'vehicule',
        label: 'Véhicule',
        type: 'text',
        placeholder: 'Nom ou immatriculation…',
        inline: true,
    },
    {
        key: 'proprietaire',
        label: 'Propriétaire',
        type: 'text',
        placeholder: 'Nom, prénom ou téléphone…',
    },
    {
        key: 'livreur',
        label: 'Livreur',
        type: 'text',
        placeholder: 'Nom, prénom ou téléphone…',
        inline: true,
    },
    {
        key: 'client',
        label: 'Client',
        type: 'text',
        placeholder: 'Nom, prénom ou téléphone…',
    },
    {
        key: 'numero_commande',
        label: 'N° commande',
        type: 'text',
        placeholder: 'CMD-…',
        inline: true,
    },
];

const filterValues = computed(() => ({
    statuts: props.statuts_actifs ?? [],
    ...props.filters,
}));

const commandesFiltrees = computed(() => props.commandes);

// ── Filtre mobile ─────────────────────────────────────────────────────────────

const mobileFiltered = computed(() => {
    const q = mobileSearch.value.toLowerCase().trim();
    if (!q) return props.commandes;
    return props.commandes.filter(
        (c) =>
            c.reference.toLowerCase().includes(q) ||
            (c.vehicule_nom && c.vehicule_nom.toLowerCase().includes(q)) ||
            (c.vehicule_immatriculation &&
                c.vehicule_immatriculation.toLowerCase().includes(q)) ||
            (c.client_nom && c.client_nom.toLowerCase().includes(q)) ||
            (c.site_nom && c.site_nom.toLowerCase().includes(q)) ||
            (c.statut_label && c.statut_label.toLowerCase().includes(q)) ||
            (c.facture_statut_label &&
                c.facture_statut_label.toLowerCase().includes(q)) ||
            (c.created_at && c.created_at.toLowerCase().includes(q)),
    );
});

// ── Formatage ─────────────────────────────────────────────────────────────────
function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}

// ── Confirmation commande (BROUILLON → A_CHARGER) ────────────────────────────
const confirmationProcessing = ref(false);

function confirmer(commande: Commande) {
    if (confirmationProcessing.value) return;
    confirmationProcessing.value = true;
    router.patch(
        `/backoffice/ventes/${commande.id}/valider`,
        {},
        {
            onSuccess: () =>
                toast.add({
                    severity: 'success',
                    summary: 'Confirmée',
                    detail: 'Commande confirmée. En attente de chargement.',
                    life: 3000,
                }),
            onFinish: () => (confirmationProcessing.value = false),
        },
    );
}

// ── Annulation ────────────────────────────────────────────────────────────────
const annulerDialogVisible = ref(false);
const selectedCommande = ref<Commande | null>(null);

const MOTIFS_ANNULATION = [
    { value: 'erreur_saisie', label: 'Erreur de saisie' },
    { value: 'doublon', label: 'Doublon' },
    { value: 'rupture_stock', label: 'Rupture de stock' },
    { value: 'autre', label: 'Autre' },
] as const;

const annulerForm = useForm({
    motif_annulation_code: '' as string,
    motif_annulation_detail: '',
});

function openAnnulerDialog(commande: Commande) {
    selectedCommande.value = commande;
    annulerForm.reset();
    annulerDialogVisible.value = true;
}

function submitAnnuler() {
    if (!selectedCommande.value) return;
    annulerForm.patch(
        `/backoffice/ventes/${selectedCommande.value.id}/annuler`,
        {
            onSuccess: () => {
                annulerDialogVisible.value = false;
                toast.add({
                    severity: 'success',
                    summary: 'Annulée',
                    detail: 'Commande annulée avec succès.',
                    life: 3000,
                });
            },
        },
    );
}

const annulerDisabled = computed(
    () =>
        annulerForm.processing ||
        !annulerForm.motif_annulation_code ||
        (annulerForm.motif_annulation_code === 'autre' &&
            !annulerForm.motif_annulation_detail.trim()),
);

// ── Encaissement ──────────────────────────────────────────────────────────────
const modesPaiement = [
    { value: 'especes', label: 'Espèces' },
    { value: 'mobile_money', label: 'Mobile Money' },
    { value: 'virement', label: 'Virement' },
    { value: 'cheque', label: 'Chèque' },
];

const encaisserDialogVisible = ref(false);
const encaisserCommande = ref<Commande | null>(null);
const encaisserForm = useForm({
    montant: null as number | null,
    mode_paiement: 'especes' as string | null,
    date_encaissement: new Date().toISOString().slice(0, 10),
});

function openEncaisserDialog(commande: Commande) {
    encaisserCommande.value = commande;
    encaisserForm.reset();
    encaisserForm.montant = commande.facture_montant_restant;
    encaisserForm.mode_paiement = 'especes';
    encaisserForm.date_encaissement = new Date().toISOString().slice(0, 10);
    encaisserDialogVisible.value = true;
}

function submitEncaisser() {
    if (!encaisserCommande.value?.facture_id) return;
    encaisserForm.post(
        `/backoffice/factures/${encaisserCommande.value.facture_id}/encaissements`,
        {
            onSuccess: () => {
                encaisserDialogVisible.value = false;
                toast.add({
                    severity: 'success',
                    summary: 'Encaissement enregistré',
                    detail: `${formatGNF(encaisserForm.montant ?? 0)} enregistré avec succès.`,
                    life: 3000,
                });
            },
        },
    );
}

// ── Historique ────────────────────────────────────────────────────────────────
const historyVisible = ref(false);
const historyCommande = ref<Commande | null>(null);

function openHistory(commande: Commande) {
    historyCommande.value = commande;
    historyVisible.value = true;
}

// ── Suppression ───────────────────────────────────────────────────────────────
function confirmDelete(c: Commande) {
    confirm.require({
        message: `Supprimer la commande « ${c.reference} » ? Cette action est irréversible.`,
        header: 'Confirmer la suppression',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Annuler',
        acceptLabel: 'Supprimer',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(`/backoffice/ventes/${c.id}`, {
                onSuccess: () =>
                    toast.add({
                        severity: 'success',
                        summary: 'Supprimée',
                        detail: 'Commande supprimée.',
                        life: 3000,
                    }),
            });
        },
    });
}
</script>

<template>
    <Head title="Ventes" />

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
                <span class="text-base font-semibold">Ventes</span>
                <Link
                    v-if="can('ventes.create')"
                    href="/backoffice/ventes/create"
                >
                    <Button size="sm" class="h-8 px-3 text-xs">
                        <Plus class="mr-1 h-3.5 w-3.5" />
                        Nouveau
                    </Button>
                </Link>
                <div v-else class="w-8" />
            </div>

            <!-- KPI cards -->
            <div class="grid grid-cols-3 gap-3 p-4">
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground">Total</p>
                    <p class="mt-1 text-lg font-bold tabular-nums">
                        {{ formatGNF(totaux.total_montant) }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ totaux.nb_total }} commande{{
                            totaux.nb_total > 1 ? 's' : ''
                        }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground">
                        Restant à encaisser
                    </p>
                    <p class="mt-1 text-lg font-bold tabular-nums">
                        {{ formatGNF(totaux.total_a_encaisser) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground">Déjà payé</p>
                    <p class="mt-1 text-lg font-bold tabular-nums">
                        {{ formatGNF(totaux.deja_paye) }}
                    </p>
                </div>
            </div>

            <!-- Search -->
            <div class="border-t border-b px-4 py-2">
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <input
                        v-model="mobileSearch"
                        type="text"
                        placeholder="Référence, client…"
                        class="h-9 w-full rounded-md border border-input bg-background pr-3 pl-8 text-sm placeholder:text-muted-foreground focus:ring-1 focus:ring-ring focus:outline-none"
                    />
                </div>
            </div>

            <!-- Card list -->
            <div class="divide-y">
                <Link
                    v-for="c in mobileFiltered"
                    :key="c.id"
                    :href="`/backoffice/ventes/${c.id}`"
                    class="flex items-start justify-between gap-3 px-4 py-3 hover:bg-muted/10 active:bg-muted/20"
                >
                    <div class="min-w-0 flex-1">
                        <p
                            class="font-mono text-sm font-semibold tracking-wide text-primary"
                        >
                            {{ c.reference }}
                        </p>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            {{ c.vehicule_nom ?? c.client_nom ?? '—' }}
                        </p>
                        <p class="mt-1 text-sm font-medium tabular-nums">
                            {{ formatGNF(c.total_commande) }}
                        </p>
                        <p
                            v-if="
                                c.facture_montant_restant !== null &&
                                c.facture_montant_restant > 0
                            "
                            class="text-xs font-semibold text-amber-600 tabular-nums dark:text-amber-400"
                        >
                            Restant : {{ formatGNF(c.facture_montant_restant) }}
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <div class="flex flex-col items-end gap-1.5">
                            <StatusDot
                                :status="c.statut"
                                :label="c.statut_label"
                            />
                            <span
                                class="text-xs text-muted-foreground tabular-nums"
                                >{{ c.created_at }}</span
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
                <ShoppingCart class="h-10 w-10 opacity-30" />
                <p class="text-sm">Aucune commande trouvée.</p>
                <Link
                    v-if="can('ventes.create')"
                    href="/backoffice/ventes/create"
                >
                    <Button variant="outline" size="sm">
                        <Plus class="mr-2 h-4 w-4" />
                        Créer la première commande
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
                        Ventes
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Suivi et encaissement des commandes.
                    </p>
                </div>
                <Link
                    v-if="can('ventes.create')"
                    href="/backoffice/ventes/create"
                >
                    <Button>
                        <Plus class="mr-2 h-4 w-4" />
                        Nouvelle commande
                    </Button>
                </Link>
            </div>

            <!-- KPI cards -->
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Total</p>
                    <p class="mt-2 text-2xl font-bold tabular-nums">
                        {{ formatGNF(totaux.total_montant) }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ totaux.nb_total }} commande{{
                            totaux.nb_total > 1 ? 's' : ''
                        }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">
                        Restant à encaisser
                    </p>
                    <p class="mt-2 text-2xl font-bold tabular-nums">
                        {{ formatGNF(totaux.total_a_encaisser) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Déjà payé</p>
                    <p class="mt-2 text-2xl font-bold tabular-nums">
                        {{ formatGNF(totaux.deja_paye) }}
                    </p>
                </div>
            </div>

            <!-- Filtres -->
            <DataFilters
                url="/backoffice/ventes"
                :base-params="{ periode: 'all' }"
                :values="filterValues"
                :sites="sites"
                :result-count="commandesFiltrees.length"
                :fields="filterFields"
            />

            <!-- Tableau -->
            <div class="overflow-x-auto rounded-xl border bg-card">
                <DataTable
                    :value="commandesFiltrees"
                    :paginator="commandesFiltrees.length > 20"
                    :rows="20"
                    data-key="id"
                    striped-rows
                    removable-sort
                    class="text-sm"
                    :pt="{
                        root: { class: 'w-full min-w-[1100px]' },
                        tbody: { class: 'divide-y' },
                        bodyRow: bodyRowPt,
                    }"
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
                                :href="`/backoffice/ventes/${data.id}`"
                                class="hover:underline"
                            >
                                <span
                                    class="inline-block rounded bg-muted px-1.5 py-0.5 font-mono text-[11px] text-muted-foreground"
                                >
                                    {{ data.reference }}
                                </span>
                            </Link>
                        </template>
                    </Column>

                    <!-- Véhicule -->
                    <Column header="Véhicule" style="min-width: 140px">
                        <template #body="{ data }">
                            <span
                                v-if="data.vehicule_nom"
                                class="font-medium"
                                >{{ data.vehicule_nom }}</span
                            >
                            <span v-else class="text-muted-foreground">—</span>
                        </template>
                    </Column>

                    <!-- Livreur -->
                    <Column header="Livreur" style="min-width: 130px">
                        <template #body="{ data }">
                            <span
                                v-if="data.chauffeur_nom"
                                class="text-muted-foreground"
                                >{{ data.chauffeur_nom }}</span
                            >
                            <span v-else class="text-muted-foreground">—</span>
                        </template>
                    </Column>

                    <!-- Client -->
                    <Column header="Client" style="min-width: 140px">
                        <template #body="{ data }">
                            <span
                                v-if="data.client_nom"
                                class="text-muted-foreground"
                                >{{ data.client_nom }}</span
                            >
                            <span v-else class="text-muted-foreground">—</span>
                        </template>
                    </Column>

                    <!-- Site -->
                    <Column
                        field="site_nom"
                        header="Site"
                        sortable
                        style="min-width: 120px"
                    >
                        <template #body="{ data }">
                            <span
                                data-testid="row-site"
                                class="text-muted-foreground"
                                >{{ data.site_nom ?? '—' }}</span
                            >
                        </template>
                    </Column>

                    <!-- Montant -->
                    <Column
                        field="total_commande"
                        header="Montant"
                        sortable
                        style="width: 140px"
                    >
                        <template #body="{ data }">
                            <span class="tabular-nums">{{
                                formatGNF(data.total_commande)
                            }}</span>
                        </template>
                    </Column>

                    <!-- Restant -->
                    <Column
                        field="facture_montant_restant"
                        header="Restant"
                        sortable
                        style="width: 140px"
                    >
                        <template #body="{ data }">
                            <span class="text-muted-foreground tabular-nums">
                                {{
                                    data.facture_montant_restant !== null
                                        ? data.facture_montant_restant > 0
                                            ? formatGNF(
                                                  data.facture_montant_restant,
                                              )
                                            : '—'
                                        : '—'
                                }}
                            </span>
                        </template>
                    </Column>

                    <!-- Date -->
                    <Column
                        field="created_at"
                        header="Date"
                        sortable
                        style="width: 110px"
                    >
                        <template #body="{ data }">
                            <span
                                class="text-xs text-muted-foreground tabular-nums"
                                >{{ data.created_at }}</span
                            >
                        </template>
                    </Column>

                    <!-- Statut commande -->
                    <Column
                        field="statut"
                        header="Statut"
                        sortable
                        style="width: 130px"
                    >
                        <template #body="{ data }">
                            <StatusDot
                                :status="data.statut"
                                :label="data.statut_label"
                            />
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
                                                :href="`/backoffice/ventes/${data.id}`"
                                                class="flex w-full cursor-pointer items-center gap-2"
                                            >
                                                <ShoppingCart class="h-4 w-4" />
                                                Détail
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            v-if="data.can_modifier"
                                            as-child
                                        >
                                            <Link
                                                :href="`/backoffice/ventes/${data.id}/edit`"
                                                class="flex w-full cursor-pointer items-center gap-2"
                                            >
                                                <Pencil class="h-4 w-4" />
                                                Modifier
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            v-if="data.can_confirmer"
                                            class="cursor-pointer text-blue-600 focus:text-blue-600"
                                            :disabled="confirmationProcessing"
                                            @click="confirmer(data)"
                                        >
                                            <CheckCircle class="h-4 w-4" />
                                            Confirmer
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            v-if="
                                                data.facture_id &&
                                                data.facture_montant_restant >
                                                    0 &&
                                                can('ventes.update')
                                            "
                                            class="cursor-pointer"
                                            @click="openEncaisserDialog(data)"
                                        >
                                            <HandCoins class="h-4 w-4" />
                                            Encaisser
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            v-if="data.facture_id"
                                            class="cursor-pointer"
                                            @click="openHistory(data)"
                                        >
                                            <History class="h-4 w-4" />
                                            Historique
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator
                                            v-if="
                                                data.can_annuler ||
                                                (data.is_annulee &&
                                                    can('ventes.delete'))
                                            "
                                        />
                                        <DropdownMenuItem
                                            v-if="data.can_annuler"
                                            class="cursor-pointer text-amber-600 focus:text-amber-600"
                                            @click="openAnnulerDialog(data)"
                                        >
                                            <XCircle class="h-4 w-4" />
                                            Annuler
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator
                                            v-if="
                                                data.is_annulee &&
                                                can('ventes.delete')
                                            "
                                        />
                                        <DropdownMenuItem
                                            v-if="
                                                data.is_annulee &&
                                                can('ventes.delete')
                                            "
                                            class="cursor-pointer text-destructive focus:text-destructive"
                                            @click="confirmDelete(data)"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                            Supprimer
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </template>
                    </Column>

                    <template #empty>
                        <div
                            class="flex flex-col items-center gap-3 py-16 text-muted-foreground"
                        >
                            <ShoppingCart class="h-12 w-12 opacity-30" />
                            <p class="text-sm">Aucune commande trouvée.</p>
                            <Link
                                v-if="can('ventes.create')"
                                href="/backoffice/ventes/create"
                            >
                                <Button variant="outline" size="sm">
                                    <Plus class="mr-2 h-4 w-4" />
                                    Créer la première commande
                                </Button>
                            </Link>
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>

        <!-- Dialog Historique -->
        <Dialog
            v-model:visible="historyVisible"
            modal
            :header="
                historyCommande
                    ? `Historique — ${historyCommande.reference}`
                    : 'Historique'
            "
            :style="{ width: '560px' }"
        >
            <div v-if="historyCommande">
                <div
                    v-if="historyCommande.encaissements.length === 0"
                    class="py-8 text-center text-sm text-muted-foreground"
                >
                    Aucun encaissement enregistré.
                </div>
                <table v-else class="w-full text-sm">
                    <thead>
                        <tr class="border-b bg-muted/30">
                            <th
                                class="px-3 py-2 text-left font-medium text-muted-foreground"
                            >
                                Date
                            </th>
                            <th
                                class="px-3 py-2 text-left font-medium text-muted-foreground"
                            >
                                Heure
                            </th>
                            <th
                                class="px-3 py-2 text-left font-medium text-muted-foreground"
                            >
                                Mode
                            </th>
                            <th
                                class="px-3 py-2 text-right font-medium text-muted-foreground"
                            >
                                Montant
                            </th>
                            <th
                                class="px-3 py-2 text-left font-medium text-muted-foreground"
                            >
                                Par
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="e in historyCommande.encaissements"
                            :key="e.id"
                            class="hover:bg-muted/10"
                        >
                            <td
                                class="px-3 py-2 text-muted-foreground tabular-nums"
                            >
                                {{ e.date_encaissement }}
                            </td>
                            <td
                                class="px-3 py-2 text-muted-foreground tabular-nums"
                            >
                                {{ e.heure ?? '—' }}
                            </td>
                            <td class="px-3 py-2 text-muted-foreground">
                                {{ e.mode_paiement_label }}
                            </td>
                            <td
                                class="px-3 py-2 text-right font-medium tabular-nums"
                            >
                                {{ formatGNF(e.montant) }}
                            </td>
                            <td class="px-3 py-2 text-xs text-muted-foreground">
                                {{ e.created_by ?? '—' }}
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t">
                            <td
                                colspan="3"
                                class="px-3 py-2 text-sm font-semibold"
                            >
                                Total encaissé
                            </td>
                            <td
                                class="px-3 py-2 text-right font-semibold tabular-nums"
                            >
                                {{
                                    formatGNF(
                                        historyCommande.encaissements.reduce(
                                            (s, e) => s + e.montant,
                                            0,
                                        ),
                                    )
                                }}
                            </td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </Dialog>

        <!-- Dialog Encaissement -->
        <Dialog
            v-model:visible="encaisserDialogVisible"
            modal
            header="Encaisser un paiement"
            :style="{ width: '440px' }"
        >
            <div v-if="encaisserCommande" class="space-y-4">
                <div class="space-y-1.5 rounded-lg bg-muted/40 p-4 text-sm">
                    <div class="flex justify-between">
                        <span class="text-muted-foreground">Commande</span>
                        <span class="font-mono font-semibold">{{
                            encaisserCommande.reference
                        }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-muted-foreground">Montant total</span>
                        <span class="tabular-nums">{{
                            formatGNF(encaisserCommande.total_commande)
                        }}</span>
                    </div>
                    <div class="flex justify-between border-t pt-1.5">
                        <span class="font-semibold text-muted-foreground"
                            >Restant dû</span
                        >
                        <span class="font-bold tabular-nums">{{
                            formatGNF(
                                encaisserCommande.facture_montant_restant ?? 0,
                            )
                        }}</span>
                    </div>
                </div>

                <div>
                    <Label class="mb-1.5 block text-sm"
                        >Montant <span class="text-destructive">*</span></Label
                    >
                    <InputNumber
                        v-model="encaisserForm.montant"
                        :max="
                            encaisserCommande.facture_montant_restant ??
                            undefined
                        "
                        :min="1"
                        :use-grouping="true"
                        locale="fr-FR"
                        suffix=" GNF"
                        class="w-full"
                        fluid
                        :class="{ 'p-invalid': encaisserForm.errors.montant }"
                    />
                    <p
                        v-if="encaisserForm.errors.montant"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ encaisserForm.errors.montant }}
                    </p>
                </div>

                <div>
                    <Label class="mb-1.5 block text-sm"
                        >Mode de paiement
                        <span class="text-destructive">*</span></Label
                    >
                    <Select
                        v-model="encaisserForm.mode_paiement"
                        :options="modesPaiement"
                        option-label="label"
                        option-value="value"
                        class="w-full"
                        fluid
                        :class="{
                            'p-invalid': encaisserForm.errors.mode_paiement,
                        }"
                    />
                    <p
                        v-if="encaisserForm.errors.mode_paiement"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ encaisserForm.errors.mode_paiement }}
                    </p>
                </div>
            </div>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <Button
                        variant="outline"
                        @click="encaisserDialogVisible = false"
                        >Annuler</Button
                    >
                    <Button
                        :disabled="
                            encaisserForm.processing ||
                            !encaisserForm.montant ||
                            !encaisserForm.mode_paiement
                        "
                        @click="submitEncaisser"
                    >
                        <HandCoins class="mr-2 h-4 w-4" />
                        {{
                            encaisserForm.processing
                                ? 'Enregistrement…'
                                : 'Confirmer'
                        }}
                    </Button>
                </div>
            </template>
        </Dialog>

        <!-- Dialog Annulation -->
        <Dialog
            v-model:visible="annulerDialogVisible"
            modal
            header="Annuler la commande"
            :style="{ width: '480px' }"
        >
            <div class="space-y-4">
                <p class="text-sm text-muted-foreground">
                    Vous êtes sur le point d'annuler la commande
                    <span class="font-mono font-semibold">{{
                        selectedCommande?.reference
                    }}</span
                    >. Cette action est irréversible.
                </p>
                <div>
                    <Label
                        for="idx-annulation-motif-code"
                        class="mb-1.5 block text-sm"
                    >
                        Motif <span class="text-destructive">*</span>
                    </Label>
                    <Select
                        input-id="idx-annulation-motif-code"
                        v-model="annulerForm.motif_annulation_code"
                        :options="MOTIFS_ANNULATION"
                        option-label="label"
                        option-value="value"
                        placeholder="Sélectionner un motif"
                        class="w-full"
                        fluid
                        :class="{
                            'p-invalid':
                                annulerForm.errors.motif_annulation_code,
                        }"
                    />
                    <p
                        v-if="annulerForm.errors.motif_annulation_code"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ annulerForm.errors.motif_annulation_code }}
                    </p>
                </div>
                <div v-if="annulerForm.motif_annulation_code === 'autre'">
                    <Label
                        for="idx-annulation-motif-detail"
                        class="mb-1.5 block text-sm"
                    >
                        Précision <span class="text-destructive">*</span>
                    </Label>
                    <Textarea
                        id="idx-annulation-motif-detail"
                        v-model="annulerForm.motif_annulation_detail"
                        rows="3"
                        class="w-full"
                        placeholder="Indiquez la raison..."
                        :class="{
                            'p-invalid':
                                annulerForm.errors.motif_annulation_detail,
                        }"
                    />
                    <p
                        v-if="annulerForm.errors.motif_annulation_detail"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ annulerForm.errors.motif_annulation_detail }}
                    </p>
                </div>
            </div>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <Button
                        variant="outline"
                        @click="annulerDialogVisible = false"
                        >Retour</Button
                    >
                    <Button
                        variant="destructive"
                        :disabled="annulerDisabled"
                        @click="submitAnnuler"
                    >
                        <XCircle class="mr-2 h-4 w-4" />
                        {{
                            annulerForm.processing
                                ? 'Annulation…'
                                : "Confirmer l'annulation"
                        }}
                    </Button>
                </div>
            </template>
        </Dialog>
    </AppLayout>
</template>
