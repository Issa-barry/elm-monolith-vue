<script setup lang="ts">
import DataFilters, { type FilterField } from '@/components/filters/DataFilters.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface TypeVehiculeRow {
    id: string;
    nom: string;
    capacite_defaut: number;
    unite_capacite: string;
    description: string | null;
    is_active: boolean;
    vehicules_count: number;
}

const props = defineProps<{ types: TypeVehiculeRow[] }>();

const page = usePage();
const flash = computed(
    () =>
        (page.props as { flash?: { success?: string; error?: string } }).flash,
);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Types de véhicules', href: '/type-vehicules' },
];

const search = ref('');

const typesFiltres = computed(() => {
    const q = search.value.toLowerCase().trim();
    if (!q) return props.types;
    return props.types.filter(
        (t) =>
            t.nom.toLowerCase().includes(q) ||
            (t.description && t.description.toLowerCase().includes(q)),
    );
});

const filterFields: FilterField[] = [
    {
        key: 'is_active',
        label: 'Statut',
        type: 'boolean',
    },
];

function destroy(id: string) {
    if (confirm('Supprimer ce type de véhicule ?')) {
        router.delete(`/type-vehicules/${id}`);
    }
}
</script>

<template>
    <Head title="Types de véhicules" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-5xl space-y-6 p-4 sm:p-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Types de véhicules
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Gérez les types de véhicules disponibles dans votre
                        flotte.
                    </p>
                </div>
                <Link href="/type-vehicules/create">
                    <button
                        class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow-sm transition-colors hover:bg-primary/90"
                    >
                        <Plus class="h-4 w-4" />
                        Nouveau type
                    </button>
                </Link>
            </div>

            <div
                v-if="flash?.success"
                class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"
            >
                {{ flash.success }}
            </div>
            <div
                v-if="flash?.error"
                class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"
            >
                {{ flash.error }}
            </div>

            <DataFilters
                v-model:search="search"
                search-placeholder="Nom, description…"
                :result-count="typesFiltres.length"
                :fields="filterFields"
            />

            <div class="rounded-xl border bg-card shadow-sm">
                <table class="w-full text-sm">
                    <thead>
                        <tr
                            class="border-b text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            <th class="px-4 py-3">Nom</th>
                            <th class="px-4 py-3 text-right">
                                Capacité défaut
                            </th>
                            <th class="px-4 py-3 text-center">Statut</th>
                            <th class="px-4 py-3 text-center">Véhicules</th>
                            <th class="px-4 py-3" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="type in typesFiltres"
                            :key="type.id"
                            class="border-b last:border-0 hover:bg-muted/30"
                        >
                            <td class="px-4 py-3 font-medium">
                                {{ type.nom }}
                                <p
                                    v-if="type.description"
                                    class="mt-0.5 text-xs font-normal text-muted-foreground"
                                >
                                    {{ type.description }}
                                </p>
                            </td>
                            <td class="px-4 py-3 text-right font-mono">
                                {{ type.capacite_defaut }}
                                {{ type.unite_capacite }}
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span
                                    :class="
                                        type.is_active
                                            ? 'bg-green-100 text-green-800'
                                            : 'bg-gray-100 text-gray-500'
                                    "
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                >
                                    {{ type.is_active ? 'Actif' : 'Inactif' }}
                                </span>
                            </td>
                            <td
                                class="px-4 py-3 text-center text-muted-foreground"
                            >
                                {{ type.vehicules_count }}
                            </td>
                            <td class="px-4 py-3">
                                <div
                                    class="flex items-center justify-end gap-2"
                                >
                                    <Link
                                        :href="`/type-vehicules/${type.id}/edit`"
                                    >
                                        <button
                                            class="rounded-md p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                        >
                                            <Pencil class="h-4 w-4" />
                                        </button>
                                    </Link>
                                    <button
                                        :disabled="type.vehicules_count > 0"
                                        class="rounded-md p-1.5 text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive disabled:cursor-not-allowed disabled:opacity-40"
                                        @click="destroy(type.id)"
                                    >
                                        <Trash2 class="h-4 w-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="typesFiltres.length === 0">
                            <td
                                colspan="5"
                                class="px-4 py-10 text-center text-sm text-muted-foreground"
                            >
                                Aucun type de véhicule trouvé.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
