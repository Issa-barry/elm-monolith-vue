<script setup lang="ts">
import DataTableFilters from '@/components/DataTableFilters.vue';
import StatusDot from '@/components/StatusDot.vue';
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
import { formatPhoneDisplay } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Eye,
    MoreVertical,
    Pencil,
    Plus,
    Trash2,
    Users,
} from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Select from 'primevue/select';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed, ref } from 'vue';

interface Equipe {
    id: string;
    nom: string;
    is_active: boolean;
    nb_membres: number;
    somme_taux: number;
    premier_chauffeur_nom: string | null;
    premier_chauffeur_telephone: string | null;
    vehicule_nom: string | null;
    vehicule_immatriculation: string | null;
    vehicule_categorie: 'interne' | 'externe' | null;
}

const props = defineProps<{ equipes: Equipe[] }>();

const { can } = usePermissions();
const confirm = useConfirm();
const toast = useToast();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Véhicules', href: '/vehicules' },
    { title: 'Équipes de livraison', href: '/equipes-livraison' },
];

const search = ref('');
const statut = ref<'tous' | 'actif' | 'inactif'>('tous');
const categorie = ref<'tous' | 'interne' | 'externe'>('tous');

function resetFilters() {
    search.value = '';
    statut.value = 'tous';
    categorie.value = 'tous';
}

const hasActiveFilters = computed(
    () =>
        !!search.value || statut.value !== 'tous' || categorie.value !== 'tous',
);

const equipesFiltrees = computed(() => {
    const q = search.value.toLowerCase().trim();
    return props.equipes.filter((e) => {
        const searchMatch =
            !q ||
            e.nom.toLowerCase().includes(q) ||
            (e.premier_chauffeur_nom ?? '').toLowerCase().includes(q) ||
            (e.vehicule_nom ?? '').toLowerCase().includes(q) ||
            (e.vehicule_immatriculation ?? '').toLowerCase().includes(q);
        const statusMatch =
            statut.value === 'tous' ||
            e.is_active === (statut.value === 'actif');
        const categorieMatch =
            categorie.value === 'tous' ||
            e.vehicule_categorie === categorie.value;
        return searchMatch && statusMatch && categorieMatch;
    });
});

function confirmDelete(equipe: Equipe) {
    confirm.require({
        message: `Supprimer l'équipe « ${equipe.nom} » ?`,
        header: 'Confirmer la suppression',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Annuler',
        acceptLabel: 'Supprimer',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(`/equipes-livraison/${equipe.id}`, {
                onSuccess: () =>
                    toast.add({
                        severity: 'success',
                        summary: 'Équipe supprimée',
                        life: 3000,
                    }),
                onError: (errors: Record<string, string>) => {
                    const msg =
                        errors.equipe ??
                        'Impossible de supprimer cette équipe.';
                    toast.add({ severity: 'error', summary: msg, life: 5000 });
                },
            });
        },
    });
}
</script>

<template>
    <Head>
        <title>Équipes de livraison</title>
    </Head>

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4 sm:p-6">
            <!-- En-tête -->
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Équipes de livraison
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Gérez les équipes et leurs taux de commission.
                    </p>
                </div>
                <Link
                    v-if="can('equipes-livraison.create')"
                    href="/equipes-livraison/create"
                >
                    <Button>
                        <Plus class="mr-2 h-4 w-4" />
                        Nouvelle équipe
                    </Button>
                </Link>
            </div>

            <!-- Barre de recherche + filtres -->
            <DataTableFilters
                v-model:search="search"
                :has-active-filters="hasActiveFilters"
                :result-count="equipesFiltrees.length"
                @reset="resetFilters"
            >
                <Select
                    v-model="statut"
                    :options="[
                        { value: 'tous', label: 'Tous' },
                        { value: 'actif', label: 'Actif' },
                        { value: 'inactif', label: 'Inactif' },
                    ]"
                    option-label="label"
                    option-value="value"
                    class="w-32"
                />
                <Select
                    v-model="categorie"
                    :options="[
                        { value: 'tous', label: 'Tous véhicules' },
                        { value: 'interne', label: 'Interne' },
                        { value: 'externe', label: 'Externe' },
                    ]"
                    option-label="label"
                    option-value="value"
                    class="w-40"
                />
            </DataTableFilters>

            <!-- Tableau -->
            <DataTable
                :value="equipesFiltrees"
                striped-rows
                :rows="25"
                :paginator="equipes.length > 25"
                class="rounded-xl border bg-card shadow-sm"
                :table-style="{ tableLayout: 'fixed', width: '100%' }"
            >
                <template #empty>
                    <div
                        class="py-16 text-center text-sm text-muted-foreground"
                    >
                        Aucune équipe trouvée.
                    </div>
                </template>

                <Column field="nom" header="Équipe" sortable style="width: 18%">
                    <template #body="{ data }">
                        <div class="truncate font-medium" :title="data.nom">
                            {{ data.nom }}
                        </div>
                    </template>
                </Column>

                <Column
                    field="premier_chauffeur_nom"
                    header="Chauffeur"
                    sortable
                    style="width: 26%"
                >
                    <template #body="{ data }">
                        <template v-if="data.premier_chauffeur_nom">
                            <div
                                class="truncate text-sm font-medium"
                                :title="data.premier_chauffeur_nom"
                            >
                                {{ data.premier_chauffeur_nom }}
                            </div>
                            <div
                                v-if="data.premier_chauffeur_telephone"
                                class="font-mono text-xs text-muted-foreground"
                            >
                                {{
                                    formatPhoneDisplay(
                                        data.premier_chauffeur_telephone,
                                    )
                                }}
                            </div>
                        </template>
                        <span v-else class="text-xs text-muted-foreground"
                            >— aucun chauffeur</span
                        >
                    </template>
                </Column>

                <Column
                    field="vehicule_nom"
                    header="Véhicule"
                    sortable
                    style="width: 20%"
                >
                    <template #body="{ data }">
                        <template v-if="data.vehicule_nom">
                            <div
                                class="truncate text-sm font-medium"
                                :title="data.vehicule_nom"
                            >
                                {{ data.vehicule_nom }}
                            </div>
                            <div
                                v-if="data.vehicule_immatriculation"
                                class="font-mono text-xs text-muted-foreground"
                            >
                                {{ data.vehicule_immatriculation }}
                            </div>
                        </template>
                        <span v-else class="text-xs text-muted-foreground"
                            >—</span
                        >
                    </template>
                </Column>

                <Column
                    field="nb_membres"
                    header="Membre"
                    sortable
                    style="width: 10%"
                >
                    <template #body="{ data }">
                        <div
                            class="flex items-center gap-1.5 text-sm text-muted-foreground"
                        >
                            <Users class="h-3.5 w-3.5" />
                            {{ data.nb_membres }}
                        </div>
                    </template>
                </Column>

                <Column
                    field="somme_taux"
                    header="Σ Taux équipe"
                    sortable
                    style="width: 11%"
                >
                    <template #body="{ data }">
                        <span
                            class="font-mono text-sm"
                            :class="
                                data.somme_taux > 100
                                    ? 'text-destructive'
                                    : 'text-muted-foreground'
                            "
                        >
                            {{ data.somme_taux }}%
                        </span>
                    </template>
                </Column>

                <Column
                    field="is_active"
                    header="Statut"
                    sortable
                    style="width: 10%"
                >
                    <template #body="{ data }">
                        <StatusDot
                            :label="data.is_active ? 'Actif' : 'Inactif'"
                            :dot-class="
                                data.is_active
                                    ? 'bg-emerald-500'
                                    : 'bg-zinc-400'
                            "
                            class="text-muted-foreground"
                        />
                    </template>
                </Column>

                <Column
                    v-if="
                        can('equipes-livraison.read') ||
                        can('equipes-livraison.update') ||
                        can('equipes-livraison.delete')
                    "
                    style="width: 4%"
                >
                    <template #body="{ data }">
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
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem
                                    v-if="can('equipes-livraison.read')"
                                    as-child
                                >
                                    <Link
                                        :href="`/equipes-livraison/${data.id}`"
                                        class="flex items-center gap-2"
                                    >
                                        <Eye class="h-4 w-4" />
                                        Détail
                                    </Link>
                                </DropdownMenuItem>
                                <DropdownMenuSeparator
                                    v-if="
                                        can('equipes-livraison.read') &&
                                        (can('equipes-livraison.update') ||
                                            can('equipes-livraison.delete'))
                                    "
                                />
                                <DropdownMenuItem
                                    v-if="can('equipes-livraison.update')"
                                    as-child
                                >
                                    <Link
                                        :href="`/equipes-livraison/${data.id}/edit`"
                                        class="flex items-center gap-2"
                                    >
                                        <Pencil class="h-4 w-4" />
                                        Modifier
                                    </Link>
                                </DropdownMenuItem>
                                <DropdownMenuSeparator
                                    v-if="
                                        can('equipes-livraison.update') &&
                                        can('equipes-livraison.delete')
                                    "
                                />
                                <DropdownMenuItem
                                    v-if="can('equipes-livraison.delete')"
                                    class="text-destructive focus:text-destructive"
                                    @click="confirmDelete(data)"
                                >
                                    <Trash2 class="mr-2 h-4 w-4" />
                                    Supprimer
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </template>
                </Column>
            </DataTable>
        </div>
    </AppLayout>
</template>
