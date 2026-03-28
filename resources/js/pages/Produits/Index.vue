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
import ProduitsMobile from './partials/ProduitsMobile.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
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
import { ref, watch } from 'vue';

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

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">

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
        <div class="sm:hidden">
            <ProduitsMobile :produits="props.produits" :on-delete="confirmDelete" />
        </div>

    </AppLayout>
</template>
