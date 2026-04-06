<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPhoneDisplay } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { Users } from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputText from 'primevue/inputtext';
import { computed, ref, watch } from 'vue';

interface EquipeRef {
    id: number;
    nom: string;
    role: string;
}

interface Livreur {
    id: number;
    nom: string;
    prenom: string;
    nom_complet: string;
    telephone: string | null;
    is_active: boolean;
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
const statutFilter = ref<'tous' | 'actif' | 'inactif'>('tous');
const filters = ref({ global: { value: '', matchMode: 'contains' } });
watch(search, (val) => {
    filters.value.global.value = val;
});

const livreursFiltres = computed(() => {
    const byStatut =
        statutFilter.value === 'tous'
            ? props.livreurs
            : props.livreurs.filter((l) =>
                  statutFilter.value === 'actif' ? l.is_active : !l.is_active,
              );
    return byStatut;
});
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
                            Équipes de livraison</Link
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

            <!-- Filtres -->
            <div class="flex flex-wrap items-center gap-3">
                <IconField class="max-w-xs flex-1">
                    <InputIcon class="pi pi-search" />
                    <InputText
                        v-model="search"
                        placeholder="Rechercher un livreur…"
                        class="w-full"
                    />
                </IconField>
                <div class="flex gap-1.5">
                    <Button
                        v-for="opt in [
                            { value: 'tous', label: 'Tous' },
                            { value: 'actif', label: 'Actif' },
                            { value: 'inactif', label: 'Inactif' },
                        ] as const"
                        :key="opt.value"
                        :variant="
                            statutFilter === opt.value ? 'default' : 'outline'
                        "
                        size="sm"
                        @click="statutFilter = opt.value"
                    >
                        {{ opt.label }}
                    </Button>
                </div>
            </div>

            <!-- Tableau -->
            <DataTable
                :value="livreursFiltres"
                :filters="filters"
                :global-filter-fields="['nom_complet', 'telephone']"
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
                        <div class="font-medium">{{ data.nom_complet }}</div>
                        <div
                            v-if="data.telephone"
                            class="text-xs text-muted-foreground"
                        >
                            {{ formatPhoneDisplay(data.telephone) }}
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
            </DataTable>
        </div>
    </AppLayout>
</template>

