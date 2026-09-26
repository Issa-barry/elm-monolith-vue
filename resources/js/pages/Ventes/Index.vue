<script setup lang="ts">
import MobileVenteList from '@/components/commande-vente/MobileVenteList.vue';
import ProcessusBadge from '@/components/commande-vente/ProcessusBadge.vue';
import KpiCardsResponsive from '@/components/dashboard/shared/KpiCardsResponsive.vue';
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import FilterMultiSelect from '@/components/filters/FilterMultiSelect.vue';
import ListPageActions from '@/components/ListPageActions.vue';
import type {
    EncaissementPayload,
    MoyenEncaissement,
} from '@/components/payment/moyensEncaissement';
import PaymentCard from '@/components/payment/PaymentCard.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import { useClickableTableRow } from '@/composables/useClickableTableRow';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatGNF, formatQuantite } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import type { KpiWidgetItem } from '@/types/kpi-widgets';
import type { VenteMobile } from '@/types/vente-mobile';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CheckCircle,
    CircleAlert,
    Download,
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
import DatePicker from 'primevue/datepicker';
import Dialog from 'primevue/dialog';
import Select from 'primevue/select';
import Textarea from 'primevue/textarea';
import Tooltip from 'primevue/tooltip';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed, onMounted, ref, watch } from 'vue';

const vTooltip = Tooltip;

// ── Types ─────────────────────────────────────────────────────────────────────
interface Commande extends VenteMobile {
    nature_operation: 'vente_standard' | 'distribution_client';
    quantite_totale: number;
    processus_code: string;
    facture_id: number | null;
    /** Caisse dédiée active de l'utilisateur sur le site de la facture — sans elle, « Espèces »
     * est désactivé dans PaymentCard (cf. CaisseAgentResolver::garantirCaissePourEspeces()). */
    peut_encaisser_especes: boolean;
    /** Moyens hors espèces de l'agence de la facture (un par support actif). */
    moyens_encaissement: MoyenEncaissement[];
    encaissements: {
        id: number;
        montant: number;
        date_encaissement: string;
        heure: string | null;
        mode_paiement_label: string;
        operateur_mobile_money_label: string | null;
        reference_paiement: string | null;
        created_by: string | null;
    }[];
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

interface VehiculeOption {
    id: string;
    nom: string;
}

interface StatutOption {
    value: string;
    label: string;
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
    nature_filtree: 'vente_standard' | 'distribution_client';
    page_title: string;
    periode: string;
    statuts_actifs: string[];
    statuts: StatutOption[];
    sites: SiteOption[];
    vehicules: VehiculeOption[];
    is_admin: boolean;
    can_creer_commande: boolean;
    raison_blocage_commande: string | null;
    filters: Filters;
}>();

const { can } = usePermissions();
const confirm = useConfirm();
const toast = useToast();

// Accès direct à /backoffice/ventes/create bloqué (aucun stock disponible pour ce site) :
// Ventes\CreateCommandeVenteController / Ventes\StoreCommandeVenteController ne renvoient jamais une page 403, ils redirigent
// ici avec un flash 'error' (partagé globalement par HandleInertiaRequests) — affiché en toast
// top-right plutôt qu'en page d'erreur, cf. règle projet <Toast position="top-right">.
onMounted(() => {
    const flash = (usePage().props as { flash?: { error?: string } }).flash;
    if (flash?.error) {
        toast.add({ severity: 'error', summary: flash.error, life: 6000 });
    }
});

const { onRowClick, bodyRowPt } = useClickableTableRow<Commande>(
    (commande) => `/backoffice/ventes/${commande.id}`,
);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    {
        title: props.page_title,
        href:
            props.nature_filtree === 'distribution_client'
                ? '/backoffice/distributions'
                : '/backoffice/ventes',
    },
]);

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

const mobileKpiItems = computed<KpiWidgetItem[]>(() => [
    {
        id: 'ventes-total',
        title: 'Total',
        value: formatGNF(props.totaux.total_montant),
        subtitle: `${props.totaux.nb_total} commande${props.totaux.nb_total > 1 ? 's' : ''}`,
        valueClass: 'text-foreground tabular-nums',
    },
    {
        id: 'ventes-restant',
        title: 'Restant à encaisser',
        value: formatGNF(props.totaux.total_a_encaisser),
        valueClass: 'text-foreground tabular-nums',
    },
    {
        id: 'ventes-paye',
        title: 'Déjà payé',
        value: formatGNF(props.totaux.deja_paye),
        valueClass: 'text-foreground tabular-nums',
    },
]);

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
        placeholder: 'VTE-…, DST-…',
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
            (c.statut_affichage.label &&
                c.statut_affichage.label.toLowerCase().includes(q)) ||
            (c.facture_statut_label &&
                c.facture_statut_label.toLowerCase().includes(q)) ||
            (c.created_at && c.created_at.toLowerCase().includes(q)),
    );
});

// ── Export ────────────────────────────────────────────────────────────────────
// Colonnes proposées par ExportCommandeVenteController / VenteListExport (clés identiques
// des deux côtés). "Agence" est le seul intitulé retenu pour le site — pas de colonne "Site"
// distincte, cf. VenteListExport.
const EXPORT_COLUMNS = [
    { key: 'reference', label: 'Référence' },
    { key: 'date', label: 'Date' },
    { key: 'client', label: 'Client' },
    { key: 'vehicule', label: 'Véhicule' },
    { key: 'livreur', label: 'Livreur' },
    { key: 'agence', label: 'Agence' },
    { key: 'processus', label: 'Processus' },
    { key: 'montant', label: 'Montant' },
    { key: 'deja_paye', label: 'Déjà payé' },
    { key: 'reste', label: 'Reste à encaisser' },
    { key: 'statut', label: 'Statut' },
];

// Périodes rapides de la modale d'export — 'custom' seul affiche les deux calendriers Date
// début/fin librement éditables, toutes les autres calculent date_debut/date_fin côté client
// (semaine calée sur lundi, cf. firstDayOfWeek: 1 dans app.ts).
const PERIODE_OPTIONS = [
    { value: 'today', label: "Aujourd'hui" },
    { value: 'yesterday', label: 'Hier' },
    { value: 'week', label: 'Semaine en cours' },
    { value: 'last_week', label: 'Semaine dernière' },
    { value: 'month', label: 'Mois en cours' },
    { value: 'last_month', label: 'Mois dernier' },
    { value: 'last_3_months', label: '3 derniers mois' },
    { value: 'last_6_months', label: '6 derniers mois' },
    { value: 'year', label: 'Année en cours' },
    { value: 'last_year', label: 'Année dernière' },
    { value: 'custom', label: 'Choisir une période' },
];

const exportDialogVisible = ref(false);
const exportPeriode = ref('today');
const exportDateDebut = ref('');
const exportDateFin = ref('');
const exportSiteIds = ref<string[]>([]);
const exportVehiculeIds = ref<string[]>([]);
const exportStatuts = ref<string[]>([]);
const exportColumns = ref<string[]>(EXPORT_COLUMNS.map((c) => c.key));
const exportFormat = ref<'xlsx' | 'csv'>('xlsx');

function pad2(n: number): string {
    return String(n).padStart(2, '0');
}

function toIsoDate(d: Date): string {
    return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;
}

function addDays(d: Date, n: number): Date {
    const date = new Date(d);
    date.setDate(date.getDate() + n);
    return date;
}

// Lundi de la semaine contenant `d` (getDay() : 0=dimanche..6=samedi).
function startOfWeekMonday(d: Date): Date {
    const day = d.getDay();
    return addDays(d, day === 0 ? -6 : 1 - day);
}

/** Calcule [date_debut, date_fin] (chaînes ISO) pour une période rapide — 'custom' exclu. */
function computePeriodeRange(preset: string): { debut: string; fin: string } {
    const today = new Date();

    switch (preset) {
        case 'today':
            return { debut: toIsoDate(today), fin: toIsoDate(today) };
        case 'yesterday': {
            const hier = addDays(today, -1);
            return { debut: toIsoDate(hier), fin: toIsoDate(hier) };
        }
        case 'week':
            return {
                debut: toIsoDate(startOfWeekMonday(today)),
                fin: toIsoDate(today),
            };
        case 'last_week': {
            const finDerniere = addDays(startOfWeekMonday(today), -1);
            const debutDerniere = addDays(finDerniere, -6);
            return {
                debut: toIsoDate(debutDerniere),
                fin: toIsoDate(finDerniere),
            };
        }
        case 'month':
            return {
                debut: toIsoDate(
                    new Date(today.getFullYear(), today.getMonth(), 1),
                ),
                fin: toIsoDate(today),
            };
        case 'last_month':
            return {
                debut: toIsoDate(
                    new Date(today.getFullYear(), today.getMonth() - 1, 1),
                ),
                fin: toIsoDate(
                    new Date(today.getFullYear(), today.getMonth(), 0),
                ),
            };
        case 'last_3_months':
            return {
                debut: toIsoDate(
                    new Date(
                        today.getFullYear(),
                        today.getMonth() - 3,
                        today.getDate(),
                    ),
                ),
                fin: toIsoDate(today),
            };
        case 'last_6_months':
            return {
                debut: toIsoDate(
                    new Date(
                        today.getFullYear(),
                        today.getMonth() - 6,
                        today.getDate(),
                    ),
                ),
                fin: toIsoDate(today),
            };
        case 'year':
            return {
                debut: toIsoDate(new Date(today.getFullYear(), 0, 1)),
                fin: toIsoDate(today),
            };
        case 'last_year':
            return {
                debut: toIsoDate(new Date(today.getFullYear() - 1, 0, 1)),
                fin: toIsoDate(new Date(today.getFullYear() - 1, 11, 31)),
            };
        default:
            return { debut: '', fin: '' };
    }
}

// 'custom' laisse exportDateDebut/exportDateFin tels quels (édition libre via les deux
// Calendar) ; toute autre valeur recalcule et écrase les deux dates.
watch(exportPeriode, (preset) => {
    if (preset === 'custom') return;
    const { debut, fin } = computePeriodeRange(preset);
    exportDateDebut.value = debut;
    exportDateFin.value = fin;
});

// Calendar (PrimeVue) travaille en Date, nos refs restent des chaînes ISO (format envoyé au
// serveur) — mêmes conversions que Packings/Show.vue.
function toDate(val: string): Date | null {
    if (!val) return null;
    const d = new Date(val);
    return isNaN(d.getTime()) ? null : d;
}

function fromDate(val: Date | null): string {
    return val ? toIsoDate(val) : '';
}

const exportTitle = computed(() =>
    props.nature_filtree === 'distribution_client'
        ? 'Export des distributions'
        : 'Export des ventes',
);

// ventes.export / distributions.export : même contrôleur, filtré par nom de route (cf.
// ExportCommandeVenteController) — jamais un paramètre client, comme pour ventes.index.
const exportUrl = computed(() =>
    props.nature_filtree === 'distribution_client'
        ? '/backoffice/distributions/export'
        : '/backoffice/ventes/export',
);

const siteFilterOptions = computed(() =>
    props.sites.map((s) => ({ value: s.id, label: s.nom })),
);

const vehiculeFilterOptions = computed(() =>
    props.vehicules.map((v) => ({ value: v.id, label: v.nom })),
);

const statutFilterOptions = computed(() =>
    props.statuts.map((s) => ({ value: s.value, label: s.label })),
);

function openExportDialog() {
    // Période : toujours "Aujourd'hui" par défaut à l'ouverture — calculé directement ici (pas
    // seulement via le watcher sur exportPeriode, qui ne se déclenche pas si la valeur ne change
    // pas d'un ouverture à l'autre). Les autres filtres restent pré-remplis avec ce qui est déjà
    // appliqué à la page.
    exportPeriode.value = 'today';
    const { debut, fin } = computePeriodeRange('today');
    exportDateDebut.value = debut;
    exportDateFin.value = fin;
    exportSiteIds.value = [...(props.filters.site_ids ?? [])];
    exportVehiculeIds.value = [];
    exportStatuts.value = [...props.statuts_actifs];
    exportColumns.value = EXPORT_COLUMNS.map((c) => c.key);
    exportFormat.value = 'xlsx';
    exportDialogVisible.value = true;
}

function submitExport() {
    const params = new URLSearchParams();
    if (exportDateDebut.value) params.set('date_debut', exportDateDebut.value);
    if (exportDateFin.value) params.set('date_fin', exportDateFin.value);
    exportSiteIds.value.forEach((id) => params.append('site_ids[]', id));
    exportVehiculeIds.value.forEach((id) =>
        params.append('vehicule_ids[]', id),
    );
    exportStatuts.value.forEach((s) => params.append('statuts[]', s));
    exportColumns.value.forEach((c) => params.append('columns[]', c));
    params.set('format', exportFormat.value);

    // Navigation native (pas router.get d'Inertia) : le serveur répond en Content-Disposition
    // attachment, le navigateur télécharge le fichier sans quitter la page — même mécanisme que
    // le lien <a href="/backoffice/vehicules/export"> de Vehicules/Index.vue.
    window.location.href = `${exportUrl.value}?${params.toString()}`;
    exportDialogVisible.value = false;
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
];

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
// Un seul choix "mode de paiement" côté UI, porté par PaymentCard : espèces + les moyens que les
// supports de trésorerie actifs de l'agence de la facture peuvent recevoir (`moyens_encaissement`,
// fourni par le backend — jamais une liste fixe, cf. docs/encaissements.md).
const encaisserDialogVisible = ref(false);
const encaisserCommande = ref<Commande | null>(null);
const encaisserProcessing = ref(false);
const encaisserErrors = ref<Record<string, string>>({});

const encaisserInfoRows = computed(() =>
    encaisserCommande.value
        ? [
              { label: 'Commande', value: encaisserCommande.value.reference },
              {
                  label: 'Montant total',
                  value: formatGNF(encaisserCommande.value.total_commande),
              },
          ]
        : [],
);

function openEncaisserDialog(commande: Commande) {
    encaisserCommande.value = commande;
    encaisserErrors.value = {};
    encaisserDialogVisible.value = true;
}

function submitEncaisser(payload: EncaissementPayload) {
    if (!encaisserCommande.value?.facture_id) return;
    encaisserProcessing.value = true;
    encaisserErrors.value = {};
    router.post(
        `/backoffice/factures/${encaisserCommande.value.facture_id}/encaissements`,
        payload,
        {
            preserveScroll: true,
            onSuccess: () => {
                encaisserDialogVisible.value = false;
                toast.add({
                    severity: 'success',
                    summary: 'Encaissement enregistré',
                    detail: `${formatGNF(payload.montant)} enregistré avec succès.`,
                    life: 3000,
                });
            },
            onError: (e) => {
                encaisserErrors.value = e as Record<string, string>;
            },
            onFinish: () => {
                encaisserProcessing.value = false;
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
    <Head :title="page_title" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- ── MOBILE VIEW ─────────────────────────────────────────────────── -->
        <div class="flex min-w-0 flex-1 flex-col bg-muted/20 sm:hidden">
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
                    v-if="can('ventes.create') && can_creer_commande"
                    href="/backoffice/ventes/create"
                >
                    <Button size="sm" class="h-8 px-3 text-xs">
                        <Plus class="mr-1 h-3.5 w-3.5" />
                        Nouveau
                    </Button>
                </Link>
                <Button
                    v-else-if="can('ventes.create')"
                    size="sm"
                    class="h-8 px-3 text-xs"
                    disabled
                    v-tooltip.bottom="raison_blocage_commande"
                >
                    <Plus class="mr-1 h-3.5 w-3.5" />
                    Nouveau
                </Button>
                <div v-else class="w-8" />
            </div>

            <div
                v-if="can('ventes.create') && !can_creer_commande"
                role="status"
                class="mx-4 mt-3 flex items-start gap-2.5 rounded-xl border border-amber-200/80 bg-amber-50/80 px-3 py-2.5 text-amber-900 shadow-sm dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-100"
            >
                <span
                    class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-amber-500/10 text-amber-700 dark:text-amber-300"
                >
                    <CircleAlert class="h-4 w-4" />
                </span>
                <div>
                    <p class="text-xs font-semibold">
                        Création de commande indisponible
                    </p>
                    <p
                        class="mt-0.5 text-xs leading-5 text-amber-800/85 dark:text-amber-200/85"
                    >
                        Aucun produit vendable n'est disponible pour votre
                        agence. Réapprovisionnez le stock pour continuer.
                    </p>
                </div>
            </div>

            <!-- KPI cards -->
            <div class="relative min-w-0 p-4">
                <KpiCardsResponsive
                    :items="mobileKpiItems"
                    breakpoint="sm"
                    mobile-slide-width-class="w-full min-w-full max-w-full"
                />
            </div>

            <!-- Search + Filtres -->
            <div class="flex items-center gap-2 border-t border-b px-4 py-2">
                <div class="relative flex-1">
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
                <DataFilters
                    trigger-only
                    url="/backoffice/ventes"
                    :base-params="{ periode: 'all' }"
                    :values="filterValues"
                    :sites="sites"
                    :result-count="commandesFiltrees.length"
                    :fields="filterFields"
                />
            </div>

            <!-- Card list -->
            <MobileVenteList
                v-if="mobileFiltered.length"
                :commandes="mobileFiltered"
            />

            <!-- Empty state -->
            <div
                v-if="mobileFiltered.length === 0"
                class="flex flex-col items-center gap-3 py-16 text-muted-foreground"
            >
                <ShoppingCart class="h-10 w-10 opacity-30" />
                <p class="text-sm">Aucune commande trouvée.</p>
                <Link
                    v-if="can('ventes.create') && can_creer_commande"
                    href="/backoffice/ventes/create"
                >
                    <Button variant="outline" size="sm">
                        <Plus class="mr-2 h-4 w-4" />
                        Créer la première commande
                    </Button>
                </Link>
                <Button
                    v-else-if="can('ventes.create')"
                    variant="outline"
                    size="sm"
                    disabled
                    v-tooltip.top="raison_blocage_commande"
                >
                    <Plus class="mr-2 h-4 w-4" />
                    Créer la première commande
                </Button>
                <div
                    v-if="can('ventes.create') && !can_creer_commande"
                    role="status"
                    class="flex max-w-sm items-start gap-2.5 rounded-xl border border-amber-200/80 bg-amber-50/80 p-3 text-left text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-100"
                >
                    <CircleAlert
                        class="mt-0.5 h-4 w-4 shrink-0 text-amber-700 dark:text-amber-300"
                    />
                    <p class="text-xs leading-5">
                        Aucun produit vendable n'est disponible pour votre
                        agence. Réapprovisionnez le stock pour créer une
                        commande.
                    </p>
                </div>
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
                <div class="flex flex-col items-end gap-2">
                    <ListPageActions>
                        <template v-if="can('ventes.exporter')" #export>
                            <Button variant="outline" @click="openExportDialog">
                                <Download class="mr-2 h-4 w-4" />
                                Exporter
                            </Button>
                        </template>
                        <template #filters>
                            <DataFilters
                                trigger-only
                                url="/backoffice/ventes"
                                :base-params="{ periode: 'all' }"
                                :values="filterValues"
                                :sites="sites"
                                :result-count="commandesFiltrees.length"
                                :fields="filterFields"
                            />
                        </template>
                        <template #primary>
                            <Link
                                v-if="
                                    can('ventes.create') && can_creer_commande
                                "
                                href="/backoffice/ventes/create"
                            >
                                <Button>
                                    <Plus class="mr-2 h-4 w-4" />
                                    Nouvelle commande
                                </Button>
                            </Link>
                            <Button
                                v-else-if="can('ventes.create')"
                                disabled
                                v-tooltip.left="raison_blocage_commande"
                            >
                                <Plus class="mr-2 h-4 w-4" />
                                Nouvelle commande
                            </Button>
                        </template>
                    </ListPageActions>
                    <div
                        v-if="can('ventes.create') && !can_creer_commande"
                        role="status"
                        class="flex max-w-md items-start gap-2.5 rounded-xl border border-amber-200/80 bg-amber-50/80 p-3 text-left text-amber-900 shadow-sm dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-100"
                    >
                        <span
                            class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-amber-500/10 text-amber-700 dark:text-amber-300"
                        >
                            <CircleAlert class="h-4 w-4" />
                        </span>
                        <div>
                            <p class="text-xs font-semibold">
                                Création de commande indisponible
                            </p>
                            <p
                                class="mt-0.5 text-xs leading-5 text-amber-800/85 dark:text-amber-200/85"
                            >
                                Aucun produit vendable n'est disponible pour
                                votre agence. Réapprovisionnez le stock pour
                                continuer.
                            </p>
                        </div>
                    </div>
                </div>
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

                    <!-- Quantité -->
                    <Column
                        field="quantite_totale"
                        header="Qté"
                        sortable
                        style="width: 90px"
                    >
                        <template #body="{ data }">
                            <span class="tabular-nums">{{
                                formatQuantite(data.quantite_totale)
                            }}</span>
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

                    <!-- Processus -->
                    <Column
                        field="processus_label"
                        header="Processus"
                        sortable
                        style="width: 150px"
                    >
                        <template #body="{ data }">
                            <ProcessusBadge
                                :processus="data.processus_code"
                                :label="data.processus_label"
                            />
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
                                :status="data.statut_affichage.value"
                                :label="data.statut_affichage.label"
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
                                v-if="
                                    can('ventes.create') && can_creer_commande
                                "
                                href="/backoffice/ventes/create"
                            >
                                <Button variant="outline" size="sm">
                                    <Plus class="mr-2 h-4 w-4" />
                                    Créer la première commande
                                </Button>
                            </Link>
                            <Button
                                v-else-if="can('ventes.create')"
                                variant="outline"
                                size="sm"
                                disabled
                                v-tooltip.top="raison_blocage_commande"
                            >
                                <Plus class="mr-2 h-4 w-4" />
                                Créer la première commande
                            </Button>
                            <div
                                v-if="
                                    can('ventes.create') && !can_creer_commande
                                "
                                role="status"
                                class="flex max-w-sm items-start gap-2.5 rounded-xl border border-amber-200/80 bg-amber-50/80 p-3 text-left text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-100"
                            >
                                <CircleAlert
                                    class="mt-0.5 h-4 w-4 shrink-0 text-amber-700 dark:text-amber-300"
                                />
                                <p class="text-xs leading-5">
                                    Aucun produit vendable n'est disponible pour
                                    votre agence. Réapprovisionnez le stock pour
                                    créer une commande.
                                </p>
                            </div>
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
            :style="{ width: '880px', maxWidth: '95vw' }"
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
                                class="hidden px-3 py-2 text-left font-medium text-muted-foreground sm:table-cell"
                            >
                                Référence
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
                                {{
                                    e.operateur_mobile_money_label ??
                                    e.mode_paiement_label
                                }}
                            </td>
                            <td
                                class="hidden px-3 py-2 text-muted-foreground sm:table-cell"
                            >
                                {{ e.reference_paiement ?? '—' }}
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
                                colspan="4"
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
        <PaymentCard
            v-model:visible="encaisserDialogVisible"
            title="Encaisser un paiement"
            :solde="encaisserCommande?.facture_montant_restant ?? 0"
            :moyens="encaisserCommande?.moyens_encaissement ?? []"
            :especes-disponibles="
                encaisserCommande?.peut_encaisser_especes ?? true
            "
            :info-rows="encaisserInfoRows"
            :processing="encaisserProcessing"
            :errors="encaisserErrors"
            @submit="submitEncaisser"
        />

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

        <!-- Dialog Export -->
        <Dialog
            v-model:visible="exportDialogVisible"
            modal
            :header="exportTitle"
            :style="{ width: '560px' }"
        >
            <div class="space-y-5">
                <!-- Période -->
                <div>
                    <Label class="mb-1.5 block text-sm">Période</Label>
                    <Select
                        v-model="exportPeriode"
                        :options="PERIODE_OPTIONS"
                        option-label="label"
                        option-value="value"
                        class="w-full"
                        fluid
                    />
                    <div
                        v-if="exportPeriode === 'custom'"
                        class="mt-2 grid grid-cols-2 gap-2"
                    >
                        <div>
                            <Label
                                for="export-date-debut"
                                class="mb-1 block text-xs text-muted-foreground"
                                >Date de début</Label
                            >
                            <DatePicker
                                input-id="export-date-debut"
                                :model-value="toDate(exportDateDebut)"
                                @update:model-value="
                                    exportDateDebut = fromDate(
                                        $event as Date | null,
                                    )
                                "
                                date-format="dd/mm/yy"
                                show-icon
                                fluid
                            />
                        </div>
                        <div>
                            <Label
                                for="export-date-fin"
                                class="mb-1 block text-xs text-muted-foreground"
                                >Date de fin</Label
                            >
                            <DatePicker
                                input-id="export-date-fin"
                                :model-value="toDate(exportDateFin)"
                                @update:model-value="
                                    exportDateFin = fromDate(
                                        $event as Date | null,
                                    )
                                "
                                date-format="dd/mm/yy"
                                show-icon
                                fluid
                            />
                        </div>
                    </div>
                </div>

                <!-- Agence -->
                <div v-if="sites.length > 0">
                    <Label class="mb-1.5 block text-sm">Agence</Label>
                    <FilterMultiSelect
                        v-model="exportSiteIds"
                        :options="siteFilterOptions"
                        placeholder="Toutes les agences"
                        empty-means-all
                    />
                </div>

                <!-- Véhicules -->
                <div v-if="vehicules.length > 0">
                    <Label class="mb-1.5 block text-sm">Véhicules</Label>
                    <FilterMultiSelect
                        v-model="exportVehiculeIds"
                        :options="vehiculeFilterOptions"
                        placeholder="Tous les véhicules"
                        empty-means-all
                    />
                </div>

                <!-- Statut -->
                <div>
                    <Label class="mb-1.5 block text-sm">Statut</Label>
                    <FilterMultiSelect
                        v-model="exportStatuts"
                        :options="statutFilterOptions"
                        placeholder="Tous les statuts"
                        empty-means-all
                    />
                </div>

                <!-- Colonnes -->
                <div>
                    <Label class="mb-1.5 block text-sm"
                        >Colonnes à exporter</Label
                    >
                    <div class="grid grid-cols-2 gap-x-4 gap-y-1.5">
                        <label
                            v-for="col in EXPORT_COLUMNS"
                            :key="col.key"
                            class="flex items-center gap-2 text-sm"
                        >
                            <input
                                v-model="exportColumns"
                                type="checkbox"
                                :value="col.key"
                                class="h-4 w-4 rounded border-input"
                            />
                            {{ col.label }}
                        </label>
                    </div>
                </div>

                <!-- Format -->
                <div>
                    <Label class="mb-1.5 block text-sm">Format</Label>
                    <div class="flex items-center gap-4">
                        <label class="flex items-center gap-2 text-sm">
                            <input
                                v-model="exportFormat"
                                type="radio"
                                value="xlsx"
                                class="h-4 w-4 border-input"
                            />
                            Excel (.xlsx)
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input
                                v-model="exportFormat"
                                type="radio"
                                value="csv"
                                class="h-4 w-4 border-input"
                            />
                            CSV
                        </label>
                    </div>
                </div>
            </div>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <Button
                        variant="outline"
                        @click="exportDialogVisible = false"
                        >Annuler</Button
                    >
                    <Button
                        :disabled="exportColumns.length === 0"
                        @click="submitExport"
                    >
                        <Download class="mr-2 h-4 w-4" />
                        Exporter
                    </Button>
                </div>
            </template>
        </Dialog>
    </AppLayout>
</template>
