<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Layers,
    MoreVertical,
    Pencil,
    Plus,
    Search,
    Trash2,
    XCircle,
    Eye,
} from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputText from 'primevue/inputtext';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { ref, watch } from 'vue';

// ── Props ─────────────────────────────────────────────────────────────────────
interface Packing {
    id: number;
    reference: string;
    prestataire_id: number;
    prestataire_nom: string | null;
    date: string;
    nb_rouleaux: number;
    prix_par_rouleau: number;
    montant: number;
    montant_verse: number;
    montant_restant: number;
    statut: string;
    statut_label: string;
    notes: string | null;
    can_edit: boolean;
    can_cancel: boolean;
}

const props = defineProps<{ packings: Packing[] }>();

const { can } = usePermissions();
const confirm = useConfirm();
const toast = useToast();

const search = ref('');
const filters = ref({ global: { value: '', matchMode: 'contains' } });
watch(search, (val) => { filters.value.global.value = val; });

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Packings', href: '/packings' },
];

// ── Badges statut ─────────────────────────────────────────────────────────────
const statutColor: Record<string, string> = {
    impayee:   'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
    partielle: 'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300',
    payee:     'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
    annulee:   'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
};

// ── Formatage ─────────────────────────────────────────────────────────────────
function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR', { style: 'decimal', maximumFractionDigits: 0 }).format(val) + ' GNF';
}

function formatDate(val: string): string {
    if (!val) return '—';
    return new Date(val).toLocaleDateString('fr-FR');
}

// ── Annulation ────────────────────────────────────────────────────────────────
function confirmAnnuler(packing: Packing) {
    confirm.require({
        message: `Annuler le packing « ${packing.reference} » ? Cette action ne peut pas être défaite.`,
        header: 'Confirmer l\'annulation',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Retour',
        acceptLabel: 'Annuler le packing',
        acceptClass: 'p-button-warning',
        accept: () => {
            router.patch(`/packings/${packing.id}/annuler`, {}, {
                onSuccess: () => toast.add({ severity: 'success', summary: 'Annulé', detail: `${packing.reference} a été annulé.`, life: 3000 }),
            });
        },
    });
}

// ── Suppression ───────────────────────────────────────────────────────────────
function confirmDelete(packing: Packing) {
    confirm.require({
        message: `Supprimer le packing « ${packing.reference} » ? Cette action est irréversible.`,
        header: 'Confirmer la suppression',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Annuler',
        acceptLabel: 'Supprimer',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(`/packings/${packing.id}`, {
                onSuccess: () => toast.add({ severity: 'success', summary: 'Supprimé', detail: `${packing.reference} a été supprimé.`, life: 3000 }),
            });
        },
    });
}
</script>

<template>
    <Head title="Packings" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">

            <!-- En-tête ──────────────────────────────────────────────────────── -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Packings</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ packings.length }} packing{{ packings.length !== 1 ? 's' : '' }} enregistré{{ packings.length !== 1 ? 's' : '' }}
                    </p>
                </div>

                <Link v-if="can('packings.create')" href="/packings/create">
                    <Button>
                        <Plus class="mr-2 h-4 w-4" />
                        Nouveau packing
                    </Button>
                </Link>
            </div>

            <!-- Tableau ──────────────────────────────────────────────────────── -->
            <div class="overflow-hidden rounded-xl border bg-card shadow-sm">
                <DataTable
                    :value="packings"
                    :paginator="packings.length > 20"
                    :rows="20"
                    :global-filter-fields="['reference', 'prestataire_nom', 'statut_label']"
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
                    <!-- Barre de recherche -->
                    <template #header>
                        <div class="flex items-center gap-3">
                            <IconField class="max-w-sm flex-1">
                                <InputIcon class="pointer-events-none">
                                    <Search class="h-4 w-4 text-muted-foreground" />
                                </InputIcon>
                                <InputText
                                    v-model="search"
                                    placeholder="Rechercher un packing..."
                                    class="w-full text-sm"
                                />
                            </IconField>
                            <span class="text-xs text-muted-foreground">
                                {{ packings.length }} résultat{{ packings.length !== 1 ? 's' : '' }}
                            </span>
                        </div>
                    </template>

                    <!-- Référence -->
                    <Column field="reference" header="Référence" sortable style="width: 180px">
                        <template #body="{ data }">
                            <Link :href="`/packings/${data.id}`" class="font-mono text-xs font-semibold tracking-wide text-foreground hover:underline">
                                {{ data.reference }}
                            </Link>
                        </template>
                    </Column>

                    <!-- Prestataire -->
                    <Column field="prestataire_nom" header="Prestataire" sortable>
                        <template #body="{ data }">
                            <span class="font-medium">{{ data.prestataire_nom ?? '—' }}</span>
                        </template>
                    </Column>

                    <!-- Date -->
                    <Column field="date" header="Date" sortable style="width: 120px">
                        <template #body="{ data }">
                            <span class="tabular-nums text-muted-foreground">{{ formatDate(data.date) }}</span>
                        </template>
                    </Column>

                    <!-- Nb rouleaux -->
                    <Column field="nb_rouleaux" header="Rouleaux" sortable style="width: 100px">
                        <template #body="{ data }">
                            <span class="tabular-nums">{{ data.nb_rouleaux.toLocaleString('fr-FR') }}</span>
                        </template>
                    </Column>

                    <!-- Montant total -->
                    <Column field="montant" header="Montant" sortable style="width: 160px">
                        <template #body="{ data }">
                            <span class="font-medium tabular-nums">{{ formatGNF(data.montant) }}</span>
                        </template>
                    </Column>

                    <!-- Versé -->
                    <Column field="montant_verse" header="Versé" sortable style="width: 160px">
                        <template #body="{ data }">
                            <span class="tabular-nums text-emerald-600 dark:text-emerald-400">{{ formatGNF(data.montant_verse) }}</span>
                        </template>
                    </Column>

                    <!-- Restant -->
                    <Column field="montant_restant" header="Restant" sortable style="width: 160px">
                        <template #body="{ data }">
                            <span
                                class="tabular-nums"
                                :class="data.montant_restant > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-muted-foreground'"
                            >
                                {{ formatGNF(data.montant_restant) }}
                            </span>
                        </template>
                    </Column>

                    <!-- Statut -->
                    <Column field="statut" header="Statut" sortable style="width: 120px">
                        <template #body="{ data }">
                            <span
                                class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="statutColor[data.statut] ?? 'bg-muted text-muted-foreground'"
                            >
                                {{ data.statut_label }}
                            </span>
                        </template>
                    </Column>

                    <!-- Actions -->
                    <Column header="" style="width: 56px">
                        <template #body="{ data }">
                            <div class="flex justify-end">
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button variant="ghost" size="icon" class="h-8 w-8">
                                            <MoreVertical class="h-4 w-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end" class="w-44">
                                        <DropdownMenuItem v-if="can('packings.read')" as-child>
                                            <Link :href="`/packings/${data.id}`" class="flex items-center gap-2 w-full">
                                                <Eye class="h-4 w-4" />
                                                Voir
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuItem v-if="data.can_edit && can('packings.update')" as-child>
                                            <Link :href="`/packings/${data.id}/edit`" class="flex items-center gap-2 w-full">
                                                <Pencil class="h-4 w-4" />
                                                Modifier
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator v-if="(data.can_cancel || data.can_edit) && can('packings.update')" />
                                        <DropdownMenuItem
                                            v-if="data.can_cancel && can('packings.update')"
                                            class="cursor-pointer text-amber-600 focus:text-amber-600"
                                            @click="confirmAnnuler(data)"
                                        >
                                            <XCircle class="h-4 w-4" />
                                            Annuler
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            v-if="data.can_edit && can('packings.delete')"
                                            class="text-destructive focus:text-destructive cursor-pointer"
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
                        <div class="flex flex-col items-center gap-3 py-16 text-muted-foreground">
                            <Layers class="h-12 w-12 opacity-30" />
                            <p class="text-sm">Aucun packing trouvé.</p>
                            <Link v-if="can('packings.create')" href="/packings/create">
                                <Button variant="outline" size="sm">
                                    <Plus class="mr-2 h-4 w-4" />
                                    Créer le premier packing
                                </Button>
                            </Link>
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>
