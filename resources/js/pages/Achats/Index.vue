<script setup lang="ts">
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import ListPageActions from '@/components/ListPageActions.vue';
import QrCodeTicket from '@/components/print/QrCodeTicket.vue';
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
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useMediaQuery, watchDebounced } from '@vueuse/core';
import {
    ArrowLeft,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    MoreVertical,
    PackageCheck,
    Plus,
    Search,
    Trash2,
    X,
    XCircle,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Drawer from 'primevue/drawer';
import Textarea from 'primevue/textarea';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed, ref, watch } from 'vue';

interface Commande {
    id: string;
    reference: string;
    statut: string;
    statut_label: string;
    total_commande: number;
    fournisseur_nom: string | null;
    site_nom: string | null;
    site_payeur_nom: string | null;
    prochaine_action: string | null;
    statut_facture: string | null;
    statut_facture_label: string | null;
    created_at: string;
    date_achat: string;
    qte_commandee: number;
    qte_recue: number;
    is_annulee: boolean;
    supprimable: boolean;
    annulable: boolean;
    peut_valider: boolean;
}

interface Paginator<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
    last_page: number;
}

interface Option {
    value: string;
    label: string;
}

const props = defineProps<{
    commandes: Paginator<Commande>;
    filters: Record<string, unknown>;
    statuts: Option[];
    fournisseurs: Option[];
    sites: { id: string; nom: string }[];
    peut_creer: boolean;
}>();

const { can } = usePermissions();
const confirm = useConfirm();
const toast = useToast();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Achats', href: '/backoffice/achats' },
];

const filterFields = computed<FilterField[]>(() => {
    const fields: FilterField[] = [
        {
            key: 'statut',
            label: 'Statut',
            type: 'select',
            options: [
                { value: '', label: 'Tous les statuts' },
                ...props.statuts,
            ],
        },
        {
            key: 'recherche',
            label: 'Recherche',
            type: 'text',
            placeholder: 'Référence, fournisseur…',
        },
        {
            key: 'fournisseur_id',
            label: 'Fournisseur',
            type: 'select',
            searchable: true,
            options: [
                { value: '', label: 'Tous les fournisseurs' },
                ...props.fournisseurs,
            ],
        },
    ];
    if (can('achats.valider')) {
        fields.push({
            key: 'a_valider_par_moi',
            label: 'À valider par moi',
            type: 'boolean',
        });
    }
    return fields;
});

// Recherche rapide mobile : mêmes critères et même requête serveur que le drawer.
const mobileSearch = ref(String(props.filters.recherche ?? ''));
watch(
    () => props.filters.recherche,
    (value) => {
        mobileSearch.value = String(value ?? '');
    },
);
watchDebounced(
    mobileSearch,
    (value) => {
        const recherche = value.trim();
        if (recherche === String(props.filters.recherche ?? '').trim()) return;
        const params: Record<string, string | string[]> = {
            recherche,
            all: '1',
        };
        for (const [key, value] of Object.entries(props.filters)) {
            if (key === 'recherche' || value == null || value === '') continue;
            params[key] = Array.isArray(value)
                ? value.map(String)
                : typeof value === 'boolean'
                  ? value
                      ? '1'
                      : '0'
                  : String(value);
        }
        router.get('/backoffice/achats', params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    },
    { debounce: 350, maxWait: 1000 },
);

function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}

function paginationLabel(label: string): string {
    return label.replace(/&laquo;|&raquo;/g, '').trim();
}

// Prochaine action propre au bon (validation, réception), calculée par le serveur et affichée
// sous son statut — masquée quand elle ne ferait que le répéter (« À valider »). Tout ce qui
// relève de la facturation est dans la colonne « Statut facture ».
function actionAffichee(c: Commande): string | null {
    const action = c.prochaine_action;
    if (!action || action === c.statut_label || action === 'Annulé')
        return null;
    return action;
}

function ouvrir(c: Commande) {
    router.visit(`/backoffice/achats/${c.id}`);
}

// Consultation mobile : le panneau utilise les données déjà autorisées de la liste.
const isMobile = useMediaQuery('(max-width: 639px)');
const detailVisible = ref(false);
const selectedId = ref<string | null>(null);
const selected = computed(() =>
    props.commandes.data.find((c) => c.id === selectedId.value),
);
let lastDetailTrigger: HTMLButtonElement | null = null;

function ouvrirDetail(c: Commande, event: MouseEvent) {
    lastDetailTrigger = event.currentTarget as HTMLButtonElement;
    selectedId.value = c.id;
    detailVisible.value = true;
}

function restaurerFocusDetail() {
    if (isMobile.value && lastDetailTrigger?.isConnected)
        lastDetailTrigger.focus();
    selectedId.value = null;
}

watch([isMobile, selected], ([mobile, commande]) => {
    if (!mobile || !commande) detailVisible.value = false;
});

const detailRows = computed(() => {
    const c = selected.value;
    if (!c) return [];
    const rows = [
        { label: 'Nature', value: 'Bon de commande fournisseur' },
        { label: 'Date', value: c.date_achat },
        { label: 'Agence', value: c.site_nom ?? 'Sans agence' },
        { label: 'Commandé', value: String(c.qte_commandee) },
        { label: 'Reçu', value: String(c.qte_recue) },
    ];
    if (c.site_payeur_nom)
        rows.splice(3, 0, {
            label: 'Agence payeuse',
            value: c.site_payeur_nom,
        });
    const action = actionAffichee(c);
    if (action) rows.push({ label: 'Prochaine étape', value: action });
    return rows;
});

// Le scanner partagé reconnaît les URLs internes, contrairement au préfixe BC- seul.
const detailQrUrl = computed(() =>
    selected.value && typeof window !== 'undefined'
        ? new URL(
              '/backoffice/achats/' + selected.value.id,
              window.location.origin,
          ).href
        : '',
);

// ── Validation ────────────────────────────────────────────────────────────────
const aValider = ref<Commande | null>(null);
const validationEnCours = ref(false);

function fermerValider(valeur: boolean) {
    if (!valeur && validationEnCours.value) return;
    if (!valeur) aValider.value = null;
}

function valider() {
    if (!aValider.value) return;
    validationEnCours.value = true;
    router.patch(
        `/backoffice/achats/${aValider.value.id}/valider`,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                aValider.value = null;
                toast.add({
                    severity: 'success',
                    summary: 'Bon de commande validé',
                    life: 3000,
                });
            },
            onError: (errors) => {
                aValider.value = null;
                toast.add({
                    severity: 'error',
                    summary: 'Validation refusée',
                    detail: errors.validation ?? Object.values(errors)[0],
                    life: 7000,
                });
            },
            onFinish: () => {
                validationEnCours.value = false;
            },
        },
    );
}

// ── Annulation ────────────────────────────────────────────────────────────────
const annulerDialogVisible = ref(false);
const selectedCommande = ref<Commande | null>(null);
const annulerForm = useForm({ motif_annulation: '' });

function openAnnulerDialog(commande: Commande) {
    selectedCommande.value = commande;
    annulerForm.reset();
    annulerForm.clearErrors();
    annulerDialogVisible.value = true;
}

function submitAnnuler() {
    if (!selectedCommande.value) return;
    annulerForm.patch(
        `/backoffice/achats/${selectedCommande.value.id}/annuler`,
        {
            preserveScroll: true,
            onSuccess: () => {
                annulerDialogVisible.value = false;
                toast.add({
                    severity: 'success',
                    summary: 'Commande annulée',
                    life: 3000,
                });
            },
        },
    );
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
            router.delete(`/backoffice/achats/${c.id}`, {
                onSuccess: () =>
                    toast.add({
                        severity: 'success',
                        summary: 'Commande supprimée',
                        life: 3000,
                    }),
            });
        },
    });
}
</script>

<template>
    <Head title="Achats" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <div
            class="sticky top-0 z-10 flex flex-wrap items-center justify-between gap-3 border-b bg-background px-4 py-3 sm:static sm:border-b-0 sm:px-6 sm:pt-6 sm:pb-0"
        >
            <div class="flex min-w-0 items-center gap-3">
                <Link
                    href="/backoffice/dashboard"
                    aria-label="Retour au tableau de bord"
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:text-foreground sm:hidden"
                >
                    <ArrowLeft class="h-5 w-5" />
                </Link>
                <div class="min-w-0">
                    <h1
                        class="text-base font-semibold sm:text-2xl sm:tracking-tight"
                    >
                        <span class="sm:hidden">Achats</span>
                        <span class="hidden sm:inline"
                            >Bons de commande fournisseurs</span
                        >
                    </h1>
                    <p
                        class="mt-1 hidden text-sm text-muted-foreground sm:block"
                    >
                        {{ commandes.total }} bon{{
                            commandes.total !== 1 ? 's' : ''
                        }}
                        de commande
                    </p>
                </div>
            </div>
            <ListPageActions>
                <template #filters>
                    <div class="hidden flex-wrap items-center gap-2 sm:flex">
                        <DataFilters
                            trigger-only
                            url="/backoffice/achats"
                            saved-filter-scope="achats"
                            :values="filters"
                            :sites="sites"
                            :result-count="commandes.total"
                            :fields="filterFields"
                        />
                    </div>
                </template>
                <template #primary>
                    <Link v-if="peut_creer" href="/backoffice/achats/create">
                        <Button size="sm" class="h-9 px-3 sm:px-4">
                            <Plus class="mr-1 h-4 w-4 sm:mr-2" />
                            <span class="sm:hidden">Nouveau</span>
                            <span class="hidden sm:inline"
                                >Nouveau bon de commande</span
                            >
                        </Button>
                    </Link>
                </template>
            </ListPageActions>
        </div>

        <div
            class="flex min-w-0 flex-1 flex-col gap-4 bg-muted/20 p-4 sm:gap-6 sm:bg-background sm:p-6"
        >
            <p class="text-xs text-muted-foreground sm:hidden">
                {{ commandes.total }} bon{{ commandes.total !== 1 ? 's' : '' }}
                de commande
            </p>

            <!-- Recherche et vues : même position que sur la liste mobile des ventes. -->
            <div
                class="-mx-4 flex flex-wrap items-center gap-2 border-t border-b bg-background px-4 py-2 sm:hidden"
                aria-label="Recherche et vues des achats"
            >
                <div class="relative min-w-0 flex-1">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <input
                        v-model="mobileSearch"
                        type="text"
                        aria-label="Rechercher un achat"
                        placeholder="Référence, fournisseur…"
                        maxlength="255"
                        class="h-9 w-full rounded-md border border-input bg-background pr-3 pl-8 text-sm placeholder:text-muted-foreground focus:ring-1 focus:ring-ring focus:outline-none"
                    />
                </div>
                <DataFilters
                    trigger-only
                    url="/backoffice/achats"
                    saved-filter-scope="achats"
                    :values="filters"
                    :sites="sites"
                    :result-count="commandes.total"
                    :fields="filterFields"
                />
            </div>

            <!-- Tableau (desktop) -->
            <div
                class="hidden overflow-hidden overflow-x-auto rounded-xl border bg-card sm:block"
            >
                <table class="w-full text-sm">
                    <thead>
                        <tr
                            class="border-b bg-muted/40 text-left text-muted-foreground"
                        >
                            <th class="px-4 py-3 font-medium">Référence</th>
                            <th class="px-4 py-3 font-medium">Date</th>
                            <th class="px-4 py-3 font-medium">Fournisseur</th>
                            <th class="px-4 py-3 font-medium">Agence</th>
                            <th class="px-4 py-3 text-right font-medium">
                                Reçu / commandé
                            </th>
                            <th class="px-4 py-3 text-right font-medium">
                                Total
                            </th>
                            <th class="px-4 py-3 font-medium whitespace-nowrap">
                                Statut commande
                            </th>
                            <th class="px-4 py-3 font-medium whitespace-nowrap">
                                Statut facture
                            </th>
                            <th class="w-12 px-2 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="c in commandes.data"
                            :key="c.id"
                            class="cursor-pointer hover:bg-muted/20"
                            @click="ouvrir(c)"
                        >
                            <td class="px-4 py-3">
                                <span
                                    class="font-mono font-semibold tracking-wide"
                                    >{{ c.reference }}</span
                                >
                            </td>
                            <td
                                class="px-4 py-3 text-muted-foreground tabular-nums"
                            >
                                {{ c.date_achat }}
                            </td>
                            <td class="px-4 py-3">
                                {{ c.fournisseur_nom ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ c.site_nom ?? '—' }}
                                <span
                                    v-if="c.site_payeur_nom"
                                    class="block text-xs"
                                >
                                    Payé par {{ c.site_payeur_nom }}
                                </span>
                            </td>
                            <td
                                class="px-4 py-3 text-right text-muted-foreground tabular-nums"
                            >
                                {{ c.qte_recue }} / {{ c.qte_commandee }}
                            </td>
                            <td
                                class="px-4 py-3 text-right font-medium tabular-nums"
                            >
                                {{ formatGNF(c.total_commande) }}
                            </td>
                            <td class="px-4 py-3">
                                <StatusDot
                                    :status="c.statut"
                                    :label="c.statut_label"
                                    class="text-muted-foreground"
                                />
                                <span
                                    v-if="actionAffichee(c)"
                                    class="mt-0.5 block pl-4 text-xs text-muted-foreground"
                                >
                                    {{ actionAffichee(c) }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <StatusDot
                                    v-if="c.statut_facture"
                                    :status="c.statut_facture"
                                    :label="c.statut_facture_label ?? ''"
                                    class="text-muted-foreground"
                                />
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </td>
                            <td class="px-2 py-3" @click.stop>
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="h-8 w-8"
                                        >
                                            <MoreVertical class="h-4 w-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent
                                        align="end"
                                        class="w-44"
                                    >
                                        <DropdownMenuItem as-child>
                                            <Link
                                                :href="`/backoffice/achats/${c.id}`"
                                                class="flex w-full cursor-pointer items-center gap-2"
                                            >
                                                <PackageCheck class="h-4 w-4" />
                                                Voir
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            v-if="c.peut_valider"
                                            class="cursor-pointer text-emerald-600 focus:text-emerald-600"
                                            @click="aValider = c"
                                        >
                                            <CheckCircle2 class="h-4 w-4" />
                                            Valider
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            v-if="
                                                c.annulable &&
                                                can('achats.annuler')
                                            "
                                            class="cursor-pointer text-amber-600 focus:text-amber-600"
                                            @click="openAnnulerDialog(c)"
                                        >
                                            <XCircle class="h-4 w-4" />
                                            Annuler
                                        </DropdownMenuItem>
                                        <template
                                            v-if="
                                                c.supprimable &&
                                                can('achats.delete')
                                            "
                                        >
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem
                                                class="cursor-pointer text-destructive focus:text-destructive"
                                                @click="confirmDelete(c)"
                                            >
                                                <Trash2 class="h-4 w-4" />
                                                Supprimer
                                            </DropdownMenuItem>
                                        </template>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </td>
                        </tr>
                        <tr v-if="commandes.data.length === 0">
                            <td
                                colspan="9"
                                class="px-4 py-16 text-center text-muted-foreground"
                            >
                                <PackageCheck
                                    class="mx-auto mb-3 h-10 w-10 opacity-30"
                                />
                                Aucun bon de commande trouvé.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Cartes (mobile) : même présentation que la liste des ventes. -->
            <div class="space-y-2.5 sm:hidden" aria-label="Liste des achats">
                <button
                    v-for="c in commandes.data"
                    :key="c.id"
                    type="button"
                    aria-haspopup="dialog"
                    @click="ouvrirDetail(c, $event)"
                    :aria-label="`${c.reference}, ${c.statut_label}. Voir le bon de commande`"
                    class="block w-full rounded-2xl border border-border/60 bg-card p-3.5 text-left transition-colors hover:bg-muted/20 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none active:bg-muted/40"
                >
                    <div class="flex items-center gap-3">
                        <span
                            class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-muted/60 text-muted-foreground"
                            aria-hidden="true"
                        >
                            <PackageCheck class="size-5" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p
                                class="text-sm font-semibold break-words text-foreground"
                            >
                                {{ c.reference }}
                            </p>
                            <p
                                class="mt-1 text-xs break-words text-muted-foreground"
                            >
                                {{
                                    c.fournisseur_nom ??
                                    'Fournisseur non renseigné'
                                }}
                            </p>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                {{ c.site_nom ?? 'Sans agence' }}
                            </p>
                        </div>
                        <ChevronRight
                            class="size-4 shrink-0 text-muted-foreground/60"
                            aria-hidden="true"
                        />
                    </div>
                    <div
                        class="mt-3 flex items-start justify-between gap-3 border-t border-border/50 pt-2.5"
                    >
                        <div
                            class="min-w-0 flex-1 text-xs text-muted-foreground"
                        >
                            <p
                                class="text-sm font-semibold break-words text-foreground tabular-nums"
                            >
                                {{ formatGNF(c.total_commande) }}
                            </p>
                            <p class="mt-1 tabular-nums">{{ c.date_achat }}</p>
                            <p class="mt-1">
                                Reçu {{ c.qte_recue }} / {{ c.qte_commandee }}
                            </p>
                        </div>
                        <div
                            class="flex max-w-[55%] flex-col items-end gap-1.5 text-right"
                        >
                            <StatusDot
                                :status="c.statut"
                                :label="c.statut_label"
                                size="sm"
                                class="whitespace-normal"
                            />
                            <StatusDot
                                v-if="c.statut_facture"
                                :status="c.statut_facture"
                                :label="`Facture : ${c.statut_facture_label}`"
                                size="sm"
                                class="whitespace-normal text-muted-foreground"
                            />
                        </div>
                    </div>
                </button>
                <div
                    v-if="commandes.data.length === 0"
                    class="flex flex-col items-center gap-3 py-16 text-center text-muted-foreground"
                >
                    <PackageCheck
                        class="h-10 w-10 opacity-30"
                        aria-hidden="true"
                    />
                    <p class="text-sm">Aucun bon de commande trouvé.</p>
                </div>
            </div>

            <!-- Pagination -->
            <div
                v-if="commandes.last_page > 1"
                class="flex flex-wrap items-center justify-center gap-1"
            >
                <template v-for="link in commandes.links" :key="link.label">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        preserve-scroll
                        class="inline-flex h-9 min-w-9 items-center justify-center rounded-md border px-2 text-sm transition-colors hover:bg-muted"
                        :class="{
                            'border-primary bg-primary text-primary-foreground hover:bg-primary/90':
                                link.active,
                        }"
                    >
                        <ChevronLeft
                            v-if="link.label.includes('&laquo')"
                            class="h-4 w-4"
                        />
                        <ChevronRight
                            v-else-if="link.label.includes('&raquo')"
                            class="h-4 w-4"
                        />
                        <span v-else>{{ paginationLabel(link.label) }}</span>
                    </Link>
                </template>
            </div>
        </div>

        <Drawer
            v-model:visible="detailVisible"
            position="bottom"
            modal
            dismissable
            close-on-escape
            block-scroll
            :show-close-icon="false"
            class="achat-mobile-details"
            aria-labelledby="achat-mobile-detail-title"
            @after-hide="restaurerFocusDetail"
        >
            <template #header>
                <div class="w-full">
                    <div class="relative flex h-7 justify-center">
                        <div
                            class="h-1 w-10 rounded-full bg-muted-foreground/25"
                            aria-hidden="true"
                        />
                        <button
                            type="button"
                            class="absolute -top-2 -right-3 flex size-11 items-center justify-center rounded-full text-muted-foreground hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            aria-label="Fermer les détails"
                            autofocus
                            @click="detailVisible = false"
                        >
                            <X class="size-5" aria-hidden="true" />
                        </button>
                    </div>
                    <div class="flex items-start justify-between gap-3 pb-2">
                        <h2
                            id="achat-mobile-detail-title"
                            class="min-w-0 text-sm font-semibold break-words"
                        >
                            {{ selected?.reference }}
                        </h2>
                        <StatusDot
                            v-if="selected"
                            :status="selected.statut"
                            :label="selected.statut_label"
                            size="sm"
                            class="max-w-[48%] shrink-0 whitespace-normal"
                        />
                    </div>
                </div>
            </template>
            <div v-if="selected" class="space-y-5 pt-3">
                <div
                    role="group"
                    :aria-label="`QR code du bon de commande ${selected.reference}`"
                    class="rounded-2xl border border-border/60 p-4 text-center"
                >
                    <QrCodeTicket
                        :key="selected.id"
                        :url="detailQrUrl"
                        :size="176"
                        label=""
                    />
                    <p class="mt-1 text-xs text-muted-foreground">
                        Scannez pour identifier le bon de commande
                    </p>
                    <p class="mt-2 text-xs font-semibold">
                        {{ selected.reference }}
                    </p>
                </div>
                <div
                    class="flex items-center gap-3 rounded-2xl bg-muted/40 p-3"
                >
                    <span
                        class="flex size-16 shrink-0 items-center justify-center rounded-xl bg-muted text-muted-foreground"
                        aria-hidden="true"
                        ><PackageCheck class="size-6"
                    /></span>
                    <div class="min-w-0">
                        <p class="text-xs text-muted-foreground">Fournisseur</p>
                        <p class="mt-1 text-sm font-medium break-words">
                            {{
                                selected.fournisseur_nom ??
                                'Fournisseur non renseigné'
                            }}
                        </p>
                    </div>
                </div>
                <dl class="divide-y divide-border/60 text-sm">
                    <div
                        v-for="row in detailRows"
                        :key="row.label"
                        class="grid grid-cols-[6rem_minmax(0,1fr)] gap-4 py-3"
                    >
                        <dt class="text-muted-foreground">{{ row.label }}</dt>
                        <dd class="text-right font-medium break-words">
                            {{ row.value }}
                        </dd>
                    </div>
                </dl>
                <section
                    aria-label="Montants du bon de commande"
                    class="rounded-2xl border border-border/60 p-4"
                >
                    <div
                        class="flex flex-wrap items-baseline justify-between gap-2"
                    >
                        <h3 class="text-sm text-muted-foreground">
                            Total commande
                        </h3>
                        <p class="text-lg font-semibold tabular-nums">
                            {{ formatGNF(selected.total_commande) }}
                        </p>
                    </div>
                    <div
                        v-if="selected.statut_facture"
                        class="mt-3 border-t border-border/60 pt-3"
                    >
                        <p class="mb-2 text-xs text-muted-foreground">
                            Statut facture
                        </p>
                        <StatusDot
                            :status="selected.statut_facture"
                            :label="selected.statut_facture_label ?? ''"
                            size="sm"
                            class="whitespace-normal"
                        />
                    </div>
                </section>
            </div>
        </Drawer>

        <!-- Validation -->
        <Dialog
            :visible="aValider !== null"
            modal
            header="Valider le bon de commande"
            :closable="!validationEnCours"
            :style="{ width: '460px', maxWidth: '95vw' }"
            @update:visible="fermerValider"
        >
            <p v-if="aValider" class="text-sm text-muted-foreground">
                Valider
                <span class="font-mono font-semibold text-foreground">{{
                    aValider.reference
                }}</span>
                pour
                <span class="font-semibold text-foreground">{{
                    formatGNF(aValider.total_commande)
                }}</span>
                ? La commande ne sera plus modifiable et pourra être
                réceptionnée à {{ aValider.site_nom }}.
            </p>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <Button
                        variant="outline"
                        :disabled="validationEnCours"
                        @click="fermerValider(false)"
                        >Retour</Button
                    >
                    <Button :disabled="validationEnCours" @click="valider">
                        <i
                            v-if="validationEnCours"
                            class="pi pi-spin pi-spinner mr-2"
                        />
                        <CheckCircle2 v-else class="mr-2 h-4 w-4" />
                        Valider
                    </Button>
                </div>
            </template>
        </Dialog>

        <!-- Annulation -->
        <Dialog
            v-model:visible="annulerDialogVisible"
            modal
            header="Annuler la commande"
            :closable="!annulerForm.processing"
            :style="{ width: '480px', maxWidth: '95vw' }"
        >
            <div class="space-y-4">
                <p class="text-sm text-muted-foreground">
                    Annuler la commande
                    <span class="font-mono font-semibold">{{
                        selectedCommande?.reference
                    }}</span>
                    ? Cette action est irréversible.
                </p>
                <div>
                    <Label class="mb-1.5 block text-sm">
                        Motif d'annulation
                        <span class="text-destructive">*</span>
                    </Label>
                    <Textarea
                        v-model="annulerForm.motif_annulation"
                        rows="4"
                        class="w-full"
                        placeholder="Indiquez la raison de l'annulation..."
                        :invalid="!!annulerForm.errors.motif_annulation"
                    />
                    <p
                        v-if="annulerForm.errors.motif_annulation"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ annulerForm.errors.motif_annulation }}
                    </p>
                </div>
            </div>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <Button
                        variant="outline"
                        :disabled="annulerForm.processing"
                        @click="annulerDialogVisible = false"
                        >Retour</Button
                    >
                    <Button
                        variant="destructive"
                        :disabled="
                            annulerForm.processing ||
                            !annulerForm.motif_annulation.trim()
                        "
                        @click="submitAnnuler"
                    >
                        <i
                            v-if="annulerForm.processing"
                            class="pi pi-spin pi-spinner mr-2"
                        />
                        <XCircle v-else class="mr-2 h-4 w-4" />
                        Confirmer l'annulation
                    </Button>
                </div>
            </template>
        </Dialog>
    </AppLayout>
</template>

<style scoped>
:global(.achat-mobile-details.p-drawer.p-component) {
    width: 100%;
    max-width: 40rem;
    height: auto;
    max-height: 85dvh;
    margin: 0 auto;
    overflow: hidden;
    border-radius: 1.5rem 1.5rem 0 0;
}

:global(.achat-mobile-details .p-drawer-header) {
    padding: 0.75rem 1.25rem 0;
}

:global(.achat-mobile-details.p-drawer .p-drawer-content) {
    min-height: 0;
    height: auto;
    padding: 0 1.25rem calc(env(safe-area-inset-bottom) + 1.25rem);
    overscroll-behavior: contain;
}
</style>
