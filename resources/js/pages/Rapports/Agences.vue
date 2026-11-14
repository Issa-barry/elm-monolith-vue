<script setup lang="ts">
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import InfoTooltip from '@/components/InfoTooltip.vue';
import KpiCard from '@/components/KpiCard.vue';
import ListPageActions from '@/components/ListPageActions.vue';
import StatusDot from '@/components/StatusDot.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { cn, formatQuantite } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowUpRight,
    Building2,
    CalendarDays,
    FileSpreadsheet,
    Landmark,
} from 'lucide-vue-next';
import { computed } from 'vue';
import { dateFr, pluriel } from './partials/format';

// Rapport par agence (docs/rapports.md RAP-011, ADR 0063) : une ligne par agence, consolidée côté
// serveur (RapportAgencesService). « Remis » reprend « Remises des agences » (ADR 0016) : seul le
// remis suit la période ; en transit et reste sont à ce jour, jamais « encaissé − remis ».

interface Moyen {
    cle: string;
    libelle: string;
}

interface Remise {
    remis: number | null;
    en_transit: number | null;
    reste_a_remettre: number | null;
    disponible: number | null;
    derniere_remise: string | null;
    statut: string;
    statut_label: string;
}

interface Agence {
    site_id: string;
    site_nom: string;
    est_tresorerie_principale: boolean;
    ventes_nombre: number;
    ventes_montant: number;
    encaisse_nombre: number;
    encaisse_montant: number;
    pour_autres_agences: number;
    par_moyen: Record<
        string,
        { cle: string; libelle: string; montant: number }
    >;
    dettes_nombre: number;
    dettes_montant: number;
    charges_payees: number;
    charges_payees_nombre: number;
    charges_en_attente: number;
    charges_en_attente_nombre: number;
    charges_avances_tiers: number;
    remise: Remise | null;
}

const props = defineProps<{
    url: string;
    export_url: string;
    filters: {
        periode: string;
        date_debut: string | null;
        date_fin: string | null;
        site_ids: string[];
    };
    periode: {
        cle: string;
        date_debut: string | null;
        date_fin: string | null;
        libelle: string;
        options: { value: string; label: string }[];
    };
    sites: { id: string; nom: string }[];
    rapport: {
        agences: Agence[];
        moyens: Moyen[];
        totaux: {
            ventes_nombre: number;
            ventes_montant: number;
            encaisse_nombre: number;
            encaisse_montant: number;
            pour_autres_agences: number;
            par_moyen: Record<string, number>;
            dettes_nombre: number;
            dettes_montant: number;
            charges_payees: number;
            charges_payees_nombre: number;
            charges_en_attente: number;
            charges_en_attente_nombre: number;
            charges_avances_tiers: number;
            remis: number;
            en_transit: number;
            reste_a_remettre: number;
            reste_incomplet: boolean;
            par_statut: Record<string, number>;
        };
        central: { id: string; nom: string } | null;
    };
    peut_voir_remises: boolean;
}>();

const page = usePage();
const URL_ACTIVITE = '/backoffice/rapports/activite';
const URL_REMISES = '/backoffice/comptabilite/tresorerie/remises';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Rapport par agence', href: props.url },
];

// Même barre que le rapport d'activité : [Agence] → [Période], dans le panneau Filtres.
const filterFields = computed<FilterField[]>(() => [
    {
        key: 'periode',
        label: 'Période',
        type: 'period',
        options: props.periode.options,
        defaultValue: 'aujourd_hui',
    },
]);

const perimetre = computed(() =>
    props.filters.site_ids.length > 0
        ? props.sites
              .filter((s) => props.filters.site_ids.includes(s.id))
              .map((s) => s.nom)
              .join(', ')
        : 'Toutes les agences',
);

const nomCentral = computed(
    () => props.rapport.central?.nom ?? "l'agence principale",
);

const t = computed(() => props.rapport.totaux);

/** Montant sans unité (l'unité GNF est indiquée une fois, en tête du tableau). */
function n(valeur: number | null | undefined): string {
    return valeur === null || valeur === undefined
        ? '—'
        : formatQuantite(valeur);
}

/** Zéro affiché en retrait : l'œil va aux montants non nuls. */
function attenue(valeur: number | null | undefined): string {
    return !valeur ? 'text-muted-foreground/70' : '';
}

function montantMoyen(agence: Agence, cle: string): number {
    return agence.par_moyen?.[cle]?.montant ?? 0;
}

/** Paramètres de période de l'écran (le serveur les résout), réutilisés par les liens. */
function parametresPeriode(): URLSearchParams {
    const courants = new URLSearchParams(
        new URL(page.url, 'http://localhost').search,
    );
    const params = new URLSearchParams();
    for (const cle of ['periode', 'date_debut', 'date_fin']) {
        const valeur = courants.get(cle);
        if (valeur) params.set(cle, valeur);
    }
    return params;
}

function activiteHref(agence: Agence): string {
    const params = parametresPeriode();
    params.append('site_ids[]', agence.site_id);
    return `${URL_ACTIVITE}?${params.toString()}`;
}

function remisesHref(agence: Agence): string {
    return `${URL_REMISES}/${agence.site_id}`;
}

function peutVoirRemises(agence: Agence): boolean {
    return props.peut_voir_remises && !agence.est_tresorerie_principale;
}

const exportHref = computed(() => {
    const params = new URLSearchParams(
        new URL(page.url, 'http://localhost').search,
    );
    return `${props.export_url}?${params.toString()}`;
});

const detailCharges = computed(() =>
    t.value.charges_en_attente > 0
        ? `+ ${n(t.value.charges_en_attente)} en attente de validation`
        : pluriel(
              t.value.charges_payees_nombre,
              'dépense validée',
              'dépenses validées',
          ),
);

const detailReste = computed(() =>
    t.value.reste_incomplet
        ? '⚠ À ce jour · données incomplètes pour au moins une agence'
        : `À ce jour · ${n(t.value.en_transit)} en transit`,
);

// Colonnes du groupe « Activité » : Ventes, Encaissé, moyens, Dettes, Charges.
const colonnesActivite = computed(() => 4 + props.rapport.moyens.length);

const TH = 'px-3 py-2.5 text-right text-xs font-medium whitespace-nowrap';
const TD = 'px-3 py-3 text-right align-top tabular-nums whitespace-nowrap';
const REMISE = 'bg-muted/30';
</script>

<template>
    <Head title="Rapport par agence" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full min-w-0 space-y-5 p-4 sm:p-6">
            <!-- En-tête compact : titre, périmètre et période sur une ligne, actions à droite -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0 space-y-1">
                    <h1 class="flex items-center gap-2 text-xl font-semibold">
                        <Building2
                            class="h-5 w-5 text-muted-foreground"
                            aria-hidden="true"
                        />
                        Rapport par agence
                        <InfoTooltip
                            label="Informations sur le rapport par agence"
                        >
                            Ventes, encaissements par moyen, dettes clients et
                            charges de chaque agence sur la période choisie, et
                            ce qu'elle a réellement remis à {{ nomCentral }}.
                            Seul « Remis » suit la période (remises reçues par
                            {{ nomCentral }}). « En transit » et « Reste à
                            remettre » sont des montants à ce jour, quelle que
                            soit la période, comme dans « Remises des agences ».
                            Les charges ne sont jamais déduites du remis ni du
                            reste.
                        </InfoTooltip>
                    </h1>
                    <p
                        class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground"
                    >
                        <span
                            class="font-medium text-foreground"
                            data-testid="rapport-agences-perimetre"
                            >{{ perimetre }}</span
                        >
                        <span aria-hidden="true">·</span>
                        <span class="inline-flex items-center gap-1.5">
                            <CalendarDays
                                class="h-4 w-4 shrink-0"
                                aria-hidden="true"
                            />
                            {{ periode.libelle }}
                        </span>
                    </p>
                </div>
                <ListPageActions>
                    <template #export>
                        <a
                            :href="exportHref"
                            data-testid="rapport-agences-export-excel"
                            aria-label="Exporter le rapport par agence en Excel"
                            class="inline-flex h-9 items-center gap-1.5 rounded-md border bg-background px-3 text-sm font-medium transition-colors hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                        >
                            <FileSpreadsheet class="h-4 w-4" /> Excel
                        </a>
                    </template>
                    <template #filters>
                        <DataFilters
                            trigger-only
                            :url="url"
                            :values="filters"
                            :fields="filterFields"
                            :sites="sites"
                            :result-count="rapport.agences.length"
                            hide-result-count
                        />
                    </template>
                </ListPageActions>
            </div>

            <p
                v-if="!rapport.central"
                class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300"
                data-testid="rapport-agences-sans-central"
            >
                Aucun site n'est désigné comme trésorerie principale : les
                remises ne peuvent pas être suivies.
            </p>

            <!-- Indicateurs : six cartes compactes, une ligne sur grand écran ; colonnes adaptées
                 à la largeur disponible pour qu'un montant en milliards ne déborde jamais. -->
            <div class="@container">
                <div
                    class="grid grid-cols-1 gap-4 @[30rem]:grid-cols-2 @[52rem]:grid-cols-3 @[92rem]:grid-cols-6"
                >
                    <KpiCard
                        compact
                        data-testid="kpi-ventes"
                        title="Ventes"
                        :value="n(t.ventes_montant)"
                        unit="GNF"
                        :detail="pluriel(t.ventes_nombre, 'vente')"
                    />
                    <KpiCard
                        compact
                        data-testid="kpi-encaisse"
                        title="Encaissé"
                        :value="n(t.encaisse_montant)"
                        unit="GNF"
                        :detail="pluriel(t.encaisse_nombre, 'paiement')"
                    />
                    <KpiCard
                        compact
                        data-testid="kpi-dettes"
                        title="Dettes clients"
                        :value="n(t.dettes_montant)"
                        unit="GNF"
                        :detail="pluriel(t.dettes_nombre, 'facture')"
                    />
                    <KpiCard
                        compact
                        data-testid="kpi-charges"
                        title="Charges payées"
                        :value="n(t.charges_payees)"
                        unit="GNF"
                        :detail="detailCharges"
                    />
                    <KpiCard
                        compact
                        data-testid="kpi-remis"
                        :title="`Remis à ${nomCentral}`"
                        :value="n(t.remis)"
                        unit="GNF"
                        detail="Reçu sur la période"
                    />
                    <KpiCard
                        compact
                        data-testid="kpi-reste"
                        title="Reste à remettre"
                        :value="n(t.reste_a_remettre)"
                        unit="GNF"
                        :detail="detailReste"
                        :warning="t.reste_incomplet"
                    />
                </div>
            </div>

            <!-- Tableau des agences (≥ 1024 px) -->
            <section
                class="hidden min-w-0 overflow-hidden rounded-xl border bg-card lg:block"
                aria-labelledby="titre-tableau-agences"
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3"
                >
                    <h2
                        id="titre-tableau-agences"
                        class="text-sm font-semibold"
                    >
                        Détail par agence
                        <span class="font-normal text-muted-foreground"
                            >·
                            {{
                                pluriel(rapport.agences.length, 'agence')
                            }}</span
                        >
                    </h2>
                    <p class="text-xs text-muted-foreground">Montants en GNF</p>
                </div>
                <div
                    role="region"
                    aria-label="Activité par agence — tableau à défilement horizontal"
                    tabindex="0"
                    class="w-full max-w-full overflow-x-auto"
                >
                    <table
                        class="w-full min-w-max border-collapse text-sm"
                        data-testid="rapport-agences-tableau"
                    >
                        <thead>
                            <tr class="text-xs text-muted-foreground">
                                <th
                                    class="sticky left-0 z-10 bg-card px-4 pt-2.5"
                                />
                                <th
                                    :colspan="colonnesActivite"
                                    class="px-3 pt-2.5 text-left font-medium"
                                >
                                    Activité de la période
                                </th>
                                <th
                                    colspan="4"
                                    :class="
                                        cn(
                                            'border-l px-3 pt-2.5 text-left font-semibold text-foreground',
                                            REMISE,
                                        )
                                    "
                                >
                                    Remis à {{ nomCentral }}
                                </th>
                                <th />
                            </tr>
                            <tr class="border-b text-muted-foreground">
                                <th
                                    class="sticky left-0 z-10 bg-card px-4 py-2.5 text-left text-xs font-medium"
                                >
                                    Agence
                                </th>
                                <th :class="TH">Ventes</th>
                                <th :class="TH">Encaissé</th>
                                <th
                                    v-for="m in rapport.moyens"
                                    :key="m.cle"
                                    :class="TH"
                                >
                                    {{ m.libelle }}
                                </th>
                                <th :class="TH">Dettes clients</th>
                                <th :class="TH">Charges payées</th>
                                <th
                                    :class="cn(TH, REMISE, 'border-l')"
                                    data-testid="entete-remis"
                                >
                                    Remis
                                    <span class="block font-normal opacity-80"
                                        >sur la période</span
                                    >
                                </th>
                                <th
                                    :class="cn(TH, REMISE)"
                                    data-testid="entete-en-transit"
                                >
                                    En transit
                                    <span class="block font-normal opacity-80"
                                        >à ce jour</span
                                    >
                                </th>
                                <th
                                    :class="cn(TH, REMISE, 'text-foreground')"
                                    data-testid="entete-reste"
                                >
                                    Reste à remettre
                                    <span class="block font-normal opacity-80"
                                        >à ce jour</span
                                    >
                                </th>
                                <th
                                    :class="
                                        cn(
                                            REMISE,
                                            'px-3 py-2.5 text-left text-xs font-medium',
                                        )
                                    "
                                >
                                    Statut
                                </th>
                                <th class="px-3 py-2.5">
                                    <span class="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="a in rapport.agences"
                                :key="a.site_id"
                                class="align-top"
                                data-testid="rapport-agence-ligne"
                            >
                                <td
                                    class="sticky left-0 z-10 bg-card px-4 py-3 align-top"
                                >
                                    <Link
                                        :href="activiteHref(a)"
                                        class="font-medium hover:text-primary hover:underline"
                                        >{{ a.site_nom }}</Link
                                    >
                                </td>
                                <td :class="cn(TD, attenue(a.ventes_montant))">
                                    {{ n(a.ventes_montant) }}
                                    <span
                                        class="block text-xs text-muted-foreground"
                                        >{{
                                            pluriel(a.ventes_nombre, 'vente')
                                        }}</span
                                    >
                                </td>
                                <td
                                    :class="
                                        cn(
                                            TD,
                                            'font-semibold',
                                            attenue(a.encaisse_montant),
                                        )
                                    "
                                >
                                    {{ n(a.encaisse_montant) }}
                                    <span
                                        v-if="a.pour_autres_agences"
                                        class="block text-xs font-normal text-muted-foreground"
                                        :title="`Encaissé pour des commandes d'autres agences : ${n(a.pour_autres_agences)} GNF, à reverser`"
                                        >dont
                                        {{ n(a.pour_autres_agences) }} autres
                                        agences</span
                                    >
                                </td>
                                <td
                                    v-for="m in rapport.moyens"
                                    :key="m.cle"
                                    :class="
                                        cn(TD, attenue(montantMoyen(a, m.cle)))
                                    "
                                >
                                    {{ n(montantMoyen(a, m.cle)) }}
                                </td>
                                <td :class="cn(TD, attenue(a.dettes_montant))">
                                    {{ n(a.dettes_montant) }}
                                    <span
                                        v-if="a.dettes_nombre"
                                        class="block text-xs text-muted-foreground"
                                        >{{
                                            pluriel(a.dettes_nombre, 'facture')
                                        }}</span
                                    >
                                </td>
                                <td
                                    :class="cn(TD, attenue(a.charges_payees))"
                                    data-testid="rapport-agence-charges"
                                >
                                    {{ n(a.charges_payees) }}
                                    <span
                                        v-if="a.charges_en_attente"
                                        class="block text-xs text-amber-700 dark:text-amber-400"
                                        >+ {{ n(a.charges_en_attente) }} en
                                        attente</span
                                    >
                                    <span
                                        v-if="a.charges_avances_tiers"
                                        class="block text-xs text-muted-foreground"
                                        title="Dépenses comptabilisées en avance récupérable sur un véhicule, un propriétaire, un livreur ou un parrain"
                                        >dont
                                        {{ n(a.charges_avances_tiers) }}
                                        avances</span
                                    >
                                </td>
                                <template
                                    v-if="
                                        a.est_tresorerie_principale || !a.remise
                                    "
                                >
                                    <td
                                        colspan="3"
                                        :class="
                                            cn(
                                                REMISE,
                                                'border-l px-3 py-3 text-right align-top text-xs text-muted-foreground',
                                            )
                                        "
                                    >
                                        {{
                                            a.est_tresorerie_principale
                                                ? 'Fonds déjà centralisés'
                                                : 'Position non disponible'
                                        }}
                                    </td>
                                </template>
                                <template v-else>
                                    <td
                                        :class="
                                            cn(
                                                TD,
                                                REMISE,
                                                'border-l',
                                                attenue(a.remise.remis),
                                            )
                                        "
                                        data-testid="rapport-agence-remis"
                                    >
                                        {{ n(a.remise.remis) }}
                                        <span
                                            v-if="a.remise.derniere_remise"
                                            class="block text-xs text-muted-foreground"
                                            >dernière le
                                            {{
                                                dateFr(a.remise.derniere_remise)
                                            }}</span
                                        >
                                    </td>
                                    <td
                                        :class="
                                            cn(
                                                TD,
                                                REMISE,
                                                attenue(a.remise.en_transit),
                                            )
                                        "
                                    >
                                        {{ n(a.remise.en_transit) }}
                                    </td>
                                    <td
                                        :class="
                                            cn(
                                                TD,
                                                REMISE,
                                                'font-semibold',
                                                attenue(
                                                    a.remise.reste_a_remettre,
                                                ),
                                            )
                                        "
                                        data-testid="rapport-agence-reste"
                                    >
                                        {{ n(a.remise.reste_a_remettre) }}
                                    </td>
                                </template>
                                <td
                                    :class="
                                        cn(
                                            REMISE,
                                            'px-3 py-3 align-top whitespace-nowrap',
                                        )
                                    "
                                >
                                    <StatusDot
                                        v-if="a.remise"
                                        :status="a.remise.statut"
                                        :label="a.remise.statut_label"
                                    />
                                    <span v-else class="text-muted-foreground"
                                        >—</span
                                    >
                                </td>
                                <td class="px-3 py-2.5 align-top">
                                    <div
                                        class="flex items-center justify-end gap-1"
                                    >
                                        <Link
                                            :href="activiteHref(a)"
                                            :aria-label="`Voir les opérations de ${a.site_nom}`"
                                            title="Voir les opérations (rapport d'activité de l'agence)"
                                            data-testid="rapport-agence-detail"
                                            class="inline-flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-primary focus-visible:outline-2 focus-visible:outline-ring"
                                        >
                                            <ArrowUpRight
                                                class="h-4 w-4"
                                                aria-hidden="true"
                                            />
                                        </Link>
                                        <Link
                                            v-if="peutVoirRemises(a)"
                                            :href="remisesHref(a)"
                                            :aria-label="`Voir les remises de ${a.site_nom}`"
                                            title="Voir les remises de l'agence"
                                            data-testid="rapport-agence-remises"
                                            class="inline-flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-primary focus-visible:outline-2 focus-visible:outline-ring"
                                        >
                                            <Landmark
                                                class="h-4 w-4"
                                                aria-hidden="true"
                                            />
                                        </Link>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="rapport.agences.length === 0">
                                <td
                                    :colspan="colonnesActivite + 7"
                                    class="px-4 py-10 text-center text-muted-foreground"
                                >
                                    Aucune agence ne correspond à ces filtres.
                                </td>
                            </tr>
                        </tbody>
                        <tfoot v-if="rapport.agences.length > 1">
                            <tr
                                class="border-t-2 bg-muted font-semibold"
                                data-testid="rapport-agences-total"
                            >
                                <td
                                    class="sticky left-0 z-10 bg-muted px-4 py-3"
                                >
                                    Total
                                </td>
                                <td :class="TD">{{ n(t.ventes_montant) }}</td>
                                <td :class="TD">{{ n(t.encaisse_montant) }}</td>
                                <td
                                    v-for="m in rapport.moyens"
                                    :key="m.cle"
                                    :class="TD"
                                >
                                    {{ n(t.par_moyen?.[m.cle] ?? 0) }}
                                </td>
                                <td :class="TD">{{ n(t.dettes_montant) }}</td>
                                <td :class="TD">
                                    {{ n(t.charges_payees) }}
                                    <span
                                        v-if="t.charges_en_attente"
                                        class="block text-xs font-normal text-amber-700 dark:text-amber-400"
                                        >+ {{ n(t.charges_en_attente) }} en
                                        attente</span
                                    >
                                </td>
                                <td :class="cn(TD, 'border-l')">
                                    {{ n(t.remis) }}
                                </td>
                                <td :class="TD">{{ n(t.en_transit) }}</td>
                                <td :class="TD">
                                    {{ n(t.reste_a_remettre) }}
                                </td>
                                <td colspan="2" />
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <p class="border-t px-4 py-2.5 text-xs text-muted-foreground">
                    « Remis » : remises reçues par {{ nomCentral }} pendant la
                    période. « En transit » et « Reste à remettre » : situation
                    à ce jour — jamais « encaissé − remis » (l'agence conserve
                    ses commissions, salaires et factures fournisseurs). Charges
                    payées = dépenses validées ; elles ne sont pas déduites du
                    reste à remettre.
                </p>
            </section>

            <!-- Liste empilée (< 1024 px, deux colonnes sur tablette) -->
            <ul
                class="grid grid-cols-1 items-start gap-3 sm:grid-cols-2 lg:hidden"
                data-testid="rapport-agences-liste-mobile"
            >
                <li
                    v-for="a in rapport.agences"
                    :key="a.site_id"
                    class="overflow-hidden rounded-xl border bg-card"
                >
                    <div
                        class="flex items-start justify-between gap-2 px-4 pt-3"
                    >
                        <Link
                            :href="activiteHref(a)"
                            class="font-semibold hover:text-primary"
                            >{{ a.site_nom }}</Link
                        >
                        <StatusDot
                            v-if="a.remise"
                            :status="a.remise.statut"
                            :label="a.remise.statut_label"
                        />
                    </div>
                    <dl
                        class="grid grid-cols-2 gap-x-3 gap-y-1.5 px-4 py-3 text-sm"
                    >
                        <dt class="text-muted-foreground">Ventes</dt>
                        <dd class="text-right tabular-nums">
                            {{ n(a.ventes_montant) }}
                        </dd>
                        <dt class="text-muted-foreground">Encaissé</dt>
                        <dd class="text-right font-semibold tabular-nums">
                            {{ n(a.encaisse_montant) }}
                        </dd>
                        <template v-for="m in rapport.moyens" :key="m.cle">
                            <dt class="pl-3 text-xs text-muted-foreground">
                                {{ m.libelle }}
                            </dt>
                            <dd class="text-right text-xs tabular-nums">
                                {{ n(montantMoyen(a, m.cle)) }}
                            </dd>
                        </template>
                        <dt class="text-muted-foreground">Dettes clients</dt>
                        <dd class="text-right tabular-nums">
                            {{ n(a.dettes_montant) }}
                        </dd>
                        <dt class="text-muted-foreground">Charges payées</dt>
                        <dd class="text-right tabular-nums">
                            {{ n(a.charges_payees) }}
                            <span
                                v-if="a.charges_en_attente"
                                class="block text-xs text-amber-700 dark:text-amber-400"
                                >+ {{ n(a.charges_en_attente) }} en
                                attente</span
                            >
                        </dd>
                    </dl>
                    <dl
                        v-if="a.remise && !a.est_tresorerie_principale"
                        :class="
                            cn(
                                REMISE,
                                'grid grid-cols-2 gap-x-3 gap-y-1.5 border-t px-4 py-3 text-sm',
                            )
                        "
                    >
                        <dt class="text-muted-foreground">
                            Remis sur la période
                        </dt>
                        <dd class="text-right tabular-nums">
                            {{ n(a.remise.remis) }}
                        </dd>
                        <dt class="text-muted-foreground">
                            En transit à ce jour
                        </dt>
                        <dd class="text-right tabular-nums">
                            {{ n(a.remise.en_transit) }}
                        </dd>
                        <dt class="font-medium">Reste à remettre à ce jour</dt>
                        <dd class="text-right font-semibold tabular-nums">
                            {{ n(a.remise.reste_a_remettre) }}
                        </dd>
                    </dl>
                    <p
                        v-else-if="a.est_tresorerie_principale"
                        :class="
                            cn(
                                REMISE,
                                'border-t px-4 py-2.5 text-xs text-muted-foreground',
                            )
                        "
                    >
                        Fonds déjà centralisés
                    </p>
                    <div class="flex gap-4 border-t px-4 py-2.5 text-sm">
                        <Link
                            :href="activiteHref(a)"
                            class="inline-flex items-center gap-1 font-medium text-primary"
                        >
                            Opérations
                            <ArrowUpRight class="h-4 w-4" aria-hidden="true" />
                        </Link>
                        <Link
                            v-if="peutVoirRemises(a)"
                            :href="remisesHref(a)"
                            class="inline-flex items-center gap-1 font-medium text-primary"
                        >
                            Remises
                            <Landmark class="h-4 w-4" aria-hidden="true" />
                        </Link>
                    </div>
                </li>
                <li
                    v-if="rapport.agences.length === 0"
                    class="rounded-xl border bg-card px-4 py-10 text-center text-sm text-muted-foreground sm:col-span-2"
                >
                    Aucune agence ne correspond à ces filtres.
                </li>
                <li
                    v-if="rapport.agences.length > 1"
                    class="rounded-xl border bg-muted/50 px-4 py-3 text-sm sm:col-span-2"
                    data-testid="rapport-agences-total-mobile"
                >
                    <p class="mb-1.5 font-semibold">Total · GNF</p>
                    <dl class="grid grid-cols-2 gap-x-3 gap-y-1">
                        <dt class="text-muted-foreground">Encaissé</dt>
                        <dd class="text-right font-semibold tabular-nums">
                            {{ n(t.encaisse_montant) }}
                        </dd>
                        <dt class="text-muted-foreground">Remis</dt>
                        <dd class="text-right tabular-nums">
                            {{ n(t.remis) }}
                        </dd>
                        <dt class="text-muted-foreground">Reste à remettre</dt>
                        <dd class="text-right font-semibold tabular-nums">
                            {{ n(t.reste_a_remettre) }}
                        </dd>
                    </dl>
                </li>
            </ul>
            <p class="text-xs text-muted-foreground lg:hidden">
                Montants en GNF. « Remis » suit la période ; « En transit » et «
                Reste à remettre » sont à ce jour.
            </p>
        </div>
    </AppLayout>
</template>
