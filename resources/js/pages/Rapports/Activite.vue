<script setup lang="ts">
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import InfoTooltip from '@/components/InfoTooltip.vue';
import KpiCard from '@/components/KpiCard.vue';
import ListPageActions from '@/components/ListPageActions.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { useUrlTab } from '@/composables/useUrlTab';
import AppLayout from '@/layouts/AppLayout.vue';
import { cn, formatGNF } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import type { RapportActivite } from '@/types/rapports';
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CalendarDays,
    ChartColumn,
    FileSpreadsheet,
    FileText,
    Info,
    UserRound,
} from 'lucide-vue-next';
import Select from 'primevue/select';
import { computed, ref, watch } from 'vue';
import ApprovisionnementsAConfirmer, {
    type ApprovisionnementAConfirmer,
} from './partials/ApprovisionnementsAConfirmer.vue';
import EnTeteSection from './partials/EnTeteSection.vue';
import { dateFr, pluriel } from './partials/format';
import ListeCharges from './partials/ListeCharges.vue';
import ListeEncaissements from './partials/ListeEncaissements.vue';
import ListeFactures from './partials/ListeFactures.vue';
import SectionCaisse from './partials/SectionCaisse.vue';
import { n, REMISE, TOTAL_ENCAISSE } from './partials/tableau';
import TableauAgents from './partials/TableauAgents.vue';

// Même page pour « Ma situation » (agent imposé par le serveur) et le rapport d'activité (agences
// et agents selon les droits) — cf. RapportActivitePresenter. Même langage graphique que le Rapport
// par agence (cartes compactes, tableau, groupe « Remis ») ; seul le niveau change : un agent remet
// à SON agence (ADR 0064), une agence remet à la trésorerie principale (ADR 0016). Chaque bloc est
// calculé indépendamment : jamais de « reste » déduit par différence entre deux blocs.
const props = defineProps<{
    mode: 'ma_situation' | 'rapport';
    url: string;
    export_url: string;
    filters: {
        periode: string;
        date_debut: string | null;
        date_fin: string | null;
        site_ids: string[];
        agent_id: string | null;
    };
    periode: {
        cle: string;
        date_debut: string;
        date_fin: string;
        libelle: string;
        options: { value: string; label: string }[];
    };
    sites: { id: string; nom: string }[];
    agents: { value: string; label: string }[];
    agent: { id: string; nom: string } | null;
    limite_lignes: number;
    rapport: RapportActivite;
    /** Lien vers une charge : module Dépenses actif et `depenses.read` (vérifié côté serveur). */
    peut_voir_depenses?: boolean;
    /** « Ma situation » seulement : espèces remises à l'agent, en attente de SA confirmation (ADR 0018). */
    approvisionnements_en_attente?: ApprovisionnementAConfirmer[];
}>();

const page = usePage();
const maSituation = computed(() => props.mode === 'ma_situation');
const titre = computed(() =>
    maSituation.value ? 'Ma situation' : "Rapport d'activité",
);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: titre.value, href: props.url },
]);

const ONGLETS = [
    'ventes',
    'encaissements',
    'creances',
    'mobile_money',
    'caisse',
    'charges',
] as const;
type Onglet = (typeof ONGLETS)[number];
const { onglet, choisir } = useUrlTab<Onglet>(ONGLETS, 'ventes');

function naviguerOnglets(event: KeyboardEvent) {
    if (!['ArrowRight', 'ArrowLeft', 'Home', 'End'].includes(event.key)) return;
    const liste = event.currentTarget as HTMLElement;
    const boutons = Array.from(
        liste.querySelectorAll<HTMLButtonElement>('[role="tab"]'),
    );
    const index = boutons.indexOf(event.target as HTMLButtonElement);
    if (index < 0) return;
    event.preventDefault();
    const suivant =
        event.key === 'Home'
            ? 0
            : event.key === 'End'
              ? boutons.length - 1
              : (index +
                    (event.key === 'ArrowRight' ? 1 : -1) +
                    boutons.length) %
                boutons.length;
    boutons[suivant].focus();
    boutons[suivant].click();
}

// ── En-tête : qui / quel périmètre, et quelle période ─────────────────────────

interface AuthAgences {
    user_sites?: { nom: string }[];
    default_site?: { nom: string } | null;
}

const agencesUtilisateur = computed((): string | null => {
    const auth = ((page.props as Record<string, unknown>).auth ??
        {}) as AuthAgences;
    const sites = Array.isArray(auth.user_sites) ? auth.user_sites : [];
    if (sites.length > 0) return sites.map((s) => s.nom).join(', ');

    return auth.default_site?.nom ?? null;
});

const perimetre = computed(() => {
    if (maSituation.value) {
        return [props.agent?.nom, agencesUtilisateur.value]
            .filter(Boolean)
            .join(' · ');
    }
    const agences =
        props.filters.site_ids.length > 0
            ? props.sites
                  .filter((s) => props.filters.site_ids.includes(s.id))
                  .map((s) => s.nom)
                  .join(', ')
            : 'Toutes les agences';
    const agent = props.filters.agent_id
        ? (props.agents.find((a) => a.value === props.filters.agent_id)
              ?.label ?? 'Agent choisi')
        : 'Tous les agents';

    return `${agences} · ${agent}`;
});

// ── Indicateurs (cartes compactes, sans clic) ─────────────────────────────────

const anomaliesMobileMoney = computed(
    () =>
        props.rapport.mobile_money.resume.reference_absente +
        props.rapport.mobile_money.resume.reference_dupliquee,
);

const remise = computed(() => props.rapport.remise);
const charges = computed(() => props.rapport.charges.resume);

/** Agence(s) de rattachement de la remise : agence de la caisse / du compte Mobile Money. */
const agenceRemise = computed(() => {
    const agences = remise.value?.agences ?? [];
    if (agences.length === 0)
        return maSituation.value ? 'mon agence' : "l'agence";

    return agences.join(', ');
});

// Saisies = activité de l'agent ; seules les validées sont déduites de sa remise (ADR 0064).
const detailCharges = computed(() =>
    charges.value.charges_en_attente > 0
        ? `${n(charges.value.charges_payees)} validées · ${n(charges.value.charges_en_attente)} en attente`
        : `${n(charges.value.charges_payees)} validées`,
);

interface Indicateur {
    cle: string;
    titre: string;
    valeur: number;
    detail: string;
    alerte?: boolean;
}

const indicateurs = computed((): Indicateur[] => {
    const r = props.rapport;
    const ma = maSituation.value;
    const liste: Indicateur[] = [
        {
            cle: 'ventes',
            titre: ma ? 'Mes ventes' : 'Ventes',
            valeur: r.ventes.resume.facture,
            detail: pluriel(r.ventes.resume.nombre, 'vente'),
        },
        {
            cle: 'encaisse',
            titre: ma ? 'Mes encaissements' : 'Total encaissé',
            valeur: r.encaissements.resume.montant,
            detail: pluriel(r.encaissements.resume.nombre, 'paiement'),
        },
        {
            cle: 'dettes',
            titre: ma ? 'Mes dettes clients' : 'Dettes clients',
            valeur: r.creances.resume.reste,
            detail: pluriel(r.creances.resume.nombre, 'facture'),
        },
        {
            cle: 'charges',
            titre: ma ? 'Mes charges saisies' : 'Charges saisies',
            valeur: charges.value.charges_saisies,
            detail: detailCharges.value,
        },
        {
            cle: 'remis',
            titre: ma ? 'Remis à mon agence' : "Remis à l'agence",
            valeur: remise.value?.remis ?? 0,
            detail: remise.value
                ? 'Reçu sur la période'
                : 'Aucune caisse dédiée',
        },
    ];
    if (ma) {
        liste.push({
            cle: 'reste',
            titre: 'Reste à remettre à mon agence',
            valeur: remise.value?.reste_a_remettre ?? 0,
            detail: remise.value
                ? `Fin de période · dont ${n(remise.value.en_transit)} en transit`
                : 'Aucune caisse dédiée',
        });
    }

    return liste;
});

// ── Ma situation : détail des encaissements par moyen ─────────────────────────

const encaissementsParMoyen = computed(() =>
    props.rapport.moyens.map((m) => {
        const ligne = props.rapport.encaissements.par_moyen.find(
            (p) => p.cle === m.cle,
        );

        return {
            cle: m.cle,
            libelle: m.libelle,
            nombre: ligne?.nombre ?? 0,
            montant: ligne?.montant ?? 0,
        };
    }),
);

// ── Rapport d'activité : détail des opérations d'un agent ─────────────────────

function hrefAgent(agentId: string): string {
    const params = new URLSearchParams(
        new URL(page.url, 'http://localhost').search,
    );
    params.set('agent_id', agentId);

    return `${props.url}?${params.toString()}`;
}

// ── Onglets du détail ─────────────────────────────────────────────────────────

const onglets = computed(() => {
    const r = props.rapport;
    const ma = maSituation.value;

    return [
        {
            cle: 'ventes' as Onglet,
            libelle: ma ? 'Mes ventes' : 'Ventes',
            nombre: r.ventes.resume.nombre,
        },
        {
            cle: 'encaissements' as Onglet,
            libelle: ma ? 'Mes encaissements' : 'Encaissements',
            nombre: r.encaissements.resume.nombre,
        },
        {
            cle: 'creances' as Onglet,
            libelle: 'Dettes clients',
            nombre: r.creances.resume.nombre,
        },
        {
            cle: 'mobile_money' as Onglet,
            libelle: 'Mobile Money',
            nombre: r.mobile_money.resume.nombre,
            alerte: anomaliesMobileMoney.value > 0,
        },
        {
            cle: 'caisse' as Onglet,
            libelle: ma ? 'Ma caisse' : 'Caisses dédiées',
            nombre: null,
        },
        {
            cle: 'charges' as Onglet,
            libelle: ma ? 'Mes charges' : 'Charges',
            nombre: r.charges.total_lignes,
        },
    ];
});

// ── Filtres et exports ────────────────────────────────────────────────────────

// Rapport d'activité : tous les filtres dans le panneau, [Agence] → [Agent] → [Période].
// « Ma situation » n'a ni Agence ni Agent : sa période reste le sélecteur de l'en-tête.
const filterFields = computed<FilterField[]>(() => [
    {
        key: 'agent_id',
        label: 'Agent',
        type: 'select',
        searchable: true,
        placeholder: 'Tous les agents',
        options: props.agents,
    },
    {
        key: 'periode',
        label: 'Période',
        type: 'period',
        options: props.periode.options,
        defaultValue: 'aujourd_hui',
    },
]);

const periodeChoisie = ref(props.filters.periode);
const dateDebut = ref(props.filters.date_debut ?? props.periode.date_debut);
const dateFin = ref(props.filters.date_fin ?? props.periode.date_fin);
const periodeEnCours = ref(false);

function synchroniserPeriode() {
    periodeChoisie.value = props.filters.periode;
    dateDebut.value = props.filters.date_debut ?? props.periode.date_debut;
    dateFin.value = props.filters.date_fin ?? props.periode.date_fin;
}
watch(() => [props.filters, props.periode], synchroniserPeriode);

function parametresPeriode(
    choix: string,
    debut: string | null,
    fin: string | null,
): Record<string, string> {
    if (choix === 'personnalisee') {
        return {
            ...(debut ? { date_debut: debut } : {}),
            ...(fin ? { date_fin: fin } : {}),
        };
    }
    // Date précise : une journée = même date de début et de fin (Periode::DATE_PRECISE).
    if (choix === 'date_precise') {
        return debut ? { date_debut: debut, date_fin: debut } : {};
    }
    return choix === 'aujourd_hui' ? {} : { periode: choix };
}

// La période est un champ du panneau : seuls les paramètres hors filtres (onglet) sont conservés.
const parametresFiltres = computed(() => ({ tab: onglet.value }));

function appliquerPeriode() {
    if (periodeEnCours.value) return;
    if (
        periodeChoisie.value === 'personnalisee' &&
        (!dateDebut.value || !dateFin.value || dateDebut.value > dateFin.value)
    )
        return;
    if (periodeChoisie.value === 'date_precise' && !dateDebut.value) return;
    const params: Record<string, string> = {
        tab: onglet.value,
        ...parametresPeriode(
            periodeChoisie.value,
            dateDebut.value,
            dateFin.value,
        ),
    };
    router.get(props.url, params, {
        preserveScroll: true,
        replace: true,
        onStart: () => {
            periodeEnCours.value = true;
        },
        onFinish: () => {
            periodeEnCours.value = false;
        },
        onError: synchroniserPeriode,
    });
}

function changerPeriode(choix: string) {
    periodeChoisie.value = choix;
    if (choix !== 'personnalisee' && choix !== 'date_precise')
        appliquerPeriode();
}

// Exports : mêmes paramètres que l'écran (le serveur réapplique le périmètre), sans l'onglet.
function exportHref(format: 'xlsx' | 'pdf'): string {
    const params = new URLSearchParams(
        new URL(page.url, 'http://localhost').search,
    );
    params.delete('tab');
    params.set('format', format);

    return `${props.export_url}?${params.toString()}`;
}

function tronque(section: { lignes: unknown[]; total_lignes: number }) {
    return section.total_lignes > section.lignes.length;
}

const chiffresVentes = computed(() => {
    const v = props.rapport.ventes.resume;

    return [
        { libelle: 'Encaissé sur ces ventes', valeur: formatGNF(v.encaisse) },
        { libelle: 'Reste à payer', valeur: formatGNF(v.reste) },
        {
            libelle: 'Annulées / retournées (hors CA)',
            valeur:
                v.annulees_nombre === 0
                    ? 'aucune'
                    : `${v.annulees_nombre} · ${formatGNF(v.annulees_montant)}`,
        },
    ];
});

const chiffresEncaissements = computed(() => {
    const r = props.rapport.encaissements.resume;
    const moyens = encaissementsParMoyen.value.map((m) => ({
        libelle: m.libelle,
        valeur: formatGNF(m.montant),
    }));

    // Encaissés pour des commandes d'autres agences (ADR 0012) : à reverser par l'AGENCE — jamais
    // affiché dans « Ma situation », où l'agent remet tout à la caisse de sa propre agence.
    return r.pour_autres_agences_nombre > 0 && !maSituation.value
        ? [
              ...moyens,
              {
                  libelle: "Pour d'autres agences (à reverser)",
                  valeur: formatGNF(r.pour_autres_agences_montant),
              },
          ]
        : moyens;
});

const chiffresDettes = computed(() => {
    const c = props.rapport.creances.resume;

    return [
        { libelle: 'Impayées', valeur: String(c.impayees) },
        { libelle: 'Partiellement payées', valeur: String(c.partielles) },
        { libelle: 'Plus ancienne', valeur: dateFr(c.plus_ancienne) },
    ];
});

const chiffresMobileMoney = computed(() =>
    props.rapport.mobile_money.par_operateur.map((o) => ({
        libelle: o.libelle,
        valeur: formatGNF(o.montant),
    })),
);

const chiffresCharges = computed(() => [
    {
        libelle: 'Validées (déduites de la remise)',
        valeur: formatGNF(charges.value.charges_payees),
    },
    {
        libelle: 'En attente de validation',
        valeur: formatGNF(charges.value.charges_en_attente),
    },
    {
        libelle: 'dont avances récupérables',
        valeur: formatGNF(charges.value.charges_avances_tiers),
    },
]);
</script>

<template>
    <Head :title="titre" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full min-w-0 space-y-5 p-4 sm:p-6">
            <!-- En-tête compact : titre, périmètre et période sur une ligne, actions à droite -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0 space-y-1">
                    <h1 class="flex items-center gap-2 text-xl font-semibold">
                        <UserRound
                            v-if="maSituation"
                            class="h-5 w-5 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <ChartColumn
                            v-else
                            class="h-5 w-5 text-muted-foreground"
                            aria-hidden="true"
                        />
                        {{ titre }}
                        <InfoTooltip
                            :label="`Informations sur ${titre.toLowerCase()}`"
                        >
                            Ventes, encaissements par moyen, dettes clients et
                            charges saisies sur la période choisie, et ce que
                            {{
                                maSituation
                                    ? 'vous avez remis à votre agence'
                                    : 'chaque agent a remis à son agence'
                            }}. Un agent remet à son agence de rattachement,
                            jamais à la trésorerie principale. Net à remettre =
                            encaissé (espèces et Mobile Money) +
                            approvisionnements reçus − charges validées ; reste
                            = net − déjà remis, en cumul jusqu'à la fin de la
                            période.
                        </InfoTooltip>
                    </h1>
                    <p
                        class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground"
                    >
                        <span
                            class="font-medium text-foreground"
                            data-testid="rapport-perimetre"
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
                            :href="exportHref('xlsx')"
                            data-testid="rapport-export-excel"
                            aria-label="Exporter le rapport en Excel"
                            class="inline-flex h-9 items-center gap-1.5 rounded-md border bg-background px-3 text-sm font-medium transition-colors hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                        >
                            <FileSpreadsheet class="h-4 w-4" /> Excel
                        </a>
                        <a
                            :href="exportHref('pdf')"
                            data-testid="rapport-export-pdf"
                            aria-label="Exporter le rapport en PDF"
                            class="inline-flex h-9 items-center gap-1.5 rounded-md border bg-background px-3 text-sm font-medium transition-colors hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                        >
                            <FileText class="h-4 w-4" /> PDF
                        </a>
                    </template>
                    <template #filters>
                        <form
                            v-if="maSituation"
                            class="flex max-w-full flex-wrap items-center gap-2"
                            data-testid="rapport-periode"
                            @submit.prevent="appliquerPeriode"
                        >
                            <Select
                                :model-value="periodeChoisie"
                                :options="periode.options"
                                option-label="label"
                                option-value="value"
                                aria-label="Période"
                                :disabled="periodeEnCours"
                                size="small"
                                class="h-9 w-[200px] max-w-full text-sm"
                                @update:model-value="changerPeriode"
                            />
                            <template v-if="periodeChoisie === 'personnalisee'">
                                <input
                                    v-model="dateDebut"
                                    type="date"
                                    aria-label="Date de début"
                                    :max="dateFin || undefined"
                                    :disabled="periodeEnCours"
                                    required
                                    class="h-9 min-w-0 rounded-md border border-input bg-background px-2 text-sm focus-visible:outline-2 focus-visible:outline-ring"
                                />
                                <span class="text-xs text-muted-foreground"
                                    >au</span
                                >
                                <input
                                    v-model="dateFin"
                                    type="date"
                                    aria-label="Date de fin"
                                    :min="dateDebut || undefined"
                                    :disabled="periodeEnCours"
                                    required
                                    class="h-9 min-w-0 rounded-md border border-input bg-background px-2 text-sm focus-visible:outline-2 focus-visible:outline-ring"
                                />
                                <button
                                    type="submit"
                                    :disabled="
                                        periodeEnCours ||
                                        !dateDebut ||
                                        !dateFin ||
                                        dateDebut > dateFin
                                    "
                                    class="h-9 rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground hover:bg-primary/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:opacity-50"
                                >
                                    Appliquer
                                </button>
                            </template>
                            <template
                                v-else-if="periodeChoisie === 'date_precise'"
                            >
                                <input
                                    v-model="dateDebut"
                                    type="date"
                                    aria-label="Date"
                                    data-testid="rapport-periode-date"
                                    :disabled="periodeEnCours"
                                    required
                                    class="h-9 min-w-0 rounded-md border border-input bg-background px-2 text-sm focus-visible:outline-2 focus-visible:outline-ring"
                                />
                                <button
                                    type="submit"
                                    :disabled="periodeEnCours || !dateDebut"
                                    class="h-9 rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground hover:bg-primary/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:opacity-50"
                                >
                                    Appliquer
                                </button>
                            </template>
                        </form>
                        <DataFilters
                            v-else
                            trigger-only
                            :url="url"
                            :base-params="parametresFiltres"
                            :values="filters"
                            :fields="filterFields"
                            :sites="sites"
                            :result-count="0"
                            hide-result-count
                        />
                    </template>
                </ListPageActions>
            </div>

            <ApprovisionnementsAConfirmer
                v-if="maSituation && approvisionnements_en_attente?.length"
                :approvisionnements="approvisionnements_en_attente"
            />

            <!-- Indicateurs : cartes compactes (même grille que le Rapport par agence) -->
            <div class="@container">
                <div
                    :class="
                        cn(
                            'grid grid-cols-1 gap-4 @[30rem]:grid-cols-2 @[52rem]:grid-cols-3',
                            maSituation
                                ? '@[92rem]:grid-cols-6'
                                : '@[92rem]:grid-cols-5',
                        )
                    "
                >
                    <KpiCard
                        v-for="k in indicateurs"
                        :key="k.cle"
                        compact
                        :data-testid="`kpi-${k.cle}`"
                        :title="k.titre"
                        :value="n(k.valeur)"
                        unit="GNF"
                        :detail="k.detail"
                    />
                </div>
            </div>

            <!-- Rapport d'activité : une ligne par agent -->
            <TableauAgents
                v-if="!maSituation && rapport.agents"
                :lignes="rapport.agents.lignes"
                :totaux="rapport.agents.totaux"
                :moyens="rapport.moyens"
                :href-agent="hrefAgent"
            />

            <!-- Ma situation : mes encaissements par moyen et ma remise à mon agence -->
            <div v-else-if="maSituation" class="grid gap-4 lg:grid-cols-2">
                <section
                    class="overflow-hidden rounded-xl border bg-card"
                    aria-labelledby="titre-mes-encaissements"
                    data-testid="mes-encaissements"
                >
                    <div
                        class="flex items-center justify-between gap-2 border-b px-4 py-3"
                    >
                        <h2
                            id="titre-mes-encaissements"
                            class="text-sm font-semibold"
                        >
                            Mes encaissements
                        </h2>
                        <p class="text-xs text-muted-foreground">
                            Sur la période · GNF
                        </p>
                    </div>
                    <dl class="divide-y text-sm">
                        <div
                            v-for="m in encaissementsParMoyen"
                            :key="m.cle"
                            class="flex items-baseline justify-between gap-3 px-4 py-2.5"
                        >
                            <dt>
                                {{ m.libelle }}
                                <span class="text-xs text-muted-foreground"
                                    >· {{ pluriel(m.nombre, 'paiement') }}</span
                                >
                            </dt>
                            <dd
                                :class="
                                    cn(
                                        'tabular-nums',
                                        !m.montant &&
                                            'text-muted-foreground/70',
                                    )
                                "
                            >
                                {{ n(m.montant) }}
                            </dd>
                        </div>
                        <div
                            :class="
                                cn(
                                    TOTAL_ENCAISSE,
                                    'flex items-baseline justify-between gap-3 border-x-0 px-4 py-2.5 font-semibold',
                                )
                            "
                        >
                            <dt>Total encaissé</dt>
                            <dd
                                class="tabular-nums"
                                data-testid="mes-encaissements-total"
                            >
                                {{ n(rapport.encaissements.resume.montant) }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section
                    class="overflow-hidden rounded-xl border bg-card"
                    aria-labelledby="titre-ma-remise"
                    data-testid="ma-remise"
                >
                    <div
                        class="flex items-center justify-between gap-2 border-b px-4 py-3"
                    >
                        <h2 id="titre-ma-remise" class="text-sm font-semibold">
                            Remises à {{ agenceRemise }}
                        </h2>
                        <StatusDot
                            v-if="remise"
                            :status="remise.statut"
                            :label="remise.statut_label"
                        />
                    </div>
                    <!-- Calcul visible ligne à ligne (ADR 0064) : net − déjà remis = reste -->
                    <dl
                        v-if="remise"
                        :class="cn(REMISE, 'divide-y text-sm')"
                        data-testid="ma-remise-calcul"
                    >
                        <div
                            class="flex items-baseline justify-between gap-3 px-4 py-2"
                        >
                            <dt>
                                Encaissé en espèces et Mobile Money
                                <span class="text-xs text-muted-foreground"
                                    >· depuis le
                                    {{ dateFr(remise.depuis) }}</span
                                >
                            </dt>
                            <dd class="tabular-nums">
                                {{ n(remise.encaisse) }}
                            </dd>
                        </div>
                        <div
                            v-if="remise.approvisionnements"
                            class="flex items-baseline justify-between gap-3 px-4 py-2"
                        >
                            <dt>+ Approvisionnements reçus de l'agence</dt>
                            <dd class="tabular-nums">
                                {{ n(remise.approvisionnements) }}
                            </dd>
                        </div>
                        <div
                            class="flex items-baseline justify-between gap-3 px-4 py-2"
                        >
                            <dt>− Charges validées</dt>
                            <dd
                                class="tabular-nums"
                                data-testid="ma-remise-charges"
                            >
                                {{ n(remise.charges_validees) }}
                            </dd>
                        </div>
                        <div
                            class="flex items-baseline justify-between gap-3 px-4 py-2 font-medium"
                        >
                            <dt>= Montant net à remettre</dt>
                            <dd
                                class="tabular-nums"
                                data-testid="ma-remise-net"
                            >
                                {{ n(remise.net_a_remettre) }}
                            </dd>
                        </div>
                        <div
                            class="flex items-baseline justify-between gap-3 px-4 py-2"
                        >
                            <dt>
                                − Déjà remis à l'agence
                                <span
                                    v-if="remise.remis"
                                    class="text-xs text-muted-foreground"
                                    >· dont {{ n(remise.remis) }} sur la
                                    période</span
                                >
                            </dt>
                            <dd
                                class="tabular-nums"
                                data-testid="ma-remise-remis"
                            >
                                {{ n(remise.deja_remis) }}
                            </dd>
                        </div>
                        <div
                            class="flex items-baseline justify-between gap-3 px-4 py-2.5 font-semibold"
                        >
                            <dt>= Reste à remettre</dt>
                            <dd
                                class="tabular-nums"
                                data-testid="ma-remise-reste"
                            >
                                {{ n(remise.reste_a_remettre) }}
                            </dd>
                        </div>
                        <div
                            class="flex items-baseline justify-between gap-3 px-4 py-2 text-muted-foreground"
                        >
                            <dt>dont en transit (envoyé, non confirmé)</dt>
                            <dd class="tabular-nums">
                                {{ n(remise.en_transit) }}
                            </dd>
                        </div>
                    </dl>
                    <div
                        v-else
                        class="flex items-center gap-2 px-4 py-4 text-sm"
                    >
                        <Info class="h-4 w-4 shrink-0 text-blue-500" />
                        <span data-testid="kpi-caisse"
                            >Aucune caisse dédiée</span
                        >
                    </div>
                    <p
                        class="border-t px-4 py-2.5 text-xs text-muted-foreground"
                    >
                        {{
                            remise?.derniere_remise
                                ? `Dernier versement envoyé le ${dateFr(remise.derniere_remise)}. `
                                : remise
                                  ? 'Aucun versement enregistré. '
                                  : ''
                        }}Cumul jusqu'à la fin de la période. Seules les charges
                        validées sont déduites ; une remise n'est comptée qu'une
                        fois reçue par votre agence. Vous remettez à votre
                        agence, jamais directement à la trésorerie principale.
                    </p>
                </section>
            </div>

            <!-- Détail : un onglet par bloc du rapport -->
            <div class="space-y-3">
                <div
                    role="tablist"
                    aria-label="Détail du rapport"
                    class="flex gap-1 overflow-x-auto border-b"
                    @keydown="naviguerOnglets"
                >
                    <button
                        v-for="o in onglets"
                        :id="`rapport-tab-${o.cle}`"
                        :key="o.cle"
                        type="button"
                        role="tab"
                        :data-testid="`rapport-tab-${o.cle}`"
                        :aria-selected="onglet === o.cle"
                        :aria-controls="`rapport-panel-${o.cle}`"
                        :tabindex="onglet === o.cle ? 0 : -1"
                        :class="
                            cn(
                                '-mb-px inline-flex shrink-0 items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors focus-visible:outline-2 focus-visible:outline-ring',
                                onglet === o.cle
                                    ? 'border-primary text-foreground'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            )
                        "
                        @click="choisir(o.cle)"
                    >
                        {{ o.libelle }}
                        <span
                            v-if="o.nombre !== null"
                            class="rounded bg-muted px-1.5 text-xs text-muted-foreground tabular-nums"
                            >{{ o.nombre }}</span
                        >
                        <AlertTriangle
                            v-if="o.alerte"
                            class="h-3.5 w-3.5 text-amber-500"
                            aria-label="Références à vérifier"
                        />
                    </button>
                </div>

                <!-- ── Ventes ─────────────────────────────────────────────── -->
                <section
                    v-if="onglet === 'ventes'"
                    id="rapport-panel-ventes"
                    role="tabpanel"
                    aria-labelledby="rapport-tab-ventes"
                    tabindex="0"
                    class="space-y-4 rounded-xl border bg-card p-4 sm:p-5"
                >
                    <EnTeteSection
                        :titre="
                            maSituation ? 'Mes ventes' : 'Ventes de la période'
                        "
                        aide="Factures créées sur la période. Encaissé et reste : état actuel de ces factures, paiements reçus après la période compris."
                        :chiffres="chiffresVentes"
                    />
                    <ListeFactures
                        :lignes="rapport.ventes.lignes"
                        :afficher-agent="!maSituation"
                        vide="Aucune vente sur la période."
                    />
                    <p
                        v-if="tronque(rapport.ventes)"
                        class="text-xs text-muted-foreground"
                    >
                        {{ rapport.ventes.lignes.length }} lignes affichées sur
                        {{ rapport.ventes.total_lignes }} — exportez pour la
                        liste complète (les totaux portent sur toutes les
                        lignes).
                    </p>
                </section>

                <!-- ── Encaissements ──────────────────────────────────────── -->
                <section
                    v-if="onglet === 'encaissements'"
                    id="rapport-panel-encaissements"
                    role="tabpanel"
                    aria-labelledby="rapport-tab-encaissements"
                    tabindex="0"
                    class="space-y-4 rounded-xl border bg-card p-4 sm:p-5"
                >
                    <EnTeteSection
                        :titre="
                            maSituation
                                ? 'Mes encaissements'
                                : 'Encaissements de la période'
                        "
                        aide="Paiements reçus sur la période, quelle que soit la date de la vente. « Saisi le » est l'heure d'enregistrement (en orange : saisi un autre jour)."
                        :chiffres="chiffresEncaissements"
                    />
                    <ListeEncaissements
                        :lignes="rapport.encaissements.lignes"
                        :afficher-agent="!maSituation"
                        :afficher-reversement="!maSituation"
                        :total="rapport.encaissements.resume.montant"
                        vide="Aucun encaissement sur la période."
                    />
                    <p
                        v-if="tronque(rapport.encaissements)"
                        class="text-xs text-muted-foreground"
                    >
                        {{ rapport.encaissements.lignes.length }} lignes
                        affichées sur {{ rapport.encaissements.total_lignes }} —
                        exportez pour la liste complète.
                    </p>
                </section>

                <!-- ── Dettes clients ─────────────────────────────────────── -->
                <section
                    v-if="onglet === 'creances'"
                    id="rapport-panel-creances"
                    role="tabpanel"
                    aria-labelledby="rapport-tab-creances"
                    tabindex="0"
                    class="space-y-4 rounded-xl border bg-card p-4 sm:p-5"
                >
                    <EnTeteSection
                        titre="Dettes clients"
                        :contexte="periode.libelle"
                        aide="Factures créées sur la période que les clients doivent encore. Reste : état actuel de ces factures, paiements reçus après la période compris."
                        :chiffres="chiffresDettes"
                    />
                    <ListeFactures
                        :lignes="rapport.creances.lignes"
                        :afficher-agent="!maSituation"
                        afficher-anciennete
                        vide="Aucune dette client sur la période."
                    />
                    <p
                        v-if="tronque(rapport.creances)"
                        class="text-xs text-muted-foreground"
                    >
                        {{ rapport.creances.lignes.length }} lignes affichées
                        (les plus anciennes) sur
                        {{ rapport.creances.total_lignes }} — exportez pour la
                        liste complète.
                    </p>
                </section>

                <!-- ── Mobile Money ───────────────────────────────────────── -->
                <section
                    v-if="onglet === 'mobile_money'"
                    id="rapport-panel-mobile_money"
                    role="tabpanel"
                    aria-labelledby="rapport-tab-mobile_money"
                    tabindex="0"
                    class="space-y-4 rounded-xl border bg-card p-4 sm:p-5"
                >
                    <EnTeteSection
                        titre="Mobile Money"
                        aide="Rapprochez chaque référence du relevé de l'opérateur."
                        :chiffres="chiffresMobileMoney"
                    />
                    <Alert
                        v-if="anomaliesMobileMoney > 0"
                        data-testid="mobile-money-anomalies"
                    >
                        <AlertTriangle class="text-amber-500" />
                        <AlertTitle>
                            {{ anomaliesMobileMoney }} référence(s) à vérifier
                        </AlertTitle>
                        <AlertDescription>
                            {{ rapport.mobile_money.resume.reference_absente }}
                            absente(s),
                            {{
                                rapport.mobile_money.resume.reference_dupliquee
                            }}
                            déjà utilisée(s) pour le même opérateur dans
                            l'organisation.
                        </AlertDescription>
                    </Alert>
                    <ListeEncaissements
                        :lignes="rapport.mobile_money.lignes"
                        :afficher-agent="!maSituation"
                        :afficher-reversement="!maSituation"
                        controle
                        vide="Aucun encaissement Mobile Money sur la période."
                    />
                    <!-- Comptes Mobile Money des agents (ADR 0027) : ce que l'agent détient encore, ce
                         qu'il a déjà transféré à l'agence, les frais. Même fiche que la caisse en espèces. -->
                    <SectionCaisse
                        v-if="
                            rapport.comptes_mobile_money &&
                            (maSituation ||
                                !rapport.comptes_mobile_money.aucune_caisse)
                        "
                        nature="mobile_money"
                        class="border-t pt-4"
                        data-testid="comptes-mobile-money"
                        :caisse="rapport.comptes_mobile_money"
                        :ma-situation="maSituation"
                        :debut="periode.date_debut"
                        :fin="periode.date_fin"
                    />
                    <p
                        v-if="tronque(rapport.mobile_money)"
                        class="text-xs text-muted-foreground"
                    >
                        {{ rapport.mobile_money.lignes.length }} lignes
                        affichées sur {{ rapport.mobile_money.total_lignes }} —
                        exportez pour la liste complète.
                    </p>
                </section>

                <!-- ── Caisse ─────────────────────────────────────────────── -->
                <SectionCaisse
                    v-if="onglet === 'caisse'"
                    id="rapport-panel-caisse"
                    role="tabpanel"
                    aria-labelledby="rapport-tab-caisse"
                    tabindex="0"
                    class="rounded-xl border bg-card p-4 sm:p-5"
                    :caisse="rapport.caisse"
                    :ma-situation="maSituation"
                    :debut="periode.date_debut"
                    :fin="periode.date_fin"
                />

                <!-- ── Charges ────────────────────────────────────────────── -->
                <section
                    v-if="onglet === 'charges'"
                    id="rapport-panel-charges"
                    role="tabpanel"
                    aria-labelledby="rapport-tab-charges"
                    tabindex="0"
                    class="space-y-4 rounded-xl border bg-card p-4 sm:p-5"
                >
                    <EnTeteSection
                        :titre="
                            maSituation
                                ? 'Mes charges saisies'
                                : 'Charges saisies par les agents'
                        "
                        aide="Dépenses saisies sur la période (date de la dépense), validées ou en attente de validation. Validée = payée : la validation fait sortir l'argent de la trésorerie. Les brouillons, rejetées et annulées ne comptent pas."
                        :chiffres="chiffresCharges"
                    />
                    <ListeCharges
                        :lignes="rapport.charges.lignes"
                        :afficher-agent="!maSituation"
                        :peut-voir="peut_voir_depenses ?? false"
                    />
                    <p
                        v-if="tronque(rapport.charges)"
                        class="text-xs text-muted-foreground"
                    >
                        {{ rapport.charges.lignes.length }} lignes affichées sur
                        {{ rapport.charges.total_lignes }} — exportez pour la
                        liste complète.
                    </p>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
