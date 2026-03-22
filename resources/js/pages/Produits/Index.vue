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
    AlertTriangle,
    MoreVertical,
    Package,
    PackageMinus,
    Pencil,
    Plus,
    Search,
    Trash2,
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
interface Produit {
    id: number;
    nom: string;
    code_interne: string | null;
    type: string;
    type_label: string;
    statut: string;
    statut_label: string;
    image_url: string | null;
    prix_vente: number | null;
    prix_usine: number | null;
    prix_achat: number | null;
    qte_stock: number;
    is_critique: boolean;
    in_stock: boolean;
    is_low_stock: boolean;
    has_stock: boolean;
}

const props = defineProps<{ produits: Produit[] }>();

const { can } = usePermissions();
const confirm = useConfirm();
const toast = useToast();

const search = ref('');
const filters = ref({ global: { value: '', matchMode: 'contains' } });
watch(search, (val) => { filters.value.global.value = val; });

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Produits', href: '/produits' },
];

// ── Config badges ─────────────────────────────────────────────────────────────
const typeColor: Record<string, string> = {
    materiel:    'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300',
    service:     'bg-purple-100 text-purple-700 dark:bg-purple-950 dark:text-purple-300',
    fabricable:  'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
    achat_vente: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
};

const statutColor: Record<string, string> = {
    actif:   'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
    inactif: 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
    archive: 'bg-red-100 text-red-600 dark:bg-red-950 dark:text-red-400',
};

// ── Formatage ─────────────────────────────────────────────────────────────────
function formatPrix(v: number | null): string {
    if (v === null || v === undefined) return '—';
    return new Intl.NumberFormat('fr-GN', { style: 'decimal' }).format(v) + ' GNF';
}

// ── Suppression ───────────────────────────────────────────────────────────────
function confirmDelete(produit: Produit) {
    confirm.require({
        message: `Supprimer « ${produit.nom} » ? Cette action est irréversible.`,
        header: 'Confirmer la suppression',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Annuler',
        acceptLabel: 'Supprimer',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(`/produits/${produit.id}`, {
                onSuccess: () => toast.add({ severity: 'success', summary: 'Supprimé', detail: `${produit.nom} a été supprimé.`, life: 3000 }),
            });
        },
    });
}
</script>

<template>
    <Head title="Produits" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">

            <!-- En-tête ──────────────────────────────────────────────────────── -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Produits</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ produits.length }} produit{{ produits.length !== 1 ? 's' : '' }} dans le catalogue
                    </p>
                </div>

                <Link v-if="can('produits.create')" href="/produits/create">
                    <Button>
                        <Plus class="mr-2 h-4 w-4" />
                        Nouveau produit
                    </Button>
                </Link>
            </div>

            <!-- Tableau ──────────────────────────────────────────────────────── -->
            <div class="overflow-hidden rounded-xl border bg-card shadow-sm">
                <DataTable
                    :value="produits"
                    :paginator="produits.length > 20"
                    :rows="20"
                    :global-filter-fields="['nom', 'code_interne', 'type_label', 'statut_label']"
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
                                    placeholder="Rechercher un produit..."
                                    class="w-full text-sm"
                                />
                            </IconField>
                            <span class="text-xs text-muted-foreground">
                                {{ produits.length }} résultat{{ produits.length !== 1 ? 's' : '' }}
                            </span>
                        </div>
                    </template>

                    <!-- Image ──── -->
                    <Column header="Image" style="width: 60px">
                        <template #body="{ data }">
                            <div class="h-10 w-10 overflow-hidden rounded-lg border bg-muted">
                                <img
                                    v-if="data.image_url"
                                    :src="data.image_url"
                                    :alt="data.nom"
                                    class="h-full w-full object-cover"
                                />
                                <div v-else class="flex h-full w-full items-center justify-center">
                                    <Package class="h-5 w-5 text-muted-foreground/40" />
                                </div>
                            </div>
                        </template>
                    </Column>

                    <!-- Code ──── -->
                    <Column field="code_interne" header="Code-barres" sortable style="width: 150px">
                        <template #body="{ data }">
                            <div class="font-mono text-xs font-semibold tracking-wide text-foreground">
                                {{ data.code_interne }}
                            </div>
                        </template>
                    </Column>

                    <!-- Nom ──── -->
                    <Column field="nom" header="Produit" sortable>
                        <template #body="{ data }">
                            <div class="flex items-center gap-1.5 font-medium">
                                {{ data.nom }}
                                <span v-if="data.is_critique" title="Produit critique">
                                    <AlertTriangle class="h-3.5 w-3.5 text-amber-500" />
                                </span>
                            </div>
                        </template>
                    </Column>

                    <!-- Type ──── -->
                    <Column field="type" header="Type" sortable style="width: 130px">
                        <template #body="{ data }">
                            <span
                                class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="typeColor[data.type] ?? 'bg-muted text-muted-foreground'"
                            >
                                {{ data.type_label }}
                            </span>
                        </template>
                    </Column>

                    <!-- Statut ──── -->
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

                    <!-- Prix vente ──── -->
                    <Column field="prix_vente" header="Prix vente" sortable style="width: 140px">
                        <template #body="{ data }">
                            <span class="font-medium tabular-nums">{{ formatPrix(data.prix_vente) }}</span>
                        </template>
                    </Column>

                    <!-- Prix achat ──── -->
                    <Column field="prix_achat" header="Prix achat" sortable style="width: 140px">
                        <template #body="{ data }">
                            <span class="tabular-nums text-muted-foreground">{{ formatPrix(data.prix_achat) }}</span>
                        </template>
                    </Column>

                    <!-- Prix usine ──── (fabricable uniquement) -->
                    <Column field="prix_usine" header="Prix usine" sortable style="width: 140px">
                        <template #body="{ data }">
                            <span v-if="data.type === 'fabricable'" class="tabular-nums text-muted-foreground">
                                {{ formatPrix(data.prix_usine) }}
                            </span>
                            <span v-else class="text-xs text-muted-foreground/40">—</span>
                        </template>
                    </Column>

                    <!-- Stock ──── -->
                    <Column field="qte_stock" header="Stock" sortable style="width: 110px">
                        <template #body="{ data }">
                            <div v-if="data.has_stock" class="flex items-center gap-1.5">
                                <PackageMinus v-if="!data.in_stock" class="h-4 w-4 text-red-500" />
                                <AlertTriangle v-else-if="data.is_low_stock" class="h-4 w-4 text-amber-500" />
                                <span
                                    class="font-medium tabular-nums"
                                    :class="{
                                        'text-red-600 dark:text-red-400': !data.in_stock,
                                        'text-amber-600 dark:text-amber-400': data.is_low_stock && data.in_stock,
                                    }"
                                >
                                    {{ data.qte_stock }}
                                </span>
                            </div>
                            <span v-else class="text-xs text-muted-foreground italic">Service</span>
                        </template>
                    </Column>

                    <!-- Actions ──── -->
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
                                        <DropdownMenuItem v-if="can('produits.update')" as-child>
                                            <Link :href="`/produits/${data.id}/edit`" class="flex items-center gap-2 w-full">
                                                <Pencil class="h-4 w-4" />
                                                Modifier
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator v-if="can('produits.update') && can('produits.delete')" />
                                        <DropdownMenuItem
                                            v-if="can('produits.delete')"
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
                            <Package class="h-12 w-12 opacity-30" />
                            <p class="text-sm">Aucun produit trouvé.</p>
                            <Link v-if="can('produits.create')" href="/produits/create">
                                <Button variant="outline" size="sm">
                                    <Plus class="mr-2 h-4 w-4" />
                                    Créer le premier produit
                                </Button>
                            </Link>
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>
