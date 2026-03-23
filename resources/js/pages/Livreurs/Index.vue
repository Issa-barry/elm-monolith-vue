<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    DropdownMenu, DropdownMenuContent, DropdownMenuItem,
    DropdownMenuSeparator, DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPhoneDisplay } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { MoreVertical, Pencil, Plus, Search, Trash2, Truck } from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputText from 'primevue/inputtext';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { ref, watch } from 'vue';

interface Livreur {
    id: number;
    nom: string;
    prenom: string;
    nom_complet: string;
    email: string | null;
    telephone: string | null;
    ville: string | null;
    pays: string | null;
    code_pays: string | null;
    is_active: boolean;
}

const props = defineProps<{ livreurs: Livreur[] }>();

const { can } = usePermissions();
const confirm = useConfirm();
const toast = useToast();

const search = ref('');
const filters = ref({ global: { value: '', matchMode: 'contains' } });
watch(search, (val) => { filters.value.global.value = val; });

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Livreurs', href: '/livreurs' },
];

function flagUrl(code: string) {
    return `https://flagcdn.com/20x15/${code.toLowerCase()}.png`;
}

function confirmDelete(l: Livreur) {
    confirm.require({
        message: `Supprimer « ${l.nom_complet} » ? Cette action est irréversible.`,
        header: 'Confirmer la suppression',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Annuler',
        acceptLabel: 'Supprimer',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(`/livreurs/${l.id}`, {
                onSuccess: () => toast.add({
                    severity: 'success',
                    summary: 'Supprimé',
                    detail: `${l.nom_complet} a été supprimé.`,
                    life: 3000,
                }),
            });
        },
    });
}
</script>

<template>
    <Head title="Livreurs" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">

            <!-- En-tête -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Livreurs</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ livreurs.length }} livreur{{ livreurs.length !== 1 ? 's' : '' }}
                    </p>
                </div>
                <Link v-if="can('livreurs.create')" href="/livreurs/create">
                    <Button>
                        <Plus class="mr-2 h-4 w-4" />
                        Nouveau livreur
                    </Button>
                </Link>
            </div>

            <!-- Tableau -->
            <div class="overflow-hidden rounded-xl border bg-card shadow-sm">
                <DataTable
                    :value="livreurs"
                    :paginator="livreurs.length > 20"
                    :rows="20"
                    :global-filter-fields="['nom_complet', 'email', 'telephone', 'ville', 'pays']"
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
                            <span class="text-xs text-muted-foreground">{{ livreurs.length }} résultat{{ livreurs.length !== 1 ? 's' : '' }}</span>
                        </div>
                    </template>

                    <!-- Nom -->
                    <Column field="nom_complet" header="Livreur" sortable style="min-width: 320px">
                        <template #body="{ data }">
                            <div class="flex items-center gap-3">
                                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted">
                                    <Truck class="h-4 w-4 text-muted-foreground" />
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

                    <!-- Localisation -->
                    <Column field="ville" header="Localisation" style="min-width: 220px">
                        <template #body="{ data }">
                            <div class="flex items-center gap-2">
                                <img v-if="data.code_pays" :src="flagUrl(data.code_pays)" class="h-4 w-auto rounded-sm shadow-sm" />
                                <span class="text-muted-foreground">
                                    {{ [data.ville, data.pays].filter(Boolean).join(', ') || '—' }}
                                </span>
                            </div>
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
                                        <DropdownMenuItem v-if="can('livreurs.update')" as-child>
                                            <Link :href="`/livreurs/${data.id}/edit`" class="flex items-center gap-2 w-full">
                                                <Pencil class="h-4 w-4" />
                                                Modifier
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator v-if="can('livreurs.update') && can('livreurs.delete')" />
                                        <DropdownMenuItem
                                            v-if="can('livreurs.delete')"
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
                            <Truck class="h-12 w-12 opacity-30" />
                            <p class="text-sm">Aucun livreur trouvé.</p>
                            <Link v-if="can('livreurs.create')" href="/livreurs/create">
                                <Button variant="outline" size="sm">
                                    <Plus class="mr-2 h-4 w-4" />
                                    Ajouter le premier livreur
                                </Button>
                            </Link>
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>


