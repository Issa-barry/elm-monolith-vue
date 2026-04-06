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
import InputText from 'primevue/inputtext';
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
    facture_statut: string | null;
    facture_statut_label: string | null;
    facture_montant_restant: number | null;
    created_at: string;
    is_annulee: boolean;
    is_brouillon: boolean;
    is_en_cours: boolean;
    can_modifier: boolean;
    can_valider: boolean;
    can_annuler: boolean;
}

// ── Props ─────────────────────────────────────────────────────────────────────
const props = defineProps<{ commandes: Commande[] }>();

const { can } = usePermissions();
const confirm = useConfirm();
const toast = useToast();

const search = ref('');
const filters = ref({ global: { value: '', matchMode: 'contains' } });
watch(search, (val) => {
    filters.value.global.value = val;
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Ventes', href: '/ventes' },
];

// ── Statut couleurs ───────────────────────────────────────────────────────────
const statutCommandeColor: Record<string, string> = {
    brouillon: 'bg-zinc-400 dark:bg-zinc-500',
    en_cours:  'bg-blue-500',
    cloturee:  'bg-emerald-500',
    annulee:   'bg-red-400',
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

// ── Validation ────────────────────────────────────────────────────────────────
const validationProcessing = ref(false);

function valider(commande: Commande) {
    if (validationProcessing.value) return;
    validationProcessing.value = true;
    router.patch(`/ventes/${commande.id}/valider`, {}, {
        onSuccess: () =>
            toast.add({ severity: 'success', summary: 'Validée', detail: 'Commande validée, facture créée.', life: 3000 }),
        onFinish: () => (validationProcessing.value = false),
    });
}

// ── Annulation ────────────────────────────────────────────────────────────────
const annulerDialogVisible = ref(false);
const selectedCommande = ref<Commande | null>(null);

const annulerForm = useForm({
    motif_annulation: '',
});

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

            <!-- Search -->
            <div class="border-b px-4 py-2">
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
                            {{ c.client_nom ?? '—' }}
                        </p>
                        <p class="mt-1 text-sm font-medium tabular-nums">
                            {{ formatGNF(c.total_commande) }}
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
                        {{ commandes.length }} commande{{
                            commandes.length !== 1 ? 's' : ''
                        }}
                    </p>
                </div>
                <Link v-if="can('ventes.create')" href="/ventes/create">
                    <Button>
                        <Plus class="mr-2 h-4 w-4" />
                        Nouvelle commande
                    </Button>
                </Link>
            </div>

            <!-- Tableau -->
            <div class="overflow-hidden rounded-xl border bg-card">
                <DataTable
                    :value="commandes"
                    :paginator="commandes.length > 20"
                    :rows="20"
                    :global-filter-fields="[
                        'reference',
                        'vehicule_nom',
                        'client_nom',
                        'statut_label',
                        'facture_statut_label',
                    ]"
                    v-model:filters="filters"
                    data-key="id"
                    striped-rows
                    removable-sort
                    class="text-sm"
                    table-class="w-full"
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
                                    placeholder="Rechercher..."
                                    class="w-full text-sm"
                                />
                            </IconField>
                            <span class="text-xs text-muted-foreground"
                                >{{ commandes.length }} résultat{{
                                    commandes.length !== 1 ? 's' : ''
                                }}</span
                            >
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
                                class="font-mono text-sm font-semibold tracking-wide hover:underline"
                            >
                                {{ data.reference }}
                            </Link>
                        </template>
                    </Column>

                    <!-- Date -->
                    <Column
                        field="created_at"
                        header="Date"
                        sortable
                        style="width: 120px"
                    >
                        <template #body="{ data }">
                            <span class="text-muted-foreground tabular-nums">{{
                                data.created_at
                            }}</span>
                        </template>
                    </Column>

                    <!-- Véhicule -->
                    <Column
                        field="vehicule_nom"
                        header="Véhicule"
                        style="min-width: 150px"
                    >
                        <template #body="{ data }">
                            <span class="text-muted-foreground">{{
                                data.vehicule_nom ?? '—'
                            }}</span>
                        </template>
                    </Column>

                    <!-- Client -->
                    <Column
                        field="client_nom"
                        header="Client"
                        style="min-width: 150px"
                    >
                        <template #body="{ data }">
                            <span class="text-muted-foreground">{{
                                data.client_nom ?? '—'
                            }}</span>
                        </template>
                    </Column>

                    <!-- Total -->
                    <Column
                        field="total_commande"
                        header="Total"
                        sortable
                        style="width: 160px"
                    >
                        <template #body="{ data }">
                            <span class="font-medium tabular-nums">{{
                                formatGNF(data.total_commande)
                            }}</span>
                        </template>
                    </Column>

                    <!-- Restant dû -->
                    <Column
                        field="facture_montant_restant"
                        header="Restant dû"
                        sortable
                        style="width: 150px"
                    >
                        <template #body="{ data }">
                            <span
                                v-if="data.facture_montant_restant !== null"
                                class="font-medium tabular-nums"
                                :class="
                                    data.facture_montant_restant > 0
                                        ? 'text-amber-600 dark:text-amber-400'
                                        : 'text-emerald-600 dark:text-emerald-400'
                                "
                            >
                                {{ formatGNF(data.facture_montant_restant) }}
                            </span>
                            <span v-else class="text-muted-foreground">—</span>
                        </template>
                    </Column>

                    <!-- Statut commande -->
                    <Column
                        field="statut"
                        header="Statut cmde"
                        sortable
                        style="width: 130px"
                    >
                        <template #body="{ data }">
                            <StatusDot
                                :label="data.statut_label"
                                :dot-class="
                                    statutCommandeColor[data.statut] ??
                                    'bg-zinc-400 dark:bg-zinc-500'
                                "
                                class="text-muted-foreground"
                            />
                        </template>
                    </Column>

                    <!-- Statut facture -->
                    <Column
                        field="facture_statut"
                        header="Statut facture"
                        sortable
                        style="width: 130px"
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
                                                Voir
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

                    <!-- État vide -->
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
