<script setup lang="ts">
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
import {
    ArrowLeft,
    CheckCircle,
    ChevronRight,
    HandCoins,
    History,
    MoreVertical,
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
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import Textarea from 'primevue/textarea';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed, ref, watch } from 'vue';

// ── Types ─────────────────────────────────────────────────────────────────────
interface Commande {
    id: number;
    reference: string;
    statut: string;
    statut_label: string;
    total_commande: number;
    vehicule_nom: string | null;
    client_nom: string | null;
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
    is_en_cours: boolean;
    can_modifier: boolean;
    can_valider: boolean;
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

// ── Props ─────────────────────────────────────────────────────────────────────
const props = defineProps<{
    commandes: Commande[];
    totaux: Totaux;
    periode: string;
    statut: string;
}>();

const { can } = usePermissions();
const confirm = useConfirm();
const toast = useToast();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Ventes', href: '/ventes' },
];

// ── Filtres période ───────────────────────────────────────────────────────────
const periodes = [
    { value: 'today', label: "Aujourd'hui" },
    { value: 'week', label: 'Cette semaine' },
    { value: 'month', label: 'Ce mois' },
    { value: 'all', label: 'Tout' },
];

function setPeriode(p: string) {
    const params: Record<string, string> = { periode: p };
    if (props.statut !== 'tous') params.statut = props.statut;
    router.get('/ventes', params, { preserveScroll: true, replace: true });
}

// ── Filtres statut (server-driven) ────────────────────────────────────────────
const filtresStatut = [
    { value: 'tous', label: 'Toutes' },
    { value: 'brouillon', label: 'Brouillons' },
    { value: 'en_cours', label: 'En cours' },
    { value: 'cloturee', label: 'Clôturées' },
    { value: 'annulee', label: 'Annulées' },
];

function setStatut(s: string) {
    const params: Record<string, string> = { periode: props.periode };
    if (s !== 'tous') params.statut = s;
    router.get('/ventes', params, { preserveScroll: true, replace: true });
}

// ── Recherche locale ──────────────────────────────────────────────────────────
const search = ref('');
const filters = ref({ global: { value: '', matchMode: 'contains' } });
watch(search, (val) => {
    filters.value.global.value = val;
});

const commandesFiltrees = computed(() => {
    const q = search.value.toLowerCase().trim();
    if (!q) return props.commandes;
    return props.commandes.filter(
        (c) =>
            c.reference.toLowerCase().includes(q) ||
            (c.vehicule_nom && c.vehicule_nom.toLowerCase().includes(q)) ||
            (c.client_nom && c.client_nom.toLowerCase().includes(q)),
    );
});

// ── Filtre mobile ─────────────────────────────────────────────────────────────
const mobileSearch = ref('');

const mobileFiltered = computed(() => {
    const q = mobileSearch.value.toLowerCase().trim();
    if (!q) return props.commandes;
    return props.commandes.filter(
        (c) =>
            c.reference.toLowerCase().includes(q) ||
            (c.client_nom && c.client_nom.toLowerCase().includes(q)),
    );
});

// ── Couleurs statut ───────────────────────────────────────────────────────────
const statutCommandeColor: Record<string, string> = {
    brouillon: 'bg-zinc-400 dark:bg-zinc-500',
    en_cours: 'bg-blue-500',
    cloturee: 'bg-emerald-500',
    annulee: 'bg-red-400',
};

const statutFactureColor: Record<string, string> = {
    impayee: 'bg-amber-500',
    partiel: 'bg-blue-500',
    payee: 'bg-emerald-500',
    annulee: 'bg-zinc-400 dark:bg-zinc-500',
};

// ── Formatage ─────────────────────────────────────────────────────────────────
function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}

// ── Validation ────────────────────────────────────────────────────────────────
const validationProcessing = ref(false);

function valider(commande: Commande) {
    if (validationProcessing.value) return;
    validationProcessing.value = true;
    router.patch(
        `/ventes/${commande.id}/valider`,
        {},
        {
            onSuccess: () =>
                toast.add({
                    severity: 'success',
                    summary: 'Validée',
                    detail: 'Commande validée, facture créée.',
                    life: 3000,
                }),
            onFinish: () => (validationProcessing.value = false),
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
    annulerDialogVisible.value = true;
}

function submitAnnuler() {
    if (!selectedCommande.value) return;
    annulerForm.patch(`/ventes/${selectedCommande.value.id}/annuler`, {
        onSuccess: () => {
            annulerDialogVisible.value = false;
            toast.add({
                severity: 'success',
                summary: 'Annulée',
                detail: 'Commande annulée avec succès.',
                life: 3000,
            });
        },
    });
}

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
        `/factures/${encaisserCommande.value.facture_id}/encaissements`,
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
            router.delete(`/ventes/${c.id}`, {
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
                    href="/dashboard"
                    class="flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft class="h-5 w-5" />
                </Link>
                <span class="text-base font-semibold">Ventes</span>
                <Link v-if="can('ventes.create')" href="/ventes/create">
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
                    :href="`/ventes/${c.id}`"
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
                                :label="c.statut_label"
                                :dot-class="
                                    statutCommandeColor[c.statut] ??
                                    'bg-zinc-400 dark:bg-zinc-500'
                                "
                                class="text-xs text-muted-foreground"
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
                <Link v-if="can('ventes.create')" href="/ventes/create">
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
                <Link v-if="can('ventes.create')" href="/ventes/create">
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

            <!-- Tableau -->
            <div class="overflow-hidden rounded-xl border bg-card">
                <DataTable
                    :value="commandesFiltrees"
                    :paginator="commandesFiltrees.length > 20"
                    :rows="20"
                    data-key="id"
                    striped-rows
                    removable-sort
                    class="text-sm"
                    :pt="{
                        root: { class: 'w-full' },
                        header: { class: 'border-b bg-muted/30 px-4 py-3' },
                        tbody: { class: 'divide-y' },
                    }"
                >
                    <template #header>
                        <div class="flex items-center gap-3">
                            <IconField class="max-w-sm flex-1">
                                <InputIcon class="pointer-events-none">
                                    <Search
                                        class="h-4 w-4 text-muted-foreground"
                                    />
                                </InputIcon>
                                <InputText
                                    v-model="search"
                                    placeholder="Référence, véhicule, client…"
                                    class="w-full text-sm"
                                />
                            </IconField>
                            <Select
                                :model-value="statut"
                                :options="filtresStatut"
                                option-label="label"
                                option-value="value"
                                class="w-36"
                                @update:model-value="setStatut($event)"
                            />
                            <Select
                                :model-value="periode"
                                :options="periodes"
                                option-label="label"
                                option-value="value"
                                class="w-40"
                                @update:model-value="setPeriode($event)"
                            />
                            <span class="text-xs text-muted-foreground">
                                {{ commandesFiltrees.length }} résultat{{
                                    commandesFiltrees.length !== 1 ? 's' : ''
                                }}
                            </span>
                        </div>
                    </template>

                    <!-- Référence -->
                    <Column
                        field="reference"
                        header="Référence"
                        sortable
                        style="min-width: 180px"
                    >
                        <template #body="{ data }">
                            <Link
                                :href="`/ventes/${data.id}`"
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

                    <!-- Véhicule / Client -->
                    <Column header="Véhicule / Client" style="min-width: 160px">
                        <template #body="{ data }">
                            <div v-if="data.vehicule_nom" class="font-medium">
                                {{ data.vehicule_nom }}
                            </div>
                            <div
                                v-if="data.client_nom"
                                class="text-muted-foreground"
                                :class="{ 'text-xs': data.vehicule_nom }"
                            >
                                {{ data.client_nom }}
                            </div>
                            <span
                                v-if="!data.vehicule_nom && !data.client_nom"
                                class="text-muted-foreground"
                                >—</span
                            >
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
                            <span class="text-muted-foreground">{{
                                data.site_nom ?? '—'
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

                    <!-- Encaissé -->
                    <Column
                        field="facture_montant_encaisse"
                        header="Encaissé"
                        sortable
                        style="width: 140px"
                    >
                        <template #body="{ data }">
                            <span class="text-muted-foreground tabular-nums">
                                {{
                                    data.facture_montant_encaisse !== null
                                        ? formatGNF(
                                              data.facture_montant_encaisse,
                                          )
                                        : '—'
                                }}
                            </span>
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

                    <!-- Statut facture -->
                    <Column
                        field="facture_statut"
                        header="Statut"
                        sortable
                        style="width: 120px"
                    >
                        <template #body="{ data }">
                            <StatusDot
                                v-if="data.facture_statut"
                                :label="data.facture_statut_label ?? '—'"
                                :dot-class="
                                    statutFactureColor[data.facture_statut] ??
                                    'bg-zinc-400 dark:bg-zinc-500'
                                "
                                class="text-muted-foreground"
                            />
                            <span v-else class="text-muted-foreground">—</span>
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
                                            <MoreVertical class="h-4 w-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent
                                        align="end"
                                        class="w-44"
                                    >
                                        <DropdownMenuItem as-child>
                                            <Link
                                                :href="`/ventes/${data.id}`"
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
                                                :href="`/ventes/${data.id}/edit`"
                                                class="flex w-full cursor-pointer items-center gap-2"
                                            >
                                                <Pencil class="h-4 w-4" />
                                                Modifier
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            v-if="data.can_valider"
                                            class="cursor-pointer text-blue-600 focus:text-blue-600"
                                            :disabled="validationProcessing"
                                            @click="valider(data)"
                                        >
                                            <CheckCircle class="h-4 w-4" />
                                            Valider
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
                                href="/ventes/create"
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
                    <Label class="mb-1.5 block text-sm">
                        Motif d'annulation
                        <span class="text-destructive">*</span>
                    </Label>
                    <Textarea
                        v-model="annulerForm.motif_annulation"
                        rows="4"
                        class="w-full"
                        placeholder="Indiquez la raison de l'annulation..."
                        :class="{
                            'p-invalid': annulerForm.errors.motif_annulation,
                        }"
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
