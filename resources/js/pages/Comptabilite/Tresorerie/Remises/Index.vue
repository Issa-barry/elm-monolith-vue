<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatGNF } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, Landmark } from 'lucide-vue-next';
import { computed } from 'vue';

interface Ligne {
    site_id: string;
    site_nom: string;
    /** Reste + en transit + déjà remis : déduit, jamais enregistré (ADR 0016). */
    attendu: number | null;
    deja_remis: number;
    en_transit: number;
    /** Calcul du moment : ce que l'agence doit encore remettre. */
    reste_a_recevoir: number | null;
    remise_obligatoire: number | null;
    excedent_a_remettre: number | null;
    derniere_remise: string | null;
    statut: string;
    statut_label: string;
}

const props = defineProps<{
    central: { id: string; nom: string } | null;
    lignes: Ligne[];
    totaux: {
        attendu: number;
        deja_remis: number;
        en_transit: number;
        reste_a_recevoir: number;
        par_statut: Record<string, number>;
    };
    filters: {
        annee: string;
        mois: string;
        site_ids: string[];
        statut: string;
    };
    sites: { id: string; nom: string }[];
    statut_options: { value: string; label: string }[];
}>();

const URL_REMISES = '/backoffice/comptabilite/tresorerie/remises';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité' },
    { title: 'Remises des agences', href: '#' },
];

const moisNoms = [
    'Janvier',
    'Février',
    'Mars',
    'Avril',
    'Mai',
    'Juin',
    'Juillet',
    'Août',
    'Septembre',
    'Octobre',
    'Novembre',
    'Décembre',
];
const anneeCourante = new Date().getFullYear();

const filterFields = computed((): FilterField[] => [
    {
        key: 'statut',
        label: 'Statut',
        type: 'select',
        inline: true,
        options: props.statut_options,
    },
    {
        key: 'annee',
        label: 'Année',
        type: 'select',
        inline: true,
        options: Array.from({ length: 4 }, (_, i) => {
            const annee = String(anneeCourante + 1 - i);
            return { value: annee, label: annee };
        }),
    },
    {
        key: 'mois',
        label: 'Mois',
        type: 'select',
        inline: true,
        options: moisNoms.map((label, i) => ({ value: String(i + 1), label })),
    },
]);

const periodeLabel = computed(
    () => `${moisNoms[Number(props.filters.mois) - 1]} ${props.filters.annee}`,
);

function dateFr(iso: string | null): string {
    return iso ? new Date(`${iso}T00:00:00`).toLocaleDateString('fr-FR') : '—';
}

function montant(valeur: number | null): string {
    return valeur === null ? '—' : formatGNF(valeur);
}

function detailHref(ligne: Ligne): string {
    return `${URL_REMISES}/${ligne.site_id}?annee=${props.filters.annee}&mois=${props.filters.mois}`;
}

const agencesQuiDoivent = computed(
    () =>
        (props.totaux.par_statut.a_remettre ?? 0) +
        (props.totaux.par_statut.partiellement_remis ?? 0) +
        (props.totaux.par_statut.remise_en_cours ?? 0),
);
</script>

<template>
    <Head title="Remises des agences" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full min-w-0 space-y-6 p-4 sm:p-6">
            <div class="flex flex-col gap-1">
                <h1 class="flex items-center gap-2 text-xl font-semibold">
                    <Landmark
                        class="h-5 w-5 text-muted-foreground"
                        aria-hidden="true"
                    />
                    Remises des agences au Trésor principal
                </h1>
                <p class="text-sm text-muted-foreground">
                    Suivi des montants que les agences doivent remettre
                    {{ central ? `à ${central.nom}` : 'au Trésor principal' }}
                    et des remises déjà effectuées.
                </p>
            </div>

            <p
                v-if="!central"
                class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300"
                data-testid="remises-sans-central"
            >
                Aucun site n'est désigné comme trésorerie principale :
                désignez-le depuis la fiche du site pour suivre les remises.
            </p>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-sm text-muted-foreground">
                        Reste à recevoir
                    </p>
                    <p
                        class="mt-1 text-2xl font-bold text-amber-600 tabular-nums dark:text-amber-400"
                        data-testid="remises-reste"
                    >
                        {{ formatGNF(totaux.reste_a_recevoir) }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ agencesQuiDoivent }} agence(s) concernée(s)
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-sm text-muted-foreground">Déjà remis</p>
                    <p
                        class="mt-1 text-2xl font-bold tabular-nums"
                        data-testid="remises-deja-remis"
                    >
                        {{ formatGNF(totaux.deja_remis) }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        reçu en {{ periodeLabel }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-sm text-muted-foreground">En transit</p>
                    <p
                        class="mt-1 text-2xl font-bold tabular-nums"
                        data-testid="remises-en-transit"
                    >
                        {{ formatGNF(totaux.en_transit) }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        envoyé, réception à confirmer
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-sm text-muted-foreground">Total attendu</p>
                    <p
                        class="mt-1 text-2xl font-bold tabular-nums"
                        data-testid="remises-attendu"
                    >
                        {{ formatGNF(totaux.attendu) }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        reste + en transit + déjà remis
                    </p>
                </div>
            </div>

            <DataFilters
                :url="URL_REMISES"
                :values="filters"
                :fields="filterFields"
                :sites="sites"
                :result-count="lignes.length"
                hide-result-count
            />

            <div
                role="region"
                aria-label="Remises par agence — tableau à défilement horizontal"
                tabindex="0"
                class="w-full max-w-full min-w-0 overflow-x-auto rounded-xl border bg-card"
            >
                <table class="w-max min-w-full text-sm whitespace-nowrap">
                    <thead>
                        <tr class="border-b bg-muted/40 text-left">
                            <th class="px-4 py-3 font-medium">Agence</th>
                            <th class="px-4 py-3 text-right font-medium">
                                Montant attendu
                            </th>
                            <th class="px-4 py-3 text-right font-medium">
                                Déjà remis
                            </th>
                            <th class="px-4 py-3 text-right font-medium">
                                En transit
                            </th>
                            <th
                                class="px-4 py-3 text-right font-semibold text-foreground"
                            >
                                Reste à recevoir
                            </th>
                            <th class="px-4 py-3 font-medium">
                                Dernière remise
                            </th>
                            <th class="px-4 py-3 font-medium">Statut</th>
                            <th class="px-4 py-3">
                                <span class="sr-only">Détail</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="ligne in lignes"
                            :key="ligne.site_id"
                            class="hover:bg-muted/30"
                            data-testid="remise-ligne"
                        >
                            <td class="px-4 py-3 font-medium">
                                {{ ligne.site_nom }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ montant(ligne.attendu) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{
                                    ligne.deja_remis
                                        ? formatGNF(ligne.deja_remis)
                                        : '—'
                                }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{
                                    ligne.en_transit
                                        ? formatGNF(ligne.en_transit)
                                        : '—'
                                }}
                            </td>
                            <td
                                class="px-4 py-3 text-right text-base font-semibold tabular-nums"
                                data-testid="remise-reste"
                                :title="
                                    ligne.reste_a_recevoir
                                        ? `Argent d'autres agences : ${formatGNF(ligne.remise_obligatoire ?? 0)} · Excédent propre : ${formatGNF(ligne.excedent_a_remettre ?? 0)}`
                                        : undefined
                                "
                            >
                                {{ montant(ligne.reste_a_recevoir) }}
                            </td>
                            <td class="px-4 py-3 tabular-nums">
                                {{ dateFr(ligne.derniere_remise) }}
                            </td>
                            <td class="px-4 py-3">
                                <StatusDot
                                    :status="ligne.statut"
                                    :label="ligne.statut_label"
                                />
                            </td>
                            <td class="px-4 py-3 text-right">
                                <Link
                                    :href="detailHref(ligne)"
                                    :aria-label="`Voir les remises de ${ligne.site_nom}`"
                                    class="inline-flex items-center gap-1.5 text-sm font-medium text-primary hover:underline"
                                >
                                    Voir le détail
                                    <ArrowRight
                                        class="h-4 w-4"
                                        aria-hidden="true"
                                    />
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="lignes.length === 0">
                            <td
                                colspan="8"
                                class="px-4 py-10 text-center text-muted-foreground"
                            >
                                Aucune agence ne correspond à ces filtres.
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p
                    class="border-t bg-muted/20 px-4 py-3 text-xs text-muted-foreground"
                >
                    Le reste à recevoir est calculé à l'instant présent ; « Déjà
                    remis » porte sur {{ periodeLabel }}.
                </p>
            </div>
        </div>
    </AppLayout>
</template>
