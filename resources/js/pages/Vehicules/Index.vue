<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    DropdownMenu, DropdownMenuContent, DropdownMenuItem,
    DropdownMenuSeparator, DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { Car, MoreVertical, Pencil, Plus, Search, Trash2 } from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputText from 'primevue/inputtext';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { ref, watch } from 'vue';

interface Vehicule {
    id: number;
    nom_vehicule: string;
    marque: string | null;
    modele: string | null;
    immatriculation: string;
    type_label: string;
    capacite_packs: number | null;
    proprietaire_nom: string | null;
    livreur_nom: string | null;
    photo_url: string | null;
    is_active: boolean;
}

const props = defineProps<{ vehicules: Vehicule[] }>();

const { can } = usePermissions();
const confirm = useConfirm();
const toast = useToast();

const search = ref('');
const filters = ref({ global: { value: '', matchMode: 'contains' } });
watch(search, (val) => { filters.value.global.value = val; });

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Véhicules', href: '/vehicules' },
];

function confirmDelete(v: Vehicule) {
    confirm.require({
        message: `Supprimer « ${v.nom_vehicule} (${v.immatriculation}) » ? Cette action est irréversible.`,
        header: 'Confirmer la suppression',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Annuler',
        acceptLabel: 'Supprimer',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(`/vehicules/${v.id}`, {
                onSuccess: () => toast.add({
                    severity: 'success',
                    summary: 'Supprimé',
                    detail: `${v.nom_vehicule} a été supprimé.`,
                    life: 3000,
                }),
            });
        },
    });
}
</script>

<template>
    <Head title="Véhicules" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">

            <!-- En-tête -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Véhicules</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ vehicules.length }} véhicule{{ vehicules.length !== 1 ? 's' : '' }}
                    </p>
                </div>
                <Link v-if="can('vehicules.create')" href="/vehicules/create">
                    <Button>
                        <Plus class="mr-2 h-4 w-4" />
                        Nouveau véhicule
                    </Button>
                </Link>
            </div>

            <!-- Tableau -->
            <div class="overflow-hidden rounded-xl border bg-card shadow-sm">
                <DataTable
                    :value="vehicules"
                    :paginator="vehicules.length > 20"
                    :rows="20"
                    :global-filter-fields="['nom_vehicule', 'immatriculation', 'type_label', 'proprietaire_nom', 'livreur_nom']"
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
                                <InputText v-model="search" placeholder="Rechercher..." class="w-full text-sm" />
                            </IconField>
                            <span class="text-xs text-muted-foreground">{{ vehicules.length }} résultat{{ vehicules.length !== 1 ? 's' : '' }}</span>
                        </div>
                    </template>

                    <!-- Véhicule -->
                    <Column field="nom_vehicule" header="Véhicule" sortable style="min-width: 320px">
                        <template #body="{ data }">
                            <div class="flex items-center gap-3">
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-lg border bg-muted/30">
                                    <img
                                        v-if="data.photo_url"
                                        :src="data.photo_url"
                                        :alt="data.nom_vehicule"
                                        class="h-full w-full object-cover"
                                    />
                                    <Car v-else class="h-5 w-5 text-muted-foreground" />
                                </div>
                                <div>
                                    <div class="font-medium">{{ data.nom_vehicule }}</div>
                                    <div class="font-mono text-xs text-muted-foreground">{{ data.immatriculation }}</div>
                                </div>
                            </div>
                        </template>
                    </Column>

                    <!-- Type -->
                    <Column field="type_label" header="Type" sortable style="width: 140px">
                        <template #body="{ data }">
                            <span class="inline-flex items-center rounded-full bg-muted px-2.5 py-0.5 text-xs font-medium">
                                {{ data.type_label }}
                            </span>
                        </template>
                    </Column>

                    <!-- Capacité -->
                    <Column field="capacite_packs" header="Capacité" sortable style="width: 130px">
                        <template #body="{ data }">
                            <span class="tabular-nums text-muted-foreground whitespace-nowrap">
                                {{ data.capacite_packs != null ? `${data.capacite_packs} packs` : '—' }}
                            </span>
                        </template>
                    </Column>

                    <!-- Propriétaire -->
                    <Column field="proprietaire_nom" header="Propriétaire" style="min-width: 180px">
                        <template #body="{ data }">
                            <span class="text-muted-foreground">{{ data.proprietaire_nom ?? '—' }}</span>
                        </template>
                    </Column>

                    <!-- Livreur -->
                    <Column field="livreur_nom" header="Livreur" style="min-width: 180px">
                        <template #body="{ data }">
                            <span class="text-muted-foreground">{{ data.livreur_nom ?? '—' }}</span>
                        </template>
                    </Column>

                    <!-- Statut -->
                    <Column field="is_active" header="Statut" sortable style="width: 110px">
                        <template #body="{ data }">
                            <span
                                class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="data.is_active
                                    ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
                                    : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400'"
                            >
                                {{ data.is_active ? 'Actif' : 'Inactif' }}
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
                                        <DropdownMenuItem v-if="can('vehicules.update')" as-child>
                                            <Link :href="`/vehicules/${data.id}/edit`" class="flex items-center gap-2 w-full">
                                                <Pencil class="h-4 w-4" />
                                                Modifier
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator v-if="can('vehicules.update') && can('vehicules.delete')" />
                                        <DropdownMenuItem
                                            v-if="can('vehicules.delete')"
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
                            <Car class="h-12 w-12 opacity-30" />
                            <p class="text-sm">Aucun véhicule trouvé.</p>
                            <Link v-if="can('vehicules.create')" href="/vehicules/create">
                                <Button variant="outline" size="sm">
                                    <Plus class="mr-2 h-4 w-4" />
                                    Ajouter le premier véhicule
                                </Button>
                            </Link>
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>


