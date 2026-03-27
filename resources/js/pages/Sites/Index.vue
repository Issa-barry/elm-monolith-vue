<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    DropdownMenu, DropdownMenuContent, DropdownMenuItem,
    DropdownMenuSeparator, DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import StatusDot from '@/components/StatusDot.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { Building2, MoreVertical, Pencil, Plus, Search, Trash2 } from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputText from 'primevue/inputtext';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { ref, watch } from 'vue';

interface Site {
    id: number;
    nom: string;
    code: string;
    type: string | null;
    type_label: string;
    statut: string | null;
    statut_label: string;
    localisation: string | null;
    pays: string | null;
    ville: string | null;
    quartier: string | null;
    description: string | null;
    parent_id: number | null;
    parent_nom: string | null;
    enfants_count: number;
}

const props = defineProps<{ sites: Site[] }>();

const { can } = usePermissions();
const confirm = useConfirm();
const toast = useToast();

const search = ref('');
const filters = ref({ global: { value: '', matchMode: 'contains' } });
watch(search, (val) => { filters.value.global.value = val; });

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Sites', href: '/sites' },
];

function confirmDelete(s: Site) {
    if (s.enfants_count > 0) {
        toast.add({
            severity: 'warn',
            summary: 'Suppression impossible',
            detail: `Ce site possède ${s.enfants_count} site${s.enfants_count > 1 ? 's' : ''} enfant${s.enfants_count > 1 ? 's' : ''}. Veuillez d'abord les réaffecter ou les supprimer.`,
            life: 5000,
        });
        return;
    }

    confirm.require({
        message: `Supprimer le site « ${s.nom} (${s.code}) » ? Cette action est irréversible.`,
        header: 'Confirmer la suppression',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Annuler',
        acceptLabel: 'Supprimer',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(`/sites/${s.id}`, {
                onSuccess: () => toast.add({
                    severity: 'success',
                    summary: 'Supprimé',
                    detail: `${s.nom} a été supprimé.`,
                    life: 3000,
                }),
            });
        },
    });
}
</script>

<template>
    <Head title="Sites" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">

            <!-- En-tête -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Sites</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ sites.length }} site{{ sites.length !== 1 ? 's' : '' }}
                    </p>
                </div>
                <Link v-if="can('sites.create')" href="/sites/create">
                    <Button>
                        <Plus class="mr-2 h-4 w-4" />
                        Nouveau site
                    </Button>
                </Link>
            </div>

            <!-- Tableau -->
            <div class="overflow-hidden rounded-xl border bg-card">
                <DataTable
                    :value="sites"
                    :paginator="sites.length > 20"
                    :rows="20"
                    :global-filter-fields="['nom', 'code', 'type_label', 'statut_label', 'ville', 'parent_nom']"
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
                            <span class="text-xs text-muted-foreground">{{ sites.length }} résultat{{ sites.length !== 1 ? 's' : '' }}</span>
                        </div>
                    </template>

                    <!-- Nom + code -->
                    <Column field="nom" header="Site" sortable style="min-width: 280px">
                        <template #body="{ data }">
                            <div class="flex items-center gap-3">
                                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border bg-muted/30">
                                    <Building2 class="h-4 w-4 text-muted-foreground" />
                                </div>
                                <div>
                                    <div class="font-medium">{{ data.nom }}</div>
                                    <span class="inline-block rounded bg-muted px-1.5 py-0.5 font-mono text-[11px] text-muted-foreground">
                                        {{ data.code }}
                                    </span>
                                </div>
                            </div>
                        </template>
                    </Column>

                    <!-- Type -->
                    <Column field="type_label" header="Type" sortable style="width: 130px">
                        <template #body="{ data }">
                            <span class="inline-flex items-center rounded-full bg-muted px-2.5 py-0.5 text-xs font-medium">
                                {{ data.type_label }}
                            </span>
                        </template>
                    </Column>

                    <!-- Statut -->
                    <Column field="statut_label" header="Statut" sortable style="width: 120px">
                        <template #body="{ data }">
                            <StatusDot
                                :label="data.statut_label"
                                :dot-class="data.statut === 'active' ? 'bg-emerald-500' : 'bg-zinc-400 dark:bg-zinc-500'"
                                class="text-muted-foreground"
                            />
                        </template>
                    </Column>

                    <!-- Parent -->
                    <Column field="parent_nom" header="Parent" style="min-width: 160px">
                        <template #body="{ data }">
                            <span class="text-muted-foreground">{{ data.parent_nom ?? '—' }}</span>
                        </template>
                    </Column>

                    <!-- Ville -->
                    <Column field="ville" header="Ville" style="min-width: 140px">
                        <template #body="{ data }">
                            <span class="text-muted-foreground">{{ data.ville ?? '—' }}</span>
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
                                        <DropdownMenuItem v-if="can('sites.update')" as-child>
                                            <Link :href="`/sites/${data.id}/edit`" class="flex items-center gap-2 w-full">
                                                <Pencil class="h-4 w-4" />
                                                Modifier
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator v-if="can('sites.update') && can('sites.delete')" />
                                        <DropdownMenuItem
                                            v-if="can('sites.delete')"
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
                            <Building2 class="h-12 w-12 opacity-30" />
                            <p class="text-sm">Aucun site trouvé.</p>
                            <Link v-if="can('sites.create')" href="/sites/create">
                                <Button variant="outline" size="sm">
                                    <Plus class="mr-2 h-4 w-4" />
                                    Ajouter le premier site
                                </Button>
                            </Link>
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>
