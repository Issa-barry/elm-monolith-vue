<script setup lang="ts">
// RH → Absences (docs/rh-absences.md) : liste paginée et filtrée par le serveur (DataFilters en
// mode trigger-only, standard A2). Les actions de chaque ligne sont calculées par le serveur.
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import InfoTooltip from '@/components/InfoTooltip.vue';
import ListPageActions from '@/components/ListPageActions.vue';
import GestionAbsences from '@/components/rh/absences/GestionAbsences.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useFlashToast } from '@/composables/useFlashToast';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { libelleDuree, type AbsenceRow, type Option } from '@/types/absence';
import { Head, Link } from '@inertiajs/vue3';
import {
    CalendarOff,
    ChevronLeft,
    ChevronRight,
    Eye,
    MoreHorizontal,
    Paperclip,
    Pencil,
    Plus,
    XCircle,
} from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import { computed, ref } from 'vue';

interface Paginator {
    data: AbsenceRow[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    total: number;
}

const props = defineProps<{
    absences: Paginator;
    filters: {
        recherche?: string;
        motif_id?: string;
        auteur_id?: string;
        statut?: string;
        site_ids?: string[];
        periode?: string;
        date_debut?: string;
        date_fin?: string;
    };
    sites: { id: string; nom: string }[];
    motifs: Option[];
    auteurs: Option[];
    statuts: Option[];
    motifs_saisissables: Option[];
    salaries: Option[];
}>();

useFlashToast();
const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Absences', href: '/backoffice/absences' },
];

const gestion = ref<InstanceType<typeof GestionAbsences> | null>(null);

const filterValues = computed(() => ({
    statut: props.filters.statut ?? '',
    recherche: props.filters.recherche ?? '',
    periode: props.filters.periode ?? 'tout',
    date_debut: props.filters.date_debut ?? '',
    date_fin: props.filters.date_fin ?? '',
    motif_id: props.filters.motif_id ?? '',
    auteur_id: props.filters.auteur_id ?? '',
    site_ids: props.filters.site_ids ?? [],
}));

// Ordre A2 : Agence (sélecteur de DataFilters) → Statut → Recherche → Période → autres filtres.
const filterFields = computed<FilterField[]>(() => [
    {
        key: 'statut',
        label: 'Statut',
        type: 'select',
        options: [
            { value: '', label: 'Toutes (hors annulées)' },
            ...props.statuts,
        ],
    },
    {
        key: 'recherche',
        label: 'Salarié',
        type: 'text',
        placeholder: 'Nom, prénom ou matricule…',
    },
    { key: 'periode', label: 'Période', type: 'period', defaultValue: 'tout' },
    {
        key: 'motif_id',
        label: 'Motif',
        type: 'select',
        options: [{ value: '', label: 'Tous les motifs' }, ...props.motifs],
    },
    {
        key: 'auteur_id',
        label: 'Auteur de la saisie',
        type: 'select',
        searchable: true,
        options: [{ value: '', label: 'Tous les auteurs' }, ...props.auteurs],
    },
]);

const peutCreer = computed(
    () => can('rh-absences.create') && props.salaries.length > 0,
);

const sousTitre = computed(() => {
    const n = props.absences.total;
    return `${n} absence${n > 1 ? 's' : ''}`;
});

function formatPaginationLabel(label: string) {
    const el = document.createElement('div');
    el.innerHTML = label;
    return el.textContent?.trim() ?? label.trim();
}

function ouvrir(a: AbsenceRow) {
    gestion.value?.ouvrir(a.id);
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Absences" />

        <div class="w-full space-y-5 p-4 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-xl font-semibold">Absences</h1>
                        <InfoTooltip label="À propos des absences">
                            Absences des salariés saisies par RH ou par le
                            responsable de l’agence : motif, dates, commentaire
                            et justificatif. La durée est comptée en jours
                            calendaires, dates de début et de fin incluses. Une
                            absence annulée n’est jamais supprimée : elle reste
                            consultable avec le filtre « Annulées ». Les congés
                            payés ne sont pas gérés ici.
                        </InfoTooltip>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        {{ sousTitre }}
                    </p>
                </div>
                <ListPageActions>
                    <template #filters>
                        <DataFilters
                            trigger-only
                            url="/backoffice/absences"
                            :values="filterValues"
                            :fields="filterFields"
                            :sites="sites"
                            :result-count="absences.total"
                        />
                    </template>
                    <template v-if="peutCreer" #primary>
                        <Button
                            size="sm"
                            data-testid="absence-nouvelle"
                            @click="gestion?.nouvelle()"
                        >
                            <Plus class="mr-1.5 h-3.5 w-3.5" />
                            Nouvelle absence
                        </Button>
                    </template>
                </ListPageActions>
            </div>

            <!-- Mobile : cartes -->
            <div class="space-y-3 sm:hidden" data-testid="absences-mobile">
                <button
                    v-for="a in absences.data"
                    :key="a.id"
                    type="button"
                    class="w-full rounded-xl border bg-card p-4 text-left"
                    @click="ouvrir(a)"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="truncate font-medium">{{ a.salarie }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ a.motif }}
                                <template v-if="a.agence">
                                    · {{ a.agence }}</template
                                >
                            </p>
                        </div>
                        <StatusDot
                            :status="a.etat"
                            :label="a.etat_label"
                            size="sm"
                        />
                    </div>
                    <p class="mt-2 text-sm">
                        Du {{ a.date_debut_label }} au {{ a.date_fin_label }}
                        <span class="text-muted-foreground"
                            >· {{ libelleDuree(a.duree_jours) }}</span
                        >
                    </p>
                </button>
                <p
                    v-if="absences.data.length === 0"
                    class="py-10 text-center text-sm text-muted-foreground"
                >
                    Aucune absence pour ces filtres.
                </p>
            </div>

            <!-- Tablette et ordinateur : tableau -->
            <div
                class="hidden overflow-hidden rounded-xl border bg-card sm:block"
            >
                <DataTable
                    :value="absences.data"
                    data-key="id"
                    striped-rows
                    row-hover
                    scrollable
                    class="text-sm"
                    :pt="{
                        root: { class: 'w-full' },
                        bodyRow: { class: 'cursor-pointer' },
                    }"
                    data-testid="absences-table"
                    @row-click="(e) => ouvrir(e.data)"
                >
                    <Column header="Salarié" style="min-width: 170px">
                        <template #body="{ data }">
                            <p class="font-medium">{{ data.salarie }}</p>
                        </template>
                    </Column>
                    <Column
                        header="Matricule"
                        body-class="whitespace-nowrap text-muted-foreground tabular-nums"
                    >
                        <template #body="{ data }">{{
                            data.matricule ?? '—'
                        }}</template>
                    </Column>
                    <Column header="Agence" body-class="whitespace-nowrap">
                        <template #body="{ data }">{{
                            data.agence ?? '—'
                        }}</template>
                    </Column>
                    <Column header="Motif" style="min-width: 140px">
                        <template #body="{ data }">
                            <span class="inline-flex items-center gap-1.5">
                                {{ data.motif }}
                                <Paperclip
                                    v-if="data.a_justificatif"
                                    class="h-3.5 w-3.5 text-muted-foreground"
                                    aria-label="Justificatif joint"
                                />
                            </span>
                        </template>
                    </Column>
                    <Column
                        header="Début"
                        body-class="whitespace-nowrap tabular-nums"
                    >
                        <template #body="{ data }">{{
                            data.date_debut_label
                        }}</template>
                    </Column>
                    <Column
                        header="Fin"
                        body-class="whitespace-nowrap tabular-nums"
                    >
                        <template #body="{ data }">{{
                            data.date_fin_label
                        }}</template>
                    </Column>
                    <Column
                        header="Durée"
                        body-class="whitespace-nowrap tabular-nums"
                    >
                        <template #body="{ data }">{{
                            libelleDuree(data.duree_jours)
                        }}</template>
                    </Column>
                    <Column header="Statut" body-class="whitespace-nowrap">
                        <template #body="{ data }">
                            <StatusDot
                                :status="data.etat"
                                :label="data.etat_label"
                            />
                        </template>
                    </Column>
                    <Column header="Commentaire" style="max-width: 220px">
                        <template #body="{ data }">
                            <p
                                class="truncate text-muted-foreground"
                                :title="data.commentaire ?? ''"
                            >
                                {{ data.commentaire ?? '—' }}
                            </p>
                        </template>
                    </Column>
                    <Column header="Saisie" body-class="whitespace-nowrap">
                        <template #body="{ data }">
                            <p>{{ data.auteur ?? '—' }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ data.saisie_le }}
                            </p>
                        </template>
                    </Column>
                    <Column style="width: 3rem">
                        <template #body="{ data }">
                            <div @click.stop>
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="h-8 w-8"
                                            aria-label="Actions"
                                        >
                                            <MoreHorizontal class="h-4 w-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent
                                        align="end"
                                        class="w-48"
                                    >
                                        <DropdownMenuItem @click="ouvrir(data)">
                                            <Eye class="mr-2 h-4 w-4" />
                                            Voir le détail
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            v-if="data.peut_modifier"
                                            @click="gestion?.modifier(data)"
                                        >
                                            <Pencil class="mr-2 h-4 w-4" />
                                            Modifier
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            v-if="data.peut_annuler"
                                            class="text-destructive"
                                            @click="gestion?.annuler(data)"
                                        >
                                            <XCircle class="mr-2 h-4 w-4" />
                                            Annuler
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </template>
                    </Column>

                    <template #empty>
                        <div
                            class="flex flex-col items-center gap-3 py-12 text-muted-foreground"
                        >
                            <CalendarOff class="h-10 w-10 opacity-30" />
                            <p class="text-sm">
                                Aucune absence pour ces filtres.
                            </p>
                        </div>
                    </template>
                </DataTable>
            </div>

            <!-- Pagination -->
            <div
                v-if="absences.last_page > 1"
                class="flex flex-wrap items-center justify-center gap-1"
            >
                <template v-for="link in absences.links" :key="link.label">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        preserve-scroll
                        class="inline-flex h-11 min-w-11 items-center justify-center rounded-md border px-2 text-sm transition-colors hover:bg-muted sm:h-8 sm:min-w-8"
                        :class="{
                            'border-primary bg-primary text-primary-foreground hover:bg-primary/90':
                                link.active,
                        }"
                    >
                        <ChevronLeft
                            v-if="
                                link.label.includes('Précédent') ||
                                link.label.includes('&laquo')
                            "
                            class="h-4 w-4"
                        />
                        <ChevronRight
                            v-else-if="
                                link.label.includes('Suivant') ||
                                link.label.includes('&raquo')
                            "
                            class="h-4 w-4"
                        />
                        <span v-else>{{
                            formatPaginationLabel(link.label)
                        }}</span>
                    </Link>
                    <span
                        v-else
                        class="inline-flex h-11 min-w-11 items-center justify-center rounded-md border px-2 text-sm opacity-40 sm:h-8 sm:min-w-8"
                    >
                        <ChevronLeft
                            v-if="
                                link.label.includes('Précédent') ||
                                link.label.includes('&laquo')
                            "
                            class="h-4 w-4"
                        />
                        <ChevronRight
                            v-else-if="
                                link.label.includes('Suivant') ||
                                link.label.includes('&raquo')
                            "
                            class="h-4 w-4"
                        />
                        <span v-else>{{
                            formatPaginationLabel(link.label)
                        }}</span>
                    </span>
                </template>
            </div>
        </div>

        <GestionAbsences
            ref="gestion"
            :motifs="motifs_saisissables"
            :salaries="salaries"
        />
    </AppLayout>
</template>
