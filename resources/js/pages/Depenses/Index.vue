<script setup lang="ts">
import AuditDrawer from '@/components/AuditDrawer.vue';
import ConcerneDetailDialog from '@/components/Depenses/ConcerneDetailDialog.vue';
import VehiculeDetailDialog from '@/components/Depenses/VehiculeDetailDialog.vue';
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
import { formatPhoneDisplay } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Check,
    ChevronLeft,
    ChevronRight,
    Download,
    ExternalLink,
    Eye,
    History,
    MoreHorizontal,
    Pencil,
    Plus,
    Printer,
    Receipt,
    Send,
    Trash2,
    X,
} from 'lucide-vue-next';
import { useToast } from 'primevue/usetoast';
import { computed, ref } from 'vue';

interface Option {
    value: string;
    label: string;
}

interface DepenseRow {
    id: string;
    montant: number;
    date_depense: string;
    statut: string;
    statut_label: string;
    commentaire: string | null;
    type: {
        id: string;
        libelle: string;
        categorie: string;
        categorie_label: string;
    } | null;
    beneficiaire_type: string | null;
    beneficiaire_id: string | null;
    beneficiaire_label: string | null;
    beneficiaire_telephone: string | null;
    vehicule_id: string | null;
    vehicule_nom: string | null;
    vehicule_immatriculation: string | null;
    site: { id: string; nom: string } | null;
    user: { id: string; name: string };
    validateur: { id: string; name: string } | null;
}

interface Paginator {
    data: DepenseRow[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    total: number;
}

interface TypeOption {
    id: string;
    libelle: string;
    categorie: string;
}

const props = defineProps<{
    depenses: Paginator;
    types: TypeOption[];
    sites: { id: string; nom: string }[];
    categories: Option[];
    statuts: Option[];
    filters: {
        search?: string;
        type?: string;
        statut?: string;
        categorie?: string;
        site_ids?: string[];
        date_debut?: string;
        date_fin?: string;
        vehicule?: string;
        concerne?: string;
        montant?: string;
    };
    stats: {
        total: number;
        montant_total: number;
        en_attente: number;
        validees: number;
    };
    can_create: boolean;
}>();

const { can } = usePermissions();
const toast = useToast();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Dépenses', href: '/depenses' },
];

const filterSearch = ref(props.filters.search ?? '');

function currentParams() {
    return {
        search: filterSearch.value || undefined,
        type: props.filters.type || undefined,
        statut: props.filters.statut || undefined,
        categorie: props.filters.categorie || undefined,
        date_debut: props.filters.date_debut || undefined,
        date_fin: props.filters.date_fin || undefined,
        vehicule: props.filters.vehicule || undefined,
        concerne: props.filters.concerne || undefined,
        montant: props.filters.montant || undefined,
    };
}

const filterValues = computed(() => ({
    search: props.filters.search ?? '',
    type: props.filters.type ?? '',
    statut: props.filters.statut ?? '',
    categorie: props.filters.categorie ?? '',
    site_ids: props.filters.site_ids ?? [],
    date_debut: props.filters.date_debut ?? '',
    date_fin: props.filters.date_fin ?? '',
    vehicule: props.filters.vehicule ?? '',
    concerne: props.filters.concerne ?? '',
    montant: props.filters.montant ?? '',
}));

const filterFields = computed<FilterField[]>(() => [
    {
        key: 'type',
        label: 'Type de dépense',
        type: 'select',
        options: [
            { value: '', label: 'Tous les types' },
            ...props.types.map((t) => ({ value: t.id, label: t.libelle })),
        ],
    },
    {
        key: 'categorie',
        label: 'Concerné',
        type: 'select',
        options: [
            { value: '', label: 'Tous les concernés' },
            ...props.categories.map((c) => ({
                value: c.value,
                label: c.label,
            })),
        ],
    },
    {
        key: 'statut',
        label: 'Statut',
        type: 'select',
        options: [
            { value: '', label: 'Tous les statuts' },
            ...props.statuts.map((s) => ({ value: s.value, label: s.label })),
        ],
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
    },
    {
        key: 'concerne',
        label: 'Recherche concerné',
        type: 'text',
        placeholder: 'Nom, prénom ou téléphone…',
    },
    {
        key: 'montant',
        label: 'Montant exact (GNF)',
        type: 'number',
        placeholder: '0',
    },
]);

function buildExportParams(): URLSearchParams {
    const params = new URLSearchParams();
    const p = currentParams();
    Object.entries(p).forEach(([k, v]) => {
        if (v) params.set(k, v);
    });
    (props.filters.site_ids ?? []).forEach((id) => {
        params.append('site_ids[]', id);
    });
    return params;
}

function exportExcel() {
    const params = buildExportParams();
    window.location.href = `/depenses/export/excel?${params.toString()}`;
}

function imprimer() {
    const params = buildExportParams();
    window.open(`/depenses/imprimer?${params.toString()}`, '_blank');
}

// ── Popup concerné ──────────────────────────────────────────────────────────
const showConcerneDialog = ref(false);
const popupConcerneType = ref<string | null>(null);
const popupConcerneId = ref<string | null>(null);

function openConcerneDialog(d: DepenseRow) {
    if (
        !d.beneficiaire_id ||
        !d.beneficiaire_type ||
        d.beneficiaire_type === 'vehicule'
    )
        return;
    popupConcerneType.value = d.beneficiaire_type;
    popupConcerneId.value = d.beneficiaire_id;
    showConcerneDialog.value = true;
}

// ── Popup véhicule ──────────────────────────────────────────────────────────
const showVehiculeDialog = ref(false);
const popupVehiculeId = ref<string | null>(null);

function openVehiculeDialog(d: DepenseRow) {
    if (!d.vehicule_id) return;
    popupVehiculeId.value = d.vehicule_id;
    showVehiculeDialog.value = true;
}

// ── Rejet ──────────────────────────────────────────────────────────────────
const rejectingDepenseId = ref<string | null>(null);
const rejectMotif = ref('');
const rejectCommentaire = ref('');
const rejectErrors = ref<{ motif?: string; commentaire?: string }>({});
const rejectProcessing = ref(false);

const showAudit = ref(false);
const auditDepenseId = ref('');

function openAudit(id: string) {
    auditDepenseId.value = id;
    showAudit.value = true;
}

function soumettre(id: string) {
    router.patch(
        `/depenses/${id}/soumettre`,
        {},
        {
            preserveScroll: true,
            onSuccess: () =>
                toast.add({
                    severity: 'success',
                    summary: 'Soumise',
                    detail: 'Dépense soumise pour validation.',
                    life: 3000,
                }),
        },
    );
}

function valider(id: string) {
    router.patch(
        `/depenses/${id}/valider`,
        {},
        {
            preserveScroll: true,
            onSuccess: () =>
                toast.add({
                    severity: 'success',
                    summary: 'Validée',
                    detail: 'Dépense validée avec succès.',
                    life: 3000,
                }),
        },
    );
}

function rejeter(id: string) {
    rejectingDepenseId.value = id;
    rejectMotif.value = '';
    rejectCommentaire.value = '';
    rejectErrors.value = {};
}

function fermerModalRejet() {
    if (rejectProcessing.value) return;
    rejectingDepenseId.value = null;
    rejectMotif.value = '';
    rejectCommentaire.value = '';
    rejectErrors.value = {};
}

function confirmerRejet() {
    rejectErrors.value = {};

    if (!rejectMotif.value) {
        rejectErrors.value.motif = 'Le motif de rejet est obligatoire.';
        return;
    }
    if (rejectMotif.value === 'Autre') {
        const trim = rejectCommentaire.value.trim();
        if (!trim) {
            rejectErrors.value.commentaire =
                'Le commentaire est obligatoire pour le motif "Autre".';
            return;
        }
        if (trim.length < 5) {
            rejectErrors.value.commentaire =
                'Le commentaire doit faire au moins 5 caractères.';
            return;
        }
    }

    rejectProcessing.value = true;
    router.patch(
        `/depenses/${rejectingDepenseId.value}/rejeter`,
        {
            motif_rejet: rejectMotif.value,
            commentaire_rejet:
                rejectMotif.value === 'Autre'
                    ? rejectCommentaire.value.trim()
                    : null,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                rejectingDepenseId.value = null;
                toast.add({
                    severity: 'warn',
                    summary: 'Rejetée',
                    detail: 'Dépense rejetée.',
                    life: 3000,
                });
            },
            onError: (errors) => {
                if (errors.motif_rejet)
                    rejectErrors.value.motif = errors.motif_rejet;
                if (errors.commentaire_rejet)
                    rejectErrors.value.commentaire = errors.commentaire_rejet;
            },
            onFinish: () => {
                rejectProcessing.value = false;
            },
        },
    );
}

function destroy(id: string) {
    if (!confirm('Supprimer cette dépense en brouillon ?')) return;
    router.delete(`/depenses/${id}`, { preserveScroll: true });
}

function fmt(n: number) {
    return (
        n.toLocaleString('fr-FR', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0,
        }) + ' GNF'
    );
}

function formatPaginationLabel(label: string) {
    const el = document.createElement('div');
    el.innerHTML = label;
    return el.textContent?.trim() ?? label.trim();
}

const statutVariant: Record<
    string,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    brouillon: 'secondary',
    soumis: 'outline',
    valide: 'default',
    rejete: 'destructive',
    annule: 'destructive',
};

const statutColors: Record<string, string> = {
    brouillon: '',
    soumis: 'border-blue-400 text-blue-700',
    valide: 'bg-emerald-100 text-emerald-700 border-emerald-300',
    rejete: 'bg-red-100 text-red-700 border-red-300',
    annule: '',
};

const categorieColors: Record<string, string> = {
    interne: 'bg-slate-100 text-slate-600',
    employe: 'bg-blue-100 text-blue-700',
    livreur: 'bg-amber-100 text-amber-700',
    proprietaire: 'bg-purple-100 text-purple-700',
    vehicule: 'bg-green-100 text-green-700',
};
</script>

<template>
    <Head title="Dépenses" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <div class="flex flex-col gap-6 p-4 sm:p-6">
            <!-- En-tête -->
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Dépenses
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ depenses.total }} dépense{{
                            depenses.total !== 1 ? 's' : ''
                        }}
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <Button variant="outline" size="sm" @click="exportExcel">
                        <Download class="mr-1.5 h-3.5 w-3.5" />
                        Excel
                    </Button>
                    <Button variant="outline" size="sm" @click="imprimer">
                        <Printer class="mr-1.5 h-3.5 w-3.5" />
                        Imprimer
                    </Button>
                    <Link v-if="props.can_create" href="/depenses/create">
                        <Button>
                            <Plus class="mr-2 h-4 w-4" />
                            Nouvelle dépense
                        </Button>
                    </Link>
                </div>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div class="rounded-xl border bg-card p-5">
                    <p class="text-sm text-muted-foreground">Total dépenses</p>
                    <p class="mt-1 text-3xl font-bold">{{ stats.total }}</p>
                </div>
                <div class="rounded-xl border bg-card p-5">
                    <p class="text-sm text-muted-foreground">Montant total</p>
                    <p class="mt-1 text-xl font-bold tabular-nums">
                        {{ fmt(stats.montant_total) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5">
                    <p class="text-sm text-muted-foreground">
                        En attente de validation
                    </p>
                    <p class="mt-1 text-3xl font-bold text-blue-500">
                        {{ stats.en_attente }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5">
                    <p class="text-sm text-muted-foreground">Validées</p>
                    <p class="mt-1 text-3xl font-bold text-emerald-500">
                        {{ stats.validees }}
                    </p>
                </div>
            </div>

            <!-- Filtres -->
            <DataFilters
                url="/depenses"
                :values="filterValues"
                :sites="sites"
                :result-count="depenses.total"
                :fields="filterFields"
                search-key="search"
                search-placeholder="Rechercher…"
                v-model:search="filterSearch"
            />

            <!-- Tableau -->
            <div class="overflow-hidden rounded-xl border bg-card">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b bg-muted/40">
                            <th
                                class="px-4 py-2.5 text-left font-medium text-muted-foreground"
                            >
                                Date
                            </th>
                            <th
                                class="px-4 py-2.5 text-left font-medium text-muted-foreground"
                            >
                                Type
                            </th>
                            <th
                                class="px-4 py-2.5 text-left font-medium text-muted-foreground"
                            >
                                Concerné
                            </th>
                            <th
                                class="hidden px-4 py-2.5 text-left font-medium text-muted-foreground lg:table-cell"
                            >
                                Véhicule
                            </th>
                            <th
                                class="px-4 py-2.5 text-right font-medium text-muted-foreground"
                            >
                                Montant
                            </th>
                            <th
                                class="px-4 py-2.5 text-center font-medium text-muted-foreground"
                            >
                                Statut
                            </th>
                            <th
                                class="hidden px-4 py-2.5 text-left font-medium text-muted-foreground lg:table-cell"
                            >
                                Site
                            </th>
                            <th
                                class="hidden px-4 py-2.5 text-left font-medium text-muted-foreground xl:table-cell"
                            >
                                Saisi par
                            </th>
                            <th
                                class="px-4 py-2.5 text-right font-medium text-muted-foreground"
                            >
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="d in depenses.data"
                            :key="d.id"
                            class="border-b transition-colors last:border-b-0 hover:bg-muted/20"
                        >
                            <!-- Date -->
                            <td
                                class="px-4 py-3 text-sm whitespace-nowrap text-muted-foreground tabular-nums"
                            >
                                {{ d.date_depense }}
                            </td>

                            <!-- Type -->
                            <td class="px-4 py-3">
                                <div class="font-medium">
                                    {{ d.type?.libelle ?? '—' }}
                                </div>
                                <span
                                    v-if="d.type"
                                    class="mt-0.5 inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="
                                        categorieColors[d.type.categorie] ??
                                        'bg-muted text-muted-foreground'
                                    "
                                >
                                    {{ d.type.categorie_label }}
                                </span>
                                <div
                                    v-if="d.commentaire"
                                    class="mt-0.5 truncate text-xs text-muted-foreground"
                                    style="max-width: 200px"
                                >
                                    {{ d.commentaire }}
                                </div>
                            </td>

                            <!-- Concerné -->
                            <td class="px-4 py-3">
                                <button
                                    v-if="
                                        d.beneficiaire_id &&
                                        d.beneficiaire_type !== 'vehicule'
                                    "
                                    type="button"
                                    class="text-left"
                                    @click="openConcerneDialog(d)"
                                >
                                    <div
                                        class="flex items-center gap-1 font-medium hover:underline"
                                    >
                                        {{ d.beneficiaire_label ?? '—' }}
                                        <ExternalLink
                                            class="h-3 w-3 shrink-0 text-primary"
                                        />
                                    </div>
                                    <div
                                        v-if="d.beneficiaire_telephone"
                                        class="mt-0.5 text-xs text-muted-foreground"
                                    >
                                        {{
                                            formatPhoneDisplay(
                                                d.beneficiaire_telephone,
                                            )
                                        }}
                                    </div>
                                </button>
                                <div v-else>
                                    <div class="text-sm font-medium">
                                        {{ d.beneficiaire_label ?? '—' }}
                                    </div>
                                    <div
                                        v-if="d.beneficiaire_telephone"
                                        class="mt-0.5 text-xs text-muted-foreground"
                                    >
                                        {{
                                            formatPhoneDisplay(
                                                d.beneficiaire_telephone,
                                            )
                                        }}
                                    </div>
                                </div>
                            </td>

                            <!-- Véhicule -->
                            <td class="hidden px-4 py-3 lg:table-cell">
                                <button
                                    v-if="d.vehicule_id"
                                    type="button"
                                    class="text-left"
                                    @click="openVehiculeDialog(d)"
                                >
                                    <div
                                        class="flex items-center gap-1 text-sm font-medium hover:underline"
                                    >
                                        {{ d.vehicule_nom ?? '—' }}
                                        <ExternalLink
                                            class="h-3 w-3 shrink-0 text-primary"
                                        />
                                    </div>
                                    <div
                                        v-if="d.vehicule_immatriculation"
                                        class="mt-0.5 font-mono text-xs text-muted-foreground"
                                    >
                                        {{ d.vehicule_immatriculation }}
                                    </div>
                                </button>
                                <span
                                    v-else
                                    class="text-xs text-muted-foreground"
                                    >—</span
                                >
                            </td>

                            <!-- Montant -->
                            <td
                                class="px-4 py-3 text-right font-mono font-medium whitespace-nowrap"
                            >
                                {{ fmt(d.montant) }}
                            </td>

                            <!-- Statut -->
                            <td class="px-4 py-3 text-center">
                                <Badge
                                    :variant="
                                        statutVariant[d.statut] ?? 'secondary'
                                    "
                                    :class="statutColors[d.statut]"
                                >
                                    {{ d.statut_label }}
                                </Badge>
                            </td>

                            <!-- Site -->
                            <td
                                class="hidden px-4 py-3 text-xs text-muted-foreground lg:table-cell"
                            >
                                {{ d.site?.nom ?? '—' }}
                            </td>

                            <!-- Saisi par -->
                            <td
                                class="hidden px-4 py-3 text-xs text-muted-foreground xl:table-cell"
                            >
                                {{ d.user.name }}
                            </td>

                            <!-- Actions -->
                            <td class="px-4 py-3">
                                <div class="flex justify-end">
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                class="h-8 w-8"
                                                aria-label="Actions"
                                            >
                                                <MoreHorizontal
                                                    class="h-4 w-4"
                                                />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent
                                            align="end"
                                            class="w-48"
                                        >
                                            <!-- Voir -->
                                            <DropdownMenuItem as-child>
                                                <Link
                                                    :href="`/depenses/${d.id}`"
                                                    class="flex w-full items-center gap-2"
                                                >
                                                    <Eye class="h-4 w-4" />
                                                    Voir le détail
                                                </Link>
                                            </DropdownMenuItem>

                                            <!-- Historique -->
                                            <DropdownMenuItem
                                                class="cursor-pointer"
                                                @click="openAudit(d.id)"
                                            >
                                                <History class="h-4 w-4" />
                                                Historique
                                            </DropdownMenuItem>

                                            <!-- Modifier (brouillon, rejeté ou annulé) -->
                                            <DropdownMenuItem
                                                v-if="
                                                    [
                                                        'brouillon',
                                                        'rejete',
                                                        'annule',
                                                    ].includes(d.statut) &&
                                                    can('depenses.update')
                                                "
                                                as-child
                                            >
                                                <Link
                                                    :href="`/depenses/${d.id}/edit`"
                                                    class="flex w-full items-center gap-2"
                                                >
                                                    <Pencil class="h-4 w-4" />
                                                    Modifier
                                                </Link>
                                            </DropdownMenuItem>

                                            <DropdownMenuSeparator />

                                            <!-- Soumettre (brouillon) -->
                                            <DropdownMenuItem
                                                v-if="d.statut === 'brouillon'"
                                                class="cursor-pointer"
                                                @click="soumettre(d.id)"
                                            >
                                                <Send class="h-4 w-4" />
                                                Soumettre
                                            </DropdownMenuItem>

                                            <!-- Valider (soumis + permission) -->
                                            <DropdownMenuItem
                                                v-if="
                                                    d.statut === 'soumis' &&
                                                    can('depenses.update')
                                                "
                                                class="cursor-pointer text-emerald-700 focus:text-emerald-700"
                                                @click="valider(d.id)"
                                            >
                                                <Check class="h-4 w-4" />
                                                Valider
                                            </DropdownMenuItem>

                                            <!-- Rejeter (soumis + permission) -->
                                            <DropdownMenuItem
                                                v-if="
                                                    d.statut === 'soumis' &&
                                                    can('depenses.update')
                                                "
                                                class="cursor-pointer text-destructive focus:text-destructive"
                                                @click="rejeter(d.id)"
                                            >
                                                <X class="h-4 w-4" />
                                                Rejeter
                                            </DropdownMenuItem>

                                            <DropdownMenuSeparator
                                                v-if="
                                                    d.statut === 'brouillon' &&
                                                    can('depenses.delete')
                                                "
                                            />

                                            <!-- Supprimer (brouillon seulement) -->
                                            <DropdownMenuItem
                                                v-if="
                                                    d.statut === 'brouillon' &&
                                                    can('depenses.delete')
                                                "
                                                class="cursor-pointer text-destructive focus:text-destructive"
                                                @click="destroy(d.id)"
                                            >
                                                <Trash2 class="h-4 w-4" />
                                                Supprimer
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>
                            </td>
                        </tr>

                        <tr v-if="depenses.data.length === 0">
                            <td
                                colspan="9"
                                class="px-4 py-16 text-center text-sm text-muted-foreground"
                            >
                                <div class="flex flex-col items-center gap-3">
                                    <Receipt class="h-12 w-12 opacity-20" />
                                    <p>Aucune dépense enregistrée.</p>
                                    <Link
                                        v-if="props.can_create"
                                        href="/depenses/create"
                                    >
                                        <Button variant="outline" size="sm">
                                            <Plus class="mr-2 h-4 w-4" />
                                            Enregistrer une dépense
                                        </Button>
                                    </Link>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div
                v-if="depenses.last_page > 1"
                class="flex items-center justify-center gap-1"
            >
                <template v-for="link in depenses.links" :key="link.label">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        class="inline-flex h-8 min-w-[2rem] items-center justify-center rounded-md border px-2 text-sm transition-colors hover:bg-muted"
                        :class="{
                            'border-primary bg-primary text-primary-foreground hover:bg-primary/90':
                                link.active,
                        }"
                    >
                        <ChevronLeft
                            v-if="
                                link.label.includes('Précédent') ||
                                link.label.includes('&laquo')
                            "
                            class="h-4 w-4"
                        />
                        <ChevronRight
                            v-else-if="
                                link.label.includes('Suivant') ||
                                link.label.includes('&raquo')
                            "
                            class="h-4 w-4"
                        />
                        <span v-else>{{
                            formatPaginationLabel(link.label)
                        }}</span>
                    </Link>
                    <span
                        v-else
                        class="inline-flex h-8 min-w-[2rem] items-center justify-center rounded-md border px-2 text-sm opacity-40"
                    >
                        <ChevronLeft
                            v-if="
                                link.label.includes('Précédent') ||
                                link.label.includes('&laquo')
                            "
                            class="h-4 w-4"
                        />
                        <ChevronRight
                            v-else-if="
                                link.label.includes('Suivant') ||
                                link.label.includes('&raquo')
                            "
                            class="h-4 w-4"
                        />
                        <span v-else>{{
                            formatPaginationLabel(link.label)
                        }}</span>
                    </span>
                </template>
            </div>
        </div>

        <!-- Reject dialog -->
        <Dialog
            :open="!!rejectingDepenseId"
            @update:open="
                (v: boolean) => {
                    if (!v) fermerModalRejet();
                }
            "
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle
                        class="flex items-center gap-2 text-destructive"
                    >
                        <AlertTriangle class="h-5 w-5" />
                        Rejeter la dépense
                    </DialogTitle>
                </DialogHeader>

                <div class="space-y-4 py-2">
                    <!-- Motif -->
                    <div class="space-y-1.5">
                        <Label for="idx-reject-motif">
                            Motif de rejet
                            <span class="text-destructive">*</span>
                        </Label>
                        <select
                            id="idx-reject-motif"
                            v-model="rejectMotif"
                            class="h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            <option value="" disabled>
                                Sélectionner un motif…
                            </option>
                            <option value="Non conforme">Non conforme</option>
                            <option value="Autre">Autre</option>
                        </select>
                        <p
                            v-if="rejectErrors.motif"
                            class="text-sm text-destructive"
                        >
                            {{ rejectErrors.motif }}
                        </p>
                    </div>

                    <!-- Commentaire (Autre seulement) -->
                    <div v-if="rejectMotif === 'Autre'" class="space-y-1.5">
                        <Label for="idx-reject-commentaire">
                            Commentaire <span class="text-destructive">*</span>
                        </Label>
                        <textarea
                            id="idx-reject-commentaire"
                            v-model="rejectCommentaire"
                            rows="3"
                            placeholder="Veuillez préciser le motif du rejet…"
                            class="flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        />
                        <p
                            v-if="rejectErrors.commentaire"
                            class="text-sm text-destructive"
                        >
                            {{ rejectErrors.commentaire }}
                        </p>
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        variant="outline"
                        :disabled="rejectProcessing"
                        @click="fermerModalRejet"
                    >
                        Annuler
                    </Button>
                    <Button
                        variant="destructive"
                        :disabled="rejectProcessing"
                        @click="confirmerRejet"
                    >
                        <span v-if="rejectProcessing">Rejet en cours…</span>
                        <span v-else>Rejeter la dépense</span>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Popup concerné -->
        <ConcerneDetailDialog
            v-model:visible="showConcerneDialog"
            :beneficiaire-type="popupConcerneType"
            :beneficiaire-id="popupConcerneId"
        />

        <!-- Popup véhicule -->
        <VehiculeDetailDialog
            v-model:visible="showVehiculeDialog"
            :vehicule-id="popupVehiculeId"
        />

        <AuditDrawer
            v-model:visible="showAudit"
            title="Historique de la dépense"
            auditable-type="App\Models\Depense"
            :auditable-id="auditDepenseId"
            module="depenses"
        />
    </AppLayout>
</template>
