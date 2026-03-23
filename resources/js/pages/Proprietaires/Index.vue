<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    DropdownMenu, DropdownMenuContent, DropdownMenuItem,
    DropdownMenuSeparator, DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import StatusDot from '@/components/StatusDot.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPhoneDisplay } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { Home, MoreVertical, Pencil, Plus, Search, Trash2 } from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputText from 'primevue/inputtext';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { ref, watch } from 'vue';

interface Proprietaire {
    id: number;
    nom: string;
    prenom: string;
    nom_complet: string;
    email: string | null;
    telephone: string | null;
    adresse: string | null;
    is_active: boolean;
}

const props = defineProps<{ proprietaires: Proprietaire[] }>();

const { can } = usePermissions();
const confirm = useConfirm();
const toast = useToast();

const search = ref('');
const filters = ref({ global: { value: '', matchMode: 'contains' } });
watch(search, (val) => { filters.value.global.value = val; });

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Propriétaires', href: '/proprietaires' },
];

function confirmDelete(p: Proprietaire) {
    confirm.require({
        message: `Supprimer « ${p.nom_complet} » ? Cette action est irréversible.`,
        header: 'Confirmer la suppression',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Annuler',
        acceptLabel: 'Supprimer',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(`/proprietaires/${p.id}`, {
                onSuccess: () => toast.add({
                    severity: 'success',
                    summary: 'Supprimé',
                    detail: `${p.nom_complet} a été supprimé.`,
                    life: 3000,
                }),
            });
        },
    });
}
</script>

<template>
    <Head title="Propriétaires" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">

            <!-- En-tête -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Propriétaires</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ proprietaires.length }} propriétaire{{ proprietaires.length !== 1 ? 's' : '' }}
                    </p>
                </div>
                <Link v-if="can('proprietaires.create')" href="/proprietaires/create">
                    <Button>
                        <Plus class="mr-2 h-4 w-4" />
                        Nouveau propriétaire
                    </Button>
                </Link>
            </div>

            <!-- Tableau -->
            <div class="overflow-hidden rounded-xl border bg-card shadow-sm">
                <DataTable
                    :value="proprietaires"
                    :paginator="proprietaires.length > 20"
                    :rows="20"
                    :global-filter-fields="['nom_complet', 'email', 'telephone', 'adresse']"
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
                            <span class="text-xs text-muted-foreground">{{ proprietaires.length }} résultat{{ proprietaires.length !== 1 ? 's' : '' }}</span>
                        </div>
                    </template>

                    <!-- Nom -->
                    <Column field="nom_complet" header="Propriétaire" sortable style="min-width: 320px">
                        <template #body="{ data }">
                            <div class="flex items-center gap-3">
                                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted">
                                    <Home class="h-4 w-4 text-muted-foreground" />
                                </div>
                                <div>
                                    <div class="font-medium">{{ data.nom_complet }}</div>
                                    <div v-if="data.email" class="text-xs text-muted-foreground">{{ data.email }}</div>
                                </div>
                            </div>
                        </template>
                    </Column>

                    <!-- Téléphone -->
                    <Column field="telephone" header="Téléphone" style="width: 190px">
                        <template #body="{ data }">
                            <span class="tabular-nums text-muted-foreground whitespace-nowrap">{{ formatPhoneDisplay(data.telephone) }}</span>
                        </template>
                    </Column>

                    <!-- Adresse -->
                    <Column field="adresse" header="Adresse" style="min-width: 220px">
                        <template #body="{ data }">
                            <span class="text-muted-foreground">{{ data.adresse ?? '—' }}</span>
                        </template>
                    </Column>

                    <!-- Statut -->
                    <Column field="is_active" header="Statut" sortable style="width: 110px">
                        <template #body="{ data }">
                            <StatusDot
                                :label="data.is_active ? 'Actif' : 'Inactif'"
                                :dot-class="data.is_active ? 'bg-emerald-500' : 'bg-zinc-400 dark:bg-zinc-500'"
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
                                        <DropdownMenuItem v-if="can('proprietaires.update')" as-child>
                                            <Link :href="`/proprietaires/${data.id}/edit`" class="flex items-center gap-2 w-full">
                                                <Pencil class="h-4 w-4" />
                                                Modifier
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator v-if="can('proprietaires.update') && can('proprietaires.delete')" />
                                        <DropdownMenuItem
                                            v-if="can('proprietaires.delete')"
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
                            <Home class="h-12 w-12 opacity-30" />
                            <p class="text-sm">Aucun propriétaire trouvé.</p>
                            <Link v-if="can('proprietaires.create')" href="/proprietaires/create">
                                <Button variant="outline" size="sm">
                                    <Plus class="mr-2 h-4 w-4" />
                                    Ajouter le premier propriétaire
                                </Button>
                            </Link>
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>


