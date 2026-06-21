<script setup lang="ts">
import DataFilters, { type FilterField } from '@/components/filters/DataFilters.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPhoneDisplay } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { CheckCircle, Users } from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import { computed, ref } from 'vue';

interface EquipeRef {
    id: number;
    nom: string;
    role: string;
}

interface Livreur {
    id: string;
    nom: string;
    prenom: string;
    nom_complet: string;
    telephone: string | null;
    is_active: boolean;
    has_account: boolean;
    equipes: EquipeRef[];
}

const props = defineProps<{ livreurs: Livreur[] }>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Équipes de livraison', href: '/equipes-livraison' },
    { title: 'Livreurs', href: '/livreurs' },
];

const search = ref('');
const statutFilter = ref<'tous' | 'actif' | 'inactif' | 'pending'>('tous');

const filterFields: FilterField[] = [
    {
        key: 'statut',
        label: 'Statut',
        type: 'select',
        options: [
            { value: 'tous', label: 'Tous' },
            { value: 'actif', label: 'Actifs' },
            { value: 'inactif', label: 'Inactifs' },
            { value: 'pending', label: 'En attente' },
        ],
    },
];

function resetFilters() {
    search.value = '';
    statutFilter.value = 'tous';
}

const pendingCount = computed(
    () => props.livreurs.filter((l) => l.has_account && !l.is_active).length,
);

const livreursFiltres = computed(() => {
    let list = props.livreurs;
    if (statutFilter.value === 'actif') list = list.filter((l) => l.is_active);
    else if (statutFilter.value === 'inactif')
        list = list.filter((l) => !l.is_active);
    else if (statutFilter.value === 'pending')
        list = list.filter((l) => l.has_account && !l.is_active);
    const q = search.value.toLowerCase().trim();
    if (!q) return list;
    return list.filter(
        (l) =>
            l.nom_complet.toLowerCase().includes(q) ||
            (l.telephone ?? '')
                .replace(/\D/g, '')
                .includes(q.replace(/\D/g, '')),
    );
});

function approuver(livreur: Livreur) {
    router.patch(
        `/livreurs/${livreur.id}/approuver`,
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head>
        <title>Livreurs</title>
    </Head>

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4 sm:p-6">
            <!-- En-tête -->
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Livreurs
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ livreursFiltres.length }} livreur{{
                            livreursFiltres.length !== 1 ? 's' : ''
                        }}
                        — Gérez les livreurs depuis les
                        <Link
                            href="/equipes-livraison"
                            class="underline underline-offset-2 hover:text-foreground"
                        >
                            Équipes de livraison </Link
                        >.
                    </p>
                </div>
                <Link
                    v-if="can('equipes-livraison.read')"
                    href="/equipes-livraison"
                >
                    <Button variant="outline">
                        <Users class="mr-2 h-4 w-4" />
                        Gérer les équipes
                    </Button>
                </Link>
            </div>

            <!-- Alerte inscriptions en attente -->
            <div
                v-if="pendingCount > 0"
                class="flex items-center gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
            >
                <CheckCircle class="h-4 w-4 shrink-0" />
                <span>
                    <strong>{{ pendingCount }}</strong> livreur{{
                        pendingCount > 1 ? 's' : ''
                    }}
                    en attente de validation.
                    <button
                        class="underline underline-offset-2"
                        @click="statutFilter = 'pending'"
                    >
                        Voir
                    </button>
                </span>
            </div>

            <!-- Filtres -->
            <DataFilters
                v-model:search="search"
                search-placeholder="Nom, téléphone…"
                :values="{ statut: statutFilter }"
                :fields="filterFields"
                :result-count="livreursFiltres.length"
                @apply="(vals) => { statutFilter.value = (vals.statut as 'tous' | 'actif' | 'inactif' | 'pending') || 'tous' }"
                @reset="resetFilters"
            />

            <!-- Tableau -->
            <DataTable
                :value="livreursFiltres"
                striped-rows
                :rows="30"
                :paginator="livreursFiltres.length > 30"
                class="rounded-xl border bg-card shadow-sm"
            >
                <template #empty>
                    <div
                        class="py-16 text-center text-sm text-muted-foreground"
                    >
                        Aucun livreur trouvé.
                    </div>
                </template>

                <Column field="nom_complet" header="Livreur" sortable>
                    <template #body="{ data }">
                        <div class="flex items-center gap-2">
                            <div>
                                <div class="font-medium">
                                    {{ data.nom_complet }}
                                </div>
                                <div
                                    v-if="data.telephone"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ formatPhoneDisplay(data.telephone) }}
                                </div>
                            </div>
                            <span
                                v-if="data.has_account && !data.is_active"
                                class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-700 dark:bg-amber-900 dark:text-amber-300"
                            >
                                En attente
                            </span>
                        </div>
                    </template>
                </Column>

                <Column header="Équipes">
                    <template #body="{ data }">
                        <div
                            v-if="data.equipes.length"
                            class="flex flex-wrap gap-1.5"
                        >
                            <Link
                                v-for="eq in data.equipes"
                                :key="eq.id"
                                :href="`/equipes-livraison/${eq.id}/edit`"
                                class="inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-xs hover:bg-muted"
                            >
                                {{ eq.nom }}
                                <span
                                    class="rounded-sm px-1 text-[10px] font-semibold"
                                    :class="
                                        eq.role === 'principal'
                                            ? 'bg-primary/10 text-primary'
                                            : 'bg-muted text-muted-foreground'
                                    "
                                >
                                    {{ eq.role }}
                                </span>
                            </Link>
                        </div>
                        <span v-else class="text-xs text-muted-foreground"
                            >— aucune équipe</span
                        >
                    </template>
                </Column>

                <Column
                    field="is_active"
                    header="Statut"
                    sortable
                    style="width: 110px"
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

                <Column header="Actions" style="width: 120px">
                    <template #body="{ data }">
                        <Button
                            v-if="
                                data.has_account &&
                                !data.is_active &&
                                can('livreurs.update')
                            "
                            size="sm"
                            variant="outline"
                            class="gap-1.5 text-emerald-700 hover:border-emerald-300 hover:bg-emerald-50 dark:text-emerald-400"
                            @click="approuver(data)"
                        >
                            <CheckCircle class="h-3.5 w-3.5" />
                            Approuver
                        </Button>
                    </template>
                </Column>
            </DataTable>
        </div>
    </AppLayout>
</template>
