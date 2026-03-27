<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import StatusDot from '@/components/StatusDot.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    MoreVertical,
    Package,
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
import { computed, ref, watch } from 'vue';

interface Produit {
    id: number;
    nom: string;
    code_interne: string | null;
    image_url: string | null;
    is_critique: boolean;
    statut: string;
    statut_label: string;
    qte_stock: number | null;
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

const filteredProduits = computed(() => {
    const query = search.value.trim().toLowerCase();
    if (!query) return props.produits;
    return props.produits.filter((p) =>
        [p.nom, p.code_interne ?? ''].join(' ').toLowerCase().includes(query),
    );
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Produits', href: '/produits' },
];

function confirmDelete(produit: Produit) {
    confirm.require({
        message: `Supprimer "${produit.nom}" ? Cette action est irreversible.`,
        header: 'Confirmer la suppression',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Annuler',
        acceptLabel: 'Supprimer',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(`/produits/${produit.id}`, {
                onSuccess: () => {
                    toast.add({
                        severity: 'success',
                        summary: 'Supprime',
                        detail: `${produit.nom} a ete supprime.`,
                        life: 3000,
                    });
                },
            });
        },
    });
}
</script>

<template>
    <Head title="Produits" />

    <AppLayout :breadcrumbs="breadcrumbs">

        <!-- ─── VUE DESKTOP ─── -->
        <div class="hidden sm:flex flex-col gap-6 p-6">

            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Produits</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ props.produits.length }} produit{{ props.produits.length !== 1 ? 's' : '' }} dans le catalogue
                    </p>
                </div>
                <Link v-if="can('produits.create')" href="/produits/create">
                    <Button>
                        <Plus class="mr-2 h-4 w-4" />
                        Nouveau produit
                    </Button>
                </Link>
            </div>

            <div class="overflow-hidden rounded-xl border bg-card">
                <DataTable
                    :value="props.produits"
                    :paginator="props.produits.length > 20"
                    :rows="20"
                    :global-filter-fields="['nom', 'code_interne']"
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
                                    <Search class="h-4 w-4 text-muted-foreground" />
                                </InputIcon>
                                <InputText v-model="search" placeholder="Rechercher un produit..." class="w-full text-sm" />
                            </IconField>
                            <span class="text-xs text-muted-foreground">
                                {{ props.produits.length }} résultat{{ props.produits.length !== 1 ? 's' : '' }}
                            </span>
                        </div>
                    </template>

                    <!-- Image -->
                    <Column header="Image" style="width: 72px">
                        <template #body="{ data }">
                            <div class="h-10 w-10 overflow-hidden rounded-lg border bg-muted">
                                <img v-if="data.image_url" :src="data.image_url" :alt="data.nom" class="h-full w-full object-cover" />
                                <div v-else class="flex h-full w-full items-center justify-center">
                                    <Package class="h-5 w-5 text-muted-foreground/40" />
                                </div>
                            </div>
                        </template>
                    </Column>

                    <!-- Code -->
                    <Column field="code_interne" header="Code" sortable style="width: 180px">
                        <template #body="{ data }">
                            <span class="font-mono text-xs font-semibold text-muted-foreground whitespace-nowrap">
                                {{ data.code_interne || '—' }}
                            </span>
                        </template>
                    </Column>

                    <!-- Produit -->
                    <Column field="nom" header="Produit" sortable style="min-width: 200px">
                        <template #body="{ data }">
                            <div class="flex items-center gap-1.5">
                                <span class="font-medium">{{ data.nom }}</span>
                                <AlertTriangle v-if="data.is_critique" class="h-3.5 w-3.5 shrink-0 text-amber-500" />
                            </div>
                        </template>
                    </Column>

                    <!-- Stock -->
                    <Column field="qte_stock" header="Stock" sortable style="width: 120px">
                        <template #body="{ data }">
                            <template v-if="!data.has_stock">
                                <span class="text-xs text-muted-foreground">—</span>
                            </template>
                            <template v-else>
                                <div class="flex items-center gap-1.5">
                                    <span
                                        class="tabular-nums text-sm font-medium"
                                        :class="data.is_low_stock ? 'text-amber-600' : 'text-foreground'"
                                    >{{ data.qte_stock ?? 0 }}</span>
                                    <AlertTriangle v-if="data.is_low_stock" class="h-3.5 w-3.5 text-amber-500" />
                                </div>
                            </template>
                        </template>
                    </Column>

                    <!-- Statut -->
                    <Column field="statut" header="Statut" sortable style="width: 120px">
                        <template #body="{ data }">
                            <StatusDot
                                :label="data.statut_label"
                                :dot-class="
                                    data.statut === 'actif'   ? 'bg-emerald-500' :
                                    data.statut === 'inactif' ? 'bg-zinc-400 dark:bg-zinc-500' :
                                    'bg-orange-400'
                                "
                                class="text-muted-foreground"
                            />
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
                                        <DropdownMenuItem v-if="can('produits.update')" as-child>
                                            <Link :href="`/produits/${data.id}/edit`" class="flex w-full items-center gap-2">
                                                <Pencil class="h-4 w-4" />
                                                Modifier
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator v-if="can('produits.update') && can('produits.delete')" />
                                        <DropdownMenuItem
                                            v-if="can('produits.delete')"
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

                    <!-- Empty state -->
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

        <!-- ─── VUE MOBILE ─── -->
        <div class="flex flex-col sm:hidden min-h-full bg-background">

            <!-- Header fixe style app native -->
            <div class="sticky top-0 z-20 bg-background/95 backdrop-blur-sm border-b border-border/60">
                <div class="relative flex items-center justify-center px-4 pt-4 pb-3">
                    <!-- Bouton retour (gauche) -->
                    <Link
                        href="/dashboard"
                        class="absolute left-4 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground active:scale-95 transition-transform"
                    >
                        <ArrowLeft class="h-4 w-4" />
                    </Link>

                    <!-- Titre centré -->
                    <div class="text-center">
                        <h1 class="text-[17px] font-semibold leading-tight">Produits</h1>
                        <p class="text-[11px] text-muted-foreground">
                            {{ filteredProduits.length }} dans le catalogue
                        </p>
                    </div>

                    <!-- Bouton action (droite) -->
                    <Link v-if="can('produits.create')" href="/produits/create" class="absolute right-4 shrink-0">
                        <button class="inline-flex items-center gap-1.5 rounded-full bg-primary px-3.5 py-2 text-xs font-semibold text-primary-foreground shadow-sm active:scale-95 transition-transform">
                            <Plus class="h-3.5 w-3.5" />
                            Nouveau
                        </button>
                    </Link>
                </div>

                <!-- Barre de recherche -->
                <div class="px-4 pb-3">
                    <div class="relative flex items-center">
                        <Search class="absolute left-3 h-4 w-4 text-muted-foreground pointer-events-none" />
                        <input
                            v-model="search"
                            type="search"
                            placeholder="Rechercher un produit..."
                            class="w-full rounded-xl border-0 bg-muted py-2.5 pl-9 pr-4 text-sm placeholder:text-muted-foreground/60 focus:outline-none focus:ring-2 focus:ring-primary/30"
                        />
                    </div>
                </div>
            </div>

            <!-- Liste produits -->
            <div v-if="filteredProduits.length" class="divide-y divide-border/50">
                <div
                    v-for="data in filteredProduits"
                    :key="data.id"
                    class="flex items-center gap-3.5 px-4 py-3.5 active:bg-muted/40 transition-colors"
                >
                    <!-- Image -->
                    <div class="h-12 w-12 shrink-0 overflow-hidden rounded-xl border bg-muted shadow-sm">
                        <img
                            v-if="data.image_url"
                            :src="data.image_url"
                            :alt="data.nom"
                            class="h-full w-full object-cover"
                        />
                        <div v-else class="flex h-full w-full items-center justify-center">
                            <Package class="h-5 w-5 text-muted-foreground/30" />
                        </div>
                    </div>

                    <!-- Infos -->
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-1.5">
                            <p class="truncate text-[13px] font-semibold leading-tight">{{ data.nom }}</p>
                            <AlertTriangle
                                v-if="data.is_critique"
                                class="h-3.5 w-3.5 shrink-0 text-amber-500"
                            />
                        </div>
                        <p class="mt-0.5 font-mono text-[11px] text-muted-foreground">
                            {{ data.code_interne || '—' }}
                        </p>
                    </div>

                    <!-- Actions -->
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <button class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-muted-foreground active:bg-muted transition-colors">
                                <MoreVertical class="h-4 w-4" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-44">
                            <DropdownMenuItem v-if="can('produits.update')" as-child>
                                <Link :href="`/produits/${data.id}/edit`" class="flex w-full items-center gap-2">
                                    <Pencil class="h-4 w-4" />
                                    Modifier
                                </Link>
                            </DropdownMenuItem>
                            <DropdownMenuSeparator v-if="can('produits.update') && can('produits.delete')" />
                            <DropdownMenuItem
                                v-if="can('produits.delete')"
                                class="cursor-pointer text-destructive focus:text-destructive"
                                @click="confirmDelete(data)"
                            >
                                <Trash2 class="h-4 w-4" />
                                Supprimer
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>

            <!-- Empty state mobile -->
            <div v-else class="flex flex-col items-center gap-4 px-6 py-16 text-center">
                <div class="flex h-16 w-16 items-center justify-center rounded-2xl bg-muted">
                    <Package class="h-8 w-8 text-muted-foreground/40" />
                </div>
                <div>
                    <p class="font-medium text-foreground">Aucun produit</p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ search ? 'Aucun résultat pour cette recherche.' : 'Le catalogue est vide pour le moment.' }}
                    </p>
                </div>
                <Link v-if="can('produits.create') && !search" href="/produits/create">
                    <button class="inline-flex items-center gap-2 rounded-full bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm active:scale-95 transition-transform">
                        <Plus class="h-4 w-4" />
                        Créer le premier produit
                    </button>
                </Link>
            </div>
        </div>

    </AppLayout>
</template>
