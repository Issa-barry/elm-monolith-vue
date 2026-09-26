<script setup lang="ts">
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import ListPageActions from '@/components/ListPageActions.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { useUrlTab } from '@/composables/useUrlTab';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatGNF } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import type { RapportActivite } from '@/types/rapports';
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowDownToLine,
    CalendarDays,
    ChartColumn,
    FileSpreadsheet,
    FileText,
    Info,
    ReceiptText,
    Smartphone,
    UserRound,
    Wallet,
} from 'lucide-vue-next';
import Select from 'primevue/select';
import { computed, ref, watch, type Component } from 'vue';
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

function naviguerCartes(event: KeyboardEvent) {
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
    icone: Component;
}

const cartes = computed((): Carte[] => {
    const r = props.rapport;
    const liste: Carte[] = [
        {
            cle: 'ventes',
            icone: ReceiptText,
            libelle: maSituation.value ? 'Mes ventes' : 'Ventes',
            valeur: formatGNF(r.ventes.resume.facture),
            detail: pluriel(r.ventes.resume.nombre, 'vente'),
            avertissement: null,
        },
        {
            cle: 'encaissements',
            icone: ArrowDownToLine,
            libelle: maSituation.value ? 'Mes encaissements' : 'Encaissé',
            valeur: formatGNF(r.encaissements.resume.montant),
            detail: pluriel(r.encaissements.resume.nombre, 'paiement'),
            avertissement: null,
        },
        {
            cle: 'creances',
            icone: ReceiptText,
            libelle: 'Dettes clients',
            valeur: formatGNF(r.creances.resume.reste),
            detail: `${pluriel(r.creances.resume.nombre, 'facture')} · toutes dates`,
            avertissement: null,
        },
        {
            cle: 'mobile_money',
            icone: Smartphone,
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
            icone: Wallet,
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
                  placeholder: 'Tous les agents',
                  options: props.agents,
              },
          ]),
]);

const periodeChoisie = ref(props.filters.periode);
const dateDebut = ref(props.filters.date_from ?? props.periode.date_debut);
const dateFin = ref(props.filters.date_to ?? props.periode.date_fin);
const periodeEnCours = ref(false);

function synchroniserPeriode() {
    periodeChoisie.value = props.filters.periode;
    dateDebut.value = props.filters.date_from ?? props.periode.date_debut;
    dateFin.value = props.filters.date_to ?? props.periode.date_fin;
}
watch(() => [props.filters, props.periode], synchroniserPeriode);

function parametresPeriode(
    choix: string,
    debut: string | null,
    fin: string | null,
): Record<string, string> {
    if (choix === 'personnalisee') {
        return {
            ...(debut ? { date_from: debut } : {}),
            ...(fin ? { date_to: fin } : {}),
        };
    }
    return choix === 'aujourd_hui' ? {} : { periode: choix };
}

// Le drawer conserve la période appliquée, jamais une plage encore en cours de saisie.
const parametresFiltres = computed(() => ({
    tab: onglet.value,
    ...parametresPeriode(
        props.filters.periode,
        props.filters.date_from,
        props.filters.date_to,
    ),
}));

function appliquerPeriode() {
    if (periodeEnCours.value) return;
    if (
        periodeChoisie.value === 'personnalisee' &&
        (!dateDebut.value || !dateFin.value || dateDebut.value > dateFin.value)
    )
        return;
    const params: Record<string, string | string[]> = {
        tab: onglet.value,
        ...parametresPeriode(
            periodeChoisie.value,
            dateDebut.value,
            dateFin.value,
        ),
    };
    if (!maSituation.value) {
        if (props.filters.site_ids.length)
            params.site_ids = props.filters.site_ids;
        if (props.filters.agent_id) params.agent_id = props.filters.agent_id;
    }
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
    if (choix !== 'personnalisee') appliquerPeriode();
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
        <div class="w-full min-w-0 space-y-6 p-4 sm:p-6">
            <!-- En-tête : où je suis, quel périmètre, quelle période -->
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0 space-y-2">
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
                    <div
                        class="flex items-center gap-1.5 text-sm text-muted-foreground"
                    >
                        <CalendarDays
                            class="h-4 w-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span>{{ periode.libelle }}</span>
                    </div>
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
                        </form>
                        <DataFilters
                            v-if="!maSituation"
                            trigger-only
                            :url="url"
                            :base-params="parametresFiltres"
                            :values="filters"
                            :fields="filterFields"
                            :sites="sites"
                            :hide-agence-selector="maSituation"
                            :result-count="0"
                            hide-result-count
                        />
                    </template>
                </ListPageActions>
            </div>

            <!-- Cartes-onglets : chaque carte résume un bloc et en ouvre le détail -->
            <div
                role="tablist"
                aria-label="Sections du rapport"
                class="space-y-3"
                @keydown="naviguerCartes"
            >
                <template v-if="maSituation">
                    <CarteSection
                        v-if="!rapport.caisse.aucune_caisse"
                        testid="rapport-tab-caisse"
                        :icone="Wallet"
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
                            : 'lg:grid-cols-3 xl:grid-cols-5'
                    "
                >
                    <CarteSection
                        v-for="c in cartes"
                        :key="c.cle"
                        :testid="`rapport-tab-${c.cle}`"
                        :libelle="c.libelle"
                        :icone="c.icone"
                        :valeur="c.valeur"
                        :detail="c.detail"
                        :avertissement="c.avertissement"
                        :active="onglet === c.cle"
                        :focusable="
                            maSituation &&
                            rapport.caisse.aucune_caisse &&
                            onglet === 'caisse' &&
                            c.cle === 'ventes'
                        "
                        @choisir="choisir(c.cle)"
                    />
                </div>
            </div>

            <!-- ── Ventes ─────────────────────────────────────────────────── -->
            <section
                v-if="onglet === 'ventes'"
                id="rapport-panel-ventes"
                role="tabpanel"
                aria-labelledby="rapport-tab-ventes"
                tabindex="0"
                class="space-y-4 rounded-xl border bg-card p-4 sm:p-5"
            >
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
                    contexte="Situation actuelle · Toutes dates"
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
        </div>
    </AppLayout>
</template>
