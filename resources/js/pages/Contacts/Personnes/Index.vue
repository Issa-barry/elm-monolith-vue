<script setup lang="ts">
/**
 * Liste « Personnes physiques » du référentiel Contacts (ADR 0060, lots 2 et 3).
 * Le serveur ne renvoie que les personnes et les fonctions visibles de l'utilisateur.
 */
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import ListPageActions from '@/components/ListPageActions.vue';
import { Button } from '@/components/ui/button';
import { useClickableTableRow } from '@/composables/useClickableTableRow';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPhoneDisplay } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import { computed } from 'vue';
import BadgesFonctions from '../partials/BadgesFonctions.vue';
import PaginationContacts from '../partials/PaginationContacts.vue';
import type {
    OptionsFiltres,
    Pagination,
    PersonneListe,
} from '../partials/types';

const props = defineProps<{
    personnes: Pagination<PersonneListe>;
    filters: Record<string, unknown>;
    options: OptionsFiltres;
    /** contacts.create et au moins une fonction créable (calculé par le serveur). */
    peut_creer: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Personnes physiques', href: '/backoffice/contacts/personnes' },
];

const lien = (p: PersonneListe) => `/backoffice/contacts/personnes/${p.id}`;
const { onRowClick, bodyRowPt } = useClickableTableRow<PersonneListe>(lien);

const filterFields = computed<FilterField[]>(() => [
    {
        key: 'fonction',
        label: 'Fonction',
        type: 'select',
        inline: true,
        options: props.options.fonctions,
    },
    {
        key: 'recherche',
        label: 'Rechercher',
        type: 'text',
        inline: true,
        placeholder: 'Nom, téléphone, e-mail...',
    },
    {
        key: 'sous_categorie',
        label: 'Sous-catégorie',
        type: 'select',
        options: props.options.sous_categories,
    },
    {
        key: 'ville',
        label: 'Ville',
        type: 'text',
        placeholder: 'Ville',
    },
]);

function telephone(p: PersonneListe): string {
    return formatPhoneDisplay(p.telephone, p.code_phone_pays) ?? '—';
}

function localisation(p: PersonneListe): string {
    return [p.quartier, p.ville].filter(Boolean).join(', ') || '—';
}
</script>

<template>
    <Head title="Personnes physiques" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-4 p-4 sm:gap-6 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Personnes physiques
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ personnes.total }} personne{{
                            personnes.total !== 1 ? 's' : ''
                        }}. Une seule fiche par personne, avec toutes ses
                        fonctions.
                    </p>
                </div>
                <ListPageActions>
                    <template #primary>
                        <Link
                            v-if="peut_creer"
                            href="/backoffice/contacts/nouveau?type=personne"
                        >
                            <Button
                                size="sm"
                                class="h-9 px-3 sm:px-4"
                                data-testid="contact-nouveau"
                            >
                                <Plus class="mr-1 h-4 w-4 sm:mr-2" />
                                Nouveau contact
                            </Button>
                        </Link>
                    </template>
                </ListPageActions>
            </div>

            <DataFilters
                url="/backoffice/contacts/personnes"
                :values="filters"
                :fields="filterFields"
                :result-count="personnes.total"
            />

            <!-- ── Mobile ───────────────────────────────────────────────── -->
            <div class="divide-y rounded-xl border bg-card sm:hidden">
                <Link
                    v-for="p in personnes.data"
                    :key="p.id"
                    :href="lien(p)"
                    class="block space-y-1.5 px-4 py-3.5 active:bg-muted/40"
                    data-testid="personne-carte"
                >
                    <p class="truncate text-sm font-medium">{{ p.nom }}</p>
                    <p class="text-xs text-muted-foreground tabular-nums">
                        {{ telephone(p) }} · {{ localisation(p) }}
                    </p>
                    <BadgesFonctions :fonctions="p.fonctions" />
                </Link>
                <p
                    v-if="personnes.data.length === 0"
                    class="py-12 text-center text-sm text-muted-foreground"
                >
                    Aucune personne trouvée.
                </p>
            </div>

            <!-- ── Desktop ──────────────────────────────────────────────── -->
            <div
                class="hidden overflow-hidden rounded-xl border bg-card sm:block"
            >
                <DataTable
                    :value="personnes.data"
                    data-key="id"
                    striped-rows
                    class="text-sm"
                    table-class="w-full"
                    :pt="{ bodyRow: bodyRowPt }"
                    @row-click="onRowClick"
                >
                    <Column header="Personne" style="min-width: 220px">
                        <template #body="{ data }">
                            <Link
                                :href="lien(data)"
                                class="font-medium hover:underline"
                                data-testid="personne-nom"
                            >
                                {{ data.nom }}
                            </Link>
                        </template>
                    </Column>
                    <Column header="Téléphone" style="width: 180px">
                        <template #body="{ data }">
                            <span
                                class="whitespace-nowrap text-muted-foreground tabular-nums"
                                >{{ telephone(data) }}</span
                            >
                        </template>
                    </Column>
                    <Column header="Localisation" style="min-width: 160px">
                        <template #body="{ data }">
                            <span class="text-muted-foreground">{{
                                localisation(data)
                            }}</span>
                        </template>
                    </Column>
                    <Column header="Fonctions" style="min-width: 260px">
                        <template #body="{ data }">
                            <BadgesFonctions :fonctions="data.fonctions" />
                        </template>
                    </Column>
                    <template #empty>
                        <p
                            class="py-10 text-center text-sm text-muted-foreground"
                        >
                            Aucune personne trouvée.
                        </p>
                    </template>
                </DataTable>
            </div>

            <PaginationContacts :page="personnes" />
        </div>
    </AppLayout>
</template>
