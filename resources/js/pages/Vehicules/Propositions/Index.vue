<script setup lang="ts">
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { CheckCircle, Eye } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Proposition {
    id: string;
    nom_contact: string | null;
    telephone_contact: string | null;
    nom_vehicule: string | null;
    immatriculation: string;
    type_vehicule: string | null;
    statut: string | null;
    statut_label: string;
    statut_color: string;
    created_at_label: string | null;
    photo_url: string | null;
}

interface StatutOption {
    value: string;
    label: string;
    color: string;
}

const props = defineProps<{
    propositions: Proposition[];
    statuts: StatutOption[];
    filters: {
        statut: string | null;
        date_debut: string | null;
        date_fin: string | null;
    };
}>();

const page = usePage();
const flashSuccess = computed(
    () => (page.props as { flash?: { success?: string } }).flash?.success,
);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Véhicules', href: '/vehicules' },
    { title: 'Propositions', href: '#' },
];

const search = ref('');

const filterFields: FilterField[] = [
    {
        key: 'statut',
        label: 'Statut',
        type: 'select',
        options: props.statuts.map((s) => ({ value: s.value, label: s.label })),
        placeholder: 'Tous',
    },
    {
        key: 'date',
        label: 'Date',
        type: 'date-range',
        startKey: 'date_debut',
        endKey: 'date_fin',
    },
];

const colorClasses: Record<string, string> = {
    amber: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
    blue: 'bg-blue-500/15 text-blue-700 dark:text-blue-300',
    orange: 'bg-orange-500/15 text-orange-700 dark:text-orange-300',
    red: 'bg-red-500/15 text-red-700 dark:text-red-300',
    emerald: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    gray: 'bg-gray-500/15 text-gray-700 dark:text-gray-300',
};
</script>

<template>
    <Head title="Propositions de véhicules" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-6xl space-y-6 p-4 sm:p-6">
            <!-- En-tête -->
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Propositions de véhicules
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ propositions.length }} proposition(s)
                    </p>
                </div>
            </div>

            <!-- Flash -->
            <div
                v-if="flashSuccess"
                class="flex items-center gap-2 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-300"
            >
                <CheckCircle class="h-4 w-4 shrink-0" />
                {{ flashSuccess }}
            </div>

            <!-- Filtres -->
            <DataFilters
                url="/vehicules/propositions"
                :values="filters"
                :fields="filterFields"
                :result-count="propositions.length"
                v-model:search="search"
            />

            <!-- Table -->
            <div class="rounded-xl border bg-card shadow-sm">
                <div
                    v-if="propositions.length === 0"
                    class="py-16 text-center text-sm text-muted-foreground"
                >
                    Aucune proposition pour les critères sélectionnés.
                </div>

                <div v-else class="divide-y">
                    <!-- Header (desktop) -->
                    <div
                        class="hidden grid-cols-[1fr_1fr_130px_110px_44px] gap-4 px-4 py-3 text-xs font-semibold tracking-wider text-muted-foreground uppercase sm:grid"
                    >
                        <span>Contact / Partenaire</span>
                        <span>Véhicule</span>
                        <span>Immatriculation</span>
                        <span>Statut</span>
                        <span />
                    </div>

                    <div
                        v-for="p in propositions"
                        :key="p.id"
                        class="grid grid-cols-1 gap-2 px-4 py-4 transition-colors hover:bg-muted/30 sm:grid-cols-[1fr_1fr_130px_110px_44px] sm:items-center sm:gap-4 sm:py-3"
                    >
                        <!-- Contact -->
                        <div>
                            <p class="font-medium text-foreground">
                                {{ p.nom_contact ?? '—' }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ p.telephone_contact ?? '' }}
                            </p>
                        </div>

                        <!-- Véhicule -->
                        <div>
                            <p class="text-sm text-foreground">
                                {{ p.nom_vehicule ?? '—' }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ p.type_vehicule ?? '' }}
                                <span v-if="p.created_at_label">
                                    · {{ p.created_at_label }}
                                </span>
                            </p>
                        </div>

                        <!-- Immatriculation -->
                        <p class="font-mono text-sm uppercase">
                            {{ p.immatriculation }}
                        </p>

                        <!-- Statut -->
                        <span
                            class="inline-block rounded-full px-2.5 py-1 text-xs font-medium"
                            :class="
                                colorClasses[p.statut_color] ??
                                colorClasses['gray']
                            "
                        >
                            {{ p.statut_label }}
                        </span>

                        <!-- Action -->
                        <Link
                            :href="`/vehicules/propositions/${p.id}`"
                            class="inline-flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                        >
                            <Eye class="h-4 w-4" />
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
