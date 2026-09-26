<script setup lang="ts">
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { useUrlTab } from '@/composables/useUrlTab';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatGNF } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import type { RapportActivite } from '@/types/rapports';
import { Head, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ChartColumn,
    FileSpreadsheet,
    FileText,
    Info,
    UserRound,
} from 'lucide-vue-next';
import { computed } from 'vue';
import CarteSection from './partials/CarteSection.vue';
import EnTeteSection from './partials/EnTeteSection.vue';
import { dateFr, pluriel } from './partials/format';
import ListeEncaissements from './partials/ListeEncaissements.vue';
import ListeFactures from './partials/ListeFactures.vue';
import SectionCaisse from './partials/SectionCaisse.vue';

// Même page pour « Ma situation » (agent imposé par le serveur) et le rapport d'activité (agences
// et agents selon les droits) — cf. RapportActivitePresenter. Les cartes du haut SONT les onglets :
// une carte résume un bloc et ouvre son détail. Chaque bloc est calculé indépendamment : jamais de
// « reste » déduit par différence entre deux blocs.
const props = defineProps<{
    mode: 'ma_situation' | 'rapport';
    url: string;
    export_url: string;
    filters: {
        periode: string;
        date_from: string | null;
        date_to: string | null;
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
] as const;
type Onglet = (typeof ONGLETS)[number];
const { onglet, choisir } = useUrlTab<Onglet>(ONGLETS, 'ventes');

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

// ── Cartes-onglets ────────────────────────────────────────────────────────────

const anomaliesMobileMoney = computed(
    () =>
        props.rapport.mobile_money.resume.reference_absente +
        props.rapport.mobile_money.resume.reference_dupliquee,
);

interface Carte {
    cle: Onglet;
    libelle: string;
    valeur: string;
    detail: string;
    avertissement: string | null;
}

const cartes = computed((): Carte[] => {
    const r = props.rapport;
    const liste: Carte[] = [
        {
            cle: 'ventes',
            libelle: maSituation.value ? 'Mes ventes' : 'Ventes',
            valeur: formatGNF(r.ventes.resume.facture),
            detail: pluriel(r.ventes.resume.nombre, 'vente'),
            avertissement: null,
        },
        {
            cle: 'encaissements',
            libelle: maSituation.value ? 'Mes encaissements' : 'Encaissé',
            valeur: formatGNF(r.encaissements.resume.montant),
            detail: pluriel(r.encaissements.resume.nombre, 'paiement'),
            avertissement: null,
        },
        {
            cle: 'creances',
            libelle: 'Dettes clients',
            valeur: formatGNF(r.creances.resume.reste),
            detail: `${pluriel(r.creances.resume.nombre, 'facture')} · toutes dates`,
            avertissement: null,
        },
        {
            cle: 'mobile_money',
            libelle: 'Mobile Money',
            valeur: formatGNF(r.mobile_money.resume.montant),
            detail: pluriel(r.mobile_money.resume.nombre, 'paiement'),
            avertissement:
                anomaliesMobileMoney.value > 0
                    ? `${anomaliesMobileMoney.value} à vérifier`
                    : null,
        },
    ];
    if (!maSituation.value) {
        liste.push({
            cle: 'caisse',
            libelle: 'Caisses dédiées',
            valeur: r.caisse.aucune_caisse
                ? '—'
                : formatGNF(r.caisse.resume.solde_actuel),
            detail: r.caisse.aucune_caisse
                ? 'Aucune caisse dédiée'
                : 'À remettre — solde théorique',
            avertissement: null,
        });
    }

    return liste;
});

const dernierVersement = computed(() => {
    const versements = props.rapport.caisse.fiches
        .map((f) => f.dernier_versement)
        .filter((v) => v !== null)
        .sort((a, b) => b.date_envoi.localeCompare(a.date_envoi));
    if (versements.length === 0) return 'Aucun versement enregistré';

    return `Dernier versement : ${dateFr(versements[0].date_envoi)} · ${formatGNF(versements[0].montant)}`;
});

// ── Filtres et exports ────────────────────────────────────────────────────────

const filterFields = computed<FilterField[]>(() => [
    ...(maSituation.value
        ? []
        : [
              {
                  key: 'agent_id',
                  label: 'Agent',
                  type: 'select' as const,
                  searchable: true,
                  inline: true,
                  wide: true,
                  placeholder: 'Tous les agents',
                  options: props.agents,
              },
          ]),
    {
        key: 'periode',
        label: 'Période',
        type: 'period',
        inline: true,
        options: props.periode.options,
        defaultValue: 'aujourd_hui',
        startKey: 'date_from',
        endKey: 'date_to',
    },
]);

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
        { libelle: 'Facturé', valeur: formatGNF(v.facture) },
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

const chiffresEncaissements = computed(() =>
    props.rapport.encaissements.par_moyen.map((m) => ({
        libelle: m.libelle,
        valeur: formatGNF(m.montant),
    })),
);

const chiffresDettes = computed(() => {
    const c = props.rapport.creances.resume;

    return [
        { libelle: 'Impayées', valeur: String(c.impayees) },
        { libelle: 'Partiellement payées', valeur: String(c.partielles) },
        { libelle: 'Reste dû', valeur: formatGNF(c.reste) },
        { libelle: 'Plus ancienne', valeur: dateFr(c.plus_ancienne) },
    ];
});

const chiffresMobileMoney = computed(() =>
    props.rapport.mobile_money.par_operateur.map((o) => ({
        libelle: o.libelle,
        valeur: formatGNF(o.montant),
    })),
);
</script>

<template>
    <Head :title="titre" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full space-y-5 p-4 sm:p-6">
            <!-- En-tête : où je suis, quel périmètre, quelle période -->
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0 space-y-0.5">
                    <h1 class="flex items-center gap-2 text-xl font-semibold">
                        <UserRound
                            v-if="maSituation"
                            class="h-5 w-5 text-muted-foreground"
                        />
                        <ChartColumn
                            v-else
                            class="h-5 w-5 text-muted-foreground"
                        />
                        {{ titre }}
                    </h1>
                    <p
                        class="text-sm font-medium"
                        data-testid="rapport-perimetre"
                    >
                        {{ perimetre }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ periode.libelle }}
                    </p>
                </div>
                <div class="flex gap-2">
                    <a
                        :href="exportHref('xlsx')"
                        data-testid="rapport-export-excel"
                        class="inline-flex h-9 items-center gap-1.5 rounded-md border bg-background px-3 text-sm font-medium hover:bg-muted"
                    >
                        <FileSpreadsheet class="h-4 w-4" /> Excel
                    </a>
                    <a
                        :href="exportHref('pdf')"
                        data-testid="rapport-export-pdf"
                        class="inline-flex h-9 items-center gap-1.5 rounded-md border bg-background px-3 text-sm font-medium hover:bg-muted"
                    >
                        <FileText class="h-4 w-4" /> PDF
                    </a>
                </div>
            </div>

            <DataFilters
                :url="url"
                :values="filters"
                :fields="filterFields"
                :sites="sites"
                :hide-agence-selector="maSituation"
                :result-count="0"
                hide-result-count
            />

            <!-- Cartes-onglets : chaque carte résume un bloc et en ouvre le détail -->
            <div role="tablist" class="space-y-2 sm:space-y-3">
                <template v-if="maSituation">
                    <CarteSection
                        v-if="!rapport.caisse.aucune_caisse"
                        testid="rapport-tab-caisse"
                        libelle="Ma caisse · À remettre — solde théorique"
                        :valeur="formatGNF(rapport.caisse.resume.solde_actuel)"
                        :detail="dernierVersement"
                        :active="onglet === 'caisse'"
                        large
                        class="w-full"
                        @choisir="choisir('caisse')"
                    />
                    <div
                        v-else
                        class="flex items-center gap-2 rounded-xl border border-blue-200 bg-blue-50/60 px-3 py-2.5 text-sm dark:border-blue-900 dark:bg-blue-950/30"
                    >
                        <Info class="h-4 w-4 shrink-0 text-blue-500" />
                        <span data-testid="kpi-caisse"
                            >Aucune caisse dédiée</span
                        >
                    </div>
                </template>

                <div
                    class="grid grid-cols-2 gap-2 sm:gap-3"
                    :class="
                        maSituation
                            ? 'lg:grid-cols-4'
                            : 'sm:grid-cols-3 lg:grid-cols-5'
                    "
                >
                    <CarteSection
                        v-for="c in cartes"
                        :key="c.cle"
                        :testid="`rapport-tab-${c.cle}`"
                        :libelle="c.libelle"
                        :valeur="c.valeur"
                        :detail="c.detail"
                        :avertissement="c.avertissement"
                        :active="onglet === c.cle"
                        @choisir="choisir(c.cle)"
                    />
                </div>
            </div>

            <!-- ── Ventes ─────────────────────────────────────────────────── -->
            <section v-if="onglet === 'ventes'" class="space-y-3">
                <EnTeteSection
                    :titre="maSituation ? 'Mes ventes' : 'Ventes de la période'"
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
                    {{ rapport.ventes.total_lignes }} — exportez pour la liste
                    complète (les totaux portent sur toutes les lignes).
                </p>
            </section>

            <!-- ── Encaissements ──────────────────────────────────────────── -->
            <section v-if="onglet === 'encaissements'" class="space-y-3">
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
                    :total="rapport.encaissements.resume.montant"
                    vide="Aucun encaissement sur la période."
                />
                <p
                    v-if="tronque(rapport.encaissements)"
                    class="text-xs text-muted-foreground"
                >
                    {{ rapport.encaissements.lignes.length }} lignes affichées
                    sur {{ rapport.encaissements.total_lignes }} — exportez pour
                    la liste complète.
                </p>
            </section>

            <!-- ── Dettes clients ─────────────────────────────────────────── -->
            <section v-if="onglet === 'creances'" class="space-y-3">
                <EnTeteSection
                    titre="Dettes clients"
                    aide="Ce que les clients doivent encore : état actuel, toutes dates confondues — la période choisie ne s'applique pas ici."
                    :chiffres="chiffresDettes"
                />
                <ListeFactures
                    :lignes="rapport.creances.lignes"
                    :afficher-agent="!maSituation"
                    afficher-anciennete
                    vide="Aucune dette client en cours."
                />
                <p
                    v-if="tronque(rapport.creances)"
                    class="text-xs text-muted-foreground"
                >
                    {{ rapport.creances.lignes.length }} lignes affichées (les
                    plus anciennes) sur {{ rapport.creances.total_lignes }} —
                    exportez pour la liste complète.
                </p>
            </section>

            <!-- ── Mobile Money ───────────────────────────────────────────── -->
            <section v-if="onglet === 'mobile_money'" class="space-y-3">
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
                        {{ rapport.mobile_money.resume.reference_dupliquee }}
                        déjà utilisée(s) pour le même opérateur dans
                        l'organisation.
                    </AlertDescription>
                </Alert>
                <ListeEncaissements
                    :lignes="rapport.mobile_money.lignes"
                    :afficher-agent="!maSituation"
                    controle
                    vide="Aucun encaissement Mobile Money sur la période."
                />
                <p
                    v-if="tronque(rapport.mobile_money)"
                    class="text-xs text-muted-foreground"
                >
                    {{ rapport.mobile_money.lignes.length }} lignes affichées
                    sur {{ rapport.mobile_money.total_lignes }} — exportez pour
                    la liste complète.
                </p>
            </section>

            <!-- ── Caisse ─────────────────────────────────────────────────── -->
            <SectionCaisse
                v-if="onglet === 'caisse'"
                :caisse="rapport.caisse"
                :ma-situation="maSituation"
                :debut="periode.date_debut"
                :fin="periode.date_fin"
            />
        </div>
    </AppLayout>
</template>
