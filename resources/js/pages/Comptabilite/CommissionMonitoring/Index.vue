<script setup lang="ts">
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import KpiCard from '@/components/KpiCard.vue';
import ListPageActions from '@/components/ListPageActions.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useFlashToast } from '@/composables/useFlashToast';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatGNF } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ExternalLink, RotateCw, ShieldCheck } from 'lucide-vue-next';
import { useToast } from 'primevue/usetoast';
import { computed, ref, watch } from 'vue';

interface Option {
    value: string;
    label: string;
}

interface PartLivreur {
    livreur_id: string;
    livreur_nom?: string;
    montant_unitaire: number | null;
}

interface ErreurCible {
    code: string;
    message: string;
    contexte: {
        categorie_nom?: string | null;
        categorie_noms?: string[];
        consultant_nom?: string | null;
        periode_reference?: string;
        quantite?: number;
        enveloppe_unitaire?: number;
        attribue?: number;
        ecart?: number;
        parts?: PartLivreur[];
    };
}

interface Tentative {
    id: string;
    date: string | null;
    statut: string;
    statut_label: string;
    declenchee_par: string | null;
    auteur: string | null;
    message: string | null;
}

interface Anomalie {
    id: string;
    statut: string;
    statut_label: string;
    ouverte: boolean;
    relancable: boolean;
    raison_sans_objet: string | null;
    source_type: 'vente' | 'transfert';
    source_label: string;
    reference: string;
    source_url: string | null;
    date_operation: string | null;
    client: string | null;
    montant_operation: number | null;
    site_nom: string | null;
    vehicule_nom: string | null;
    cible_label: string;
    processus_label: string | null;
    categories: string[];
    motif_code: string;
    motif_label: string;
    action_corrective: string;
    message: string;
    erreurs: ErreurCible[];
    montant_attendu: number | null;
    detectee_le: string | null;
    derniere_tentative_le: string | null;
    nb_tentatives_echouees: number;
    nb_tentatives: number;
    regularisee_le: string | null;
    montant_regularise: number | null;
    tentatives: Tentative[];
    enveloppes_generees: {
        cible_label: string;
        montant: number;
        statut: string;
    }[];
}

interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

const props = defineProps<{
    anomalies: Paginated<Anomalie>;
    kpis: {
        non_generees: number;
        echecs_recurrents: number;
        regularisees: number;
        sans_objet: number;
        montant_en_attente: number;
    };
    filtres: {
        site_ids: string[];
        statut: string;
        reference: string;
        cible: string;
        processus: string;
        motif: string;
        detection_debut: string;
        detection_fin: string;
        tentatives_min: string;
    };
    sites: { id: string; nom: string }[];
    statut_options: Option[];
    cible_options: Option[];
    motif_options: Option[];
    processus_options: Option[];
    seuil_echec_recurrent: number;
    can_relancer: boolean;
}>();

const URL_MONITORING = '/backoffice/comptabilite/commissions/monitoring';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité' },
    { title: 'Commissions' },
    { title: 'Monitoring', href: URL_MONITORING },
];

useFlashToast();
const toast = useToast();
const { can } = usePermissions();

// Même clé que RelancerCommissionMonitoringController (commissions.update).
const peutRelancer = computed(
    () => props.can_relancer && can('commissions.update'),
);

// ── Filtres ──────────────────────────────────────────────────────────────────

const filterFields = computed((): FilterField[] => [
    {
        key: 'statut',
        label: 'Statut',
        type: 'select',
        inline: true,
        options: props.statut_options,
    },
    {
        key: 'reference',
        label: 'Commande / véhicule',
        type: 'text',
        inline: true,
        placeholder: 'Référence, véhicule, client…',
    },
    {
        key: 'cible',
        label: 'Cible',
        type: 'select',
        options: props.cible_options,
    },
    {
        key: 'motif',
        label: 'Motif',
        type: 'select',
        options: props.motif_options,
    },
    {
        key: 'processus',
        label: 'Processus',
        type: 'select',
        options: props.processus_options,
    },
    {
        key: 'detection',
        label: 'Détectée entre',
        type: 'date-range',
    },
    {
        key: 'tentatives_min',
        label: 'Tentatives en échec (minimum)',
        type: 'number',
    },
]);

const filterValues = computed(() => ({ ...props.filtres }));

function filtrerStatut(statut: string) {
    router.get(
        URL_MONITORING,
        { ...props.filtres, statut, page: undefined },
        { preserveScroll: true, preserveState: true },
    );
}

// ── KPI ──────────────────────────────────────────────────────────────────────

const cartes = computed(() => [
    {
        id: 'non_generee',
        titre: 'Non générées',
        valeur: props.kpis.non_generees,
        detail: 'Commission attendue, absente',
    },
    {
        id: 'echec_recurrent',
        titre: 'Échecs récurrents',
        valeur: props.kpis.echecs_recurrents,
        detail: `${props.seuil_echec_recurrent} tentatives ou plus en échec`,
    },
    {
        id: 'regularisee',
        titre: 'Régularisées',
        valeur: props.kpis.regularisees,
        detail: 'Commission finalement générée',
    },
    {
        id: 'ouvertes',
        titre: 'Montant en attente',
        valeur: formatGNF(props.kpis.montant_en_attente),
        detail: 'Total attendu des anomalies ouvertes',
    },
]);

// ── Sélection et relance ─────────────────────────────────────────────────────

const selection = ref<string[]>([]);
const relanceEnCours = ref(false);

watch(
    () => props.anomalies.data,
    () => {
        const visibles = new Set(
            props.anomalies.data.filter((a) => a.relancable).map((a) => a.id),
        );
        selection.value = selection.value.filter((id) => visibles.has(id));
    },
);

const relancables = computed(() =>
    props.anomalies.data.filter((a) => a.relancable),
);

const toutSelectionne = computed(
    () =>
        relancables.value.length > 0 &&
        relancables.value.every((a) => selection.value.includes(a.id)),
);

function basculerTout(coche: boolean) {
    selection.value = coche ? relancables.value.map((a) => a.id) : [];
}

function basculer(id: string, coche: boolean) {
    selection.value = coche
        ? [...new Set([...selection.value, id])]
        : selection.value.filter((s) => s !== id);
}

// Lots successifs : chaque requête reste courte (sous le délai nginx), quelle que soit la
// taille de la sélection — même plafond serveur : RelancerCommissionMonitoringController::LOT_MAX.
const LOT_RELANCE = 10;
const progression = ref<{ fait: number; total: number } | null>(null);

interface ResultatRelance {
    regularisees: number;
    sans_objet: number;
    echecs: { reference: string; message: string }[];
}

async function relancerLot(ids: string[]): Promise<ResultatRelance> {
    const response = await fetch(`${URL_MONITORING}/relancer`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(
                document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)?.[1] ??
                    '',
            ),
        },
        body: JSON.stringify({ anomalies: ids }),
    });
    const data = await response.json().catch(() => null);
    if (!response.ok) {
        const premiere = Object.values(data?.errors ?? {}).flat()[0];
        throw new Error(
            typeof premiere === 'string'
                ? premiere
                : (data?.message ?? 'La relance a échoué.'),
        );
    }
    return data as ResultatRelance;
}

// Mêmes messages que la réponse non-JSON de RelancerCommissionMonitoringController.
function afficherResultat(r: ResultatRelance, interruption: string | null) {
    const prefixe =
        r.regularisees > 0
            ? `${r.regularisees} commission(s) régularisée(s). `
            : '';
    let detailMessage: string;
    let severity: 'success' | 'warn' | 'error';

    if (interruption !== null) {
        detailMessage = `${prefixe}Relance interrompue : ${interruption}`;
        severity = 'error';
    } else if (r.echecs.length > 0) {
        const premier = r.echecs[0];
        detailMessage =
            prefixe +
            (r.echecs.length === 1
                ? `La génération a de nouveau échoué pour ${premier.reference} : ${premier.message}`
                : `${r.echecs.length} anomalie(s) toujours en échec (ex. ${premier.reference} : ${premier.message}).`);
        severity = r.regularisees > 0 ? 'warn' : 'error';
    } else if (r.regularisees === 0 && r.sans_objet === 0) {
        detailMessage = 'Aucune anomalie ouverte à relancer dans la sélection.';
        severity = 'error';
    } else {
        detailMessage =
            r.regularisees === 1 && r.sans_objet === 0
                ? 'Commission régularisée avec succès.'
                : (
                      prefixe +
                      (r.sans_objet > 0
                          ? `${r.sans_objet} anomalie(s) désormais sans objet.`
                          : '')
                  ).trim();
        severity = 'success';
    }

    toast.add({
        group: 'top',
        severity,
        summary: 'Relance',
        detail: detailMessage,
        life: severity === 'success' ? 4000 : 8000,
    });
}

async function relancer(ids: string[]) {
    if (relanceEnCours.value || ids.length === 0) return;
    relanceEnCours.value = true;
    progression.value = { fait: 0, total: ids.length };

    const cumul: ResultatRelance = {
        regularisees: 0,
        sans_objet: 0,
        echecs: [],
    };
    let interruption: string | null = null;
    try {
        for (let i = 0; i < ids.length; i += LOT_RELANCE) {
            const r = await relancerLot(ids.slice(i, i + LOT_RELANCE));
            cumul.regularisees += r.regularisees;
            cumul.sans_objet += r.sans_objet;
            cumul.echecs.push(...r.echecs);
            progression.value = {
                fait: Math.min(i + LOT_RELANCE, ids.length),
                total: ids.length,
            };
        }
    } catch (e) {
        interruption = e instanceof Error ? e.message : 'La relance a échoué.';
    }

    afficherResultat(cumul, interruption);
    if (interruption === null && cumul.echecs.length === 0) {
        selection.value = [];
        detail.value = null;
    }

    router.reload({
        onFinish: () => {
            relanceEnCours.value = false;
            progression.value = null;
        },
    });
}

// ── Détail ───────────────────────────────────────────────────────────────────

const detail = ref<Anomalie | null>(null);
const detailOuvert = computed({
    get: () => detail.value !== null,
    set: (ouvert: boolean) => {
        if (!ouvert) detail.value = null;
    },
});

watch(
    () => props.anomalies.data,
    (lignes) => {
        if (detail.value) {
            detail.value =
                lignes.find((a) => a.id === detail.value?.id) ?? null;
        }
    },
);

// « Cible equipe_livraison : … » : la cible est déjà affichée dans sa propre colonne.
function sansPrefixeCible(message: string): string {
    return message
        .split(' | ')
        .map((m) => m.replace(/^Cible \w+ : /, ''))
        .join(' | ');
}

function montant(val: number | null | undefined): string {
    return val === null || val === undefined ? '—' : formatGNF(val);
}
</script>

<template>
    <Head title="Monitoring des commissions" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-5 p-4 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Monitoring des commissions
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Commissions attendues mais non générées : motif,
                        configuration en cause et relance.
                    </p>
                </div>
                <ListPageActions>
                    <template #filters>
                        <DataFilters
                            trigger-only
                            :url="URL_MONITORING"
                            :values="filterValues"
                            :fields="filterFields"
                            :sites="sites"
                            :result-count="anomalies.total"
                        />
                    </template>
                    <template v-if="peutRelancer" #primary>
                        <Button
                            size="sm"
                            data-testid="monitoring-relancer-selection"
                            :disabled="selection.length === 0 || relanceEnCours"
                            @click="relancer(selection)"
                        >
                            <RotateCw
                                class="mr-1.5 h-3.5 w-3.5"
                                :class="relanceEnCours && 'animate-spin'"
                            />
                            <template
                                v-if="progression && progression.total > 1"
                            >
                                Relance {{ progression.fait }}/{{
                                    progression.total
                                }}…
                            </template>
                            <template v-else>
                                Relancer la sélection
                                <span v-if="selection.length > 0"
                                    >({{ selection.length }})</span
                                >
                            </template>
                        </Button>
                    </template>
                </ListPageActions>
            </div>

            <div class="@container">
                <div
                    class="grid grid-cols-12 gap-7"
                    data-testid="monitoring-kpis"
                >
                    <div
                        v-for="carte in cartes"
                        :key="carte.id"
                        class="col-span-12 @[40rem]:col-span-6 @[70rem]:col-span-3"
                        :data-testid="`monitoring-kpi-${carte.id}`"
                    >
                        <KpiCard
                            as="button"
                            :title="carte.titre"
                            :value="carte.valeur"
                            :detail="carte.detail"
                            :active="filtres.statut === carte.id"
                            @click="filtrerStatut(carte.id)"
                        />
                    </div>
                </div>
            </div>

            <div class="overflow-hidden rounded-xl border bg-card shadow-sm">
                <div
                    class="flex items-center justify-between gap-4 border-b px-5 py-3"
                >
                    <h2 class="text-base font-semibold">Anomalies</h2>
                    <span class="text-xs text-muted-foreground">
                        {{ anomalies.total }} résultat{{
                            anomalies.total !== 1 ? 's' : ''
                        }}
                    </span>
                </div>

                <div
                    v-if="anomalies.data.length > 0"
                    class="max-w-full overflow-x-auto"
                >
                    <table
                        class="w-full min-w-[1040px] text-sm"
                        data-testid="monitoring-table"
                    >
                        <thead>
                            <tr class="border-b bg-muted/50 text-left">
                                <th v-if="peutRelancer" class="w-10 px-4 py-3">
                                    <Checkbox
                                        aria-label="Tout sélectionner"
                                        :model-value="toutSelectionne"
                                        :disabled="relancables.length === 0"
                                        @update:model-value="
                                            (v) => basculerTout(v === true)
                                        "
                                    />
                                </th>
                                <th
                                    class="px-4 py-3 font-semibold text-foreground/70"
                                >
                                    Commande
                                </th>
                                <th
                                    class="px-4 py-3 font-semibold text-foreground/70"
                                >
                                    Cible
                                </th>
                                <th
                                    class="px-4 py-3 font-semibold text-foreground/70"
                                >
                                    Véhicule / agence
                                </th>
                                <th
                                    class="px-4 py-3 font-semibold text-foreground/70"
                                >
                                    Motif
                                </th>
                                <th
                                    class="px-4 py-3 text-right font-semibold text-foreground/70"
                                >
                                    Montant attendu
                                </th>
                                <th
                                    class="px-4 py-3 font-semibold text-foreground/70"
                                >
                                    Tentatives
                                </th>
                                <th
                                    class="px-4 py-3 font-semibold text-foreground/70"
                                >
                                    Statut
                                </th>
                                <th
                                    class="sticky right-0 z-20 border-l bg-muted px-4 py-3"
                                    aria-label="Actions"
                                />
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="a in anomalies.data"
                                :key="a.id"
                                class="cursor-pointer even:bg-muted/20 hover:bg-muted/40"
                                :data-testid="`monitoring-ligne-${a.reference}`"
                                @click="detail = a"
                            >
                                <td
                                    v-if="peutRelancer"
                                    class="px-4 py-3"
                                    @click.stop
                                >
                                    <Checkbox
                                        v-if="a.relancable"
                                        :aria-label="`Sélectionner ${a.reference} — ${a.cible_label}`"
                                        :model-value="selection.includes(a.id)"
                                        @update:model-value="
                                            (v) => basculer(a.id, v === true)
                                        "
                                    />
                                </td>
                                <td class="px-4 py-3">
                                    <p class="font-semibold">
                                        {{ a.reference }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ a.source_label }}
                                        <template v-if="a.date_operation">
                                            · {{ a.date_operation }}
                                        </template>
                                    </p>
                                </td>
                                <td class="px-4 py-3">
                                    <p class="font-medium">
                                        {{ a.cible_label }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{
                                            a.categories.join(', ') ||
                                            a.processus_label
                                        }}
                                    </p>
                                </td>
                                <td class="px-4 py-3">
                                    <p>{{ a.vehicule_nom ?? '—' }}</p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ a.site_nom ?? '—' }}
                                    </p>
                                </td>
                                <td class="max-w-[260px] px-4 py-3">
                                    <p class="font-medium">
                                        {{ a.motif_label }}
                                    </p>
                                    <p
                                        class="line-clamp-2 text-xs text-muted-foreground"
                                        :title="sansPrefixeCible(a.message)"
                                    >
                                        {{ sansPrefixeCible(a.message) }}
                                    </p>
                                </td>
                                <td
                                    class="px-4 py-3 text-right whitespace-nowrap tabular-nums"
                                >
                                    {{ montant(a.montant_attendu) }}
                                </td>
                                <td class="min-w-[130px] px-4 py-3">
                                    <p class="tabular-nums">
                                        {{ a.nb_tentatives_echouees }} en échec
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        Détectée le {{ a.detectee_le }}
                                    </p>
                                </td>
                                <td class="px-4 py-3">
                                    <StatusDot
                                        :status="a.statut"
                                        :label="a.statut_label"
                                    />
                                </td>
                                <td
                                    class="sticky right-0 z-10 border-l bg-card px-4 py-3 text-right whitespace-nowrap"
                                    @click.stop
                                >
                                    <Button
                                        v-if="peutRelancer && a.relancable"
                                        variant="outline"
                                        size="sm"
                                        class="h-7 px-2 text-xs"
                                        :disabled="relanceEnCours"
                                        :data-testid="`monitoring-relancer-${a.reference}`"
                                        @click="relancer([a.id])"
                                    >
                                        <RotateCw class="mr-1 h-3.5 w-3.5" />
                                        Relancer
                                    </Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div
                    v-else
                    data-testid="monitoring-vide"
                    class="flex flex-col items-center gap-3 py-16 text-muted-foreground"
                >
                    <ShieldCheck class="h-12 w-12 opacity-30" />
                    <p class="text-sm">
                        Aucune anomalie pour ces filtres : toutes les
                        commissions attendues ont été générées.
                    </p>
                </div>
            </div>

            <div
                v-if="anomalies.last_page > 1"
                class="flex items-center justify-between"
            >
                <p class="text-sm text-muted-foreground">
                    {{ anomalies.from }}–{{ anomalies.to }} sur
                    {{ anomalies.total }}
                </p>
                <div class="flex items-center gap-1">
                    <Link
                        v-for="link in anomalies.links"
                        :key="link.label"
                        :href="link.url ?? '#'"
                        preserve-scroll
                        class="inline-flex h-8 items-center justify-center rounded px-3 text-sm transition-colors"
                        :class="[
                            link.active
                                ? 'bg-primary font-medium text-primary-foreground'
                                : 'text-muted-foreground hover:bg-muted/50',
                            !link.url ? 'pointer-events-none opacity-40' : '',
                        ]"
                    >
                        {{
                            link.label
                                .replace('&laquo;', '‹')
                                .replace('&raquo;', '›')
                                .replace('Previous', '')
                                .replace('Next', '')
                                .trim()
                        }}
                    </Link>
                </div>
            </div>
        </div>

        <Sheet v-model:open="detailOuvert">
            <SheetContent
                side="right"
                class="flex w-full flex-col gap-0 sm:max-w-xl"
                data-testid="monitoring-detail"
            >
                <template v-if="detail">
                    <SheetHeader class="border-b">
                        <SheetTitle>
                            {{ detail.reference }} — {{ detail.cible_label }}
                        </SheetTitle>
                        <SheetDescription>
                            <StatusDot
                                :status="detail.statut"
                                :label="detail.statut_label"
                            />
                        </SheetDescription>
                    </SheetHeader>

                    <div
                        class="flex-1 space-y-6 overflow-y-auto px-4 py-4 text-sm"
                    >
                        <section class="space-y-2">
                            <h3 class="font-semibold">
                                {{ detail.source_label }}
                            </h3>
                            <dl class="grid grid-cols-2 gap-x-4 gap-y-1.5">
                                <dt class="text-muted-foreground">Référence</dt>
                                <dd>{{ detail.reference }}</dd>
                                <dt class="text-muted-foreground">Date</dt>
                                <dd>{{ detail.date_operation ?? '—' }}</dd>
                                <template v-if="detail.client">
                                    <dt class="text-muted-foreground">
                                        Client
                                    </dt>
                                    <dd>{{ detail.client }}</dd>
                                </template>
                                <template v-if="detail.montant_operation">
                                    <dt class="text-muted-foreground">
                                        Montant
                                    </dt>
                                    <dd>
                                        {{ montant(detail.montant_operation) }}
                                    </dd>
                                </template>
                                <dt class="text-muted-foreground">Agence</dt>
                                <dd>{{ detail.site_nom ?? '—' }}</dd>
                                <dt class="text-muted-foreground">Véhicule</dt>
                                <dd>{{ detail.vehicule_nom ?? '—' }}</dd>
                            </dl>
                            <Button
                                v-if="detail.source_url"
                                as-child
                                variant="outline"
                                size="sm"
                            >
                                <Link
                                    :href="detail.source_url"
                                    data-testid="monitoring-voir-commande"
                                >
                                    <ExternalLink class="mr-1.5 h-3.5 w-3.5" />
                                    Voir
                                    {{
                                        detail.source_type === 'transfert'
                                            ? 'le transfert'
                                            : 'la commande'
                                    }}
                                </Link>
                            </Button>
                        </section>

                        <section class="space-y-2">
                            <h3 class="font-semibold">Commission attendue</h3>
                            <dl class="grid grid-cols-2 gap-x-4 gap-y-1.5">
                                <dt class="text-muted-foreground">Cible</dt>
                                <dd>{{ detail.cible_label }}</dd>
                                <dt class="text-muted-foreground">Processus</dt>
                                <dd>{{ detail.processus_label ?? '—' }}</dd>
                                <dt class="text-muted-foreground">
                                    Catégorie(s)
                                </dt>
                                <dd>
                                    {{ detail.categories.join(', ') || '—' }}
                                </dd>
                                <dt class="text-muted-foreground">
                                    Montant attendu
                                </dt>
                                <dd class="font-semibold">
                                    {{ montant(detail.montant_attendu) }}
                                </dd>
                                <template v-if="detail.regularisee_le">
                                    <dt class="text-muted-foreground">
                                        Régularisée le
                                    </dt>
                                    <dd>
                                        {{ detail.regularisee_le }} ({{
                                            montant(detail.montant_regularise)
                                        }})
                                    </dd>
                                </template>
                            </dl>
                            <p
                                v-if="detail.enveloppes_generees.length"
                                class="text-xs text-muted-foreground"
                            >
                                Déjà générées sur l'opération :
                                {{
                                    detail.enveloppes_generees
                                        .map(
                                            (e) =>
                                                `${e.cible_label} ${montant(e.montant)}`,
                                        )
                                        .join(' · ')
                                }}
                            </p>
                        </section>

                        <section class="space-y-3">
                            <h3 class="font-semibold">Blocage</h3>
                            <p
                                v-if="detail.raison_sans_objet"
                                class="text-muted-foreground"
                            >
                                {{ detail.raison_sans_objet }}
                            </p>
                            <div
                                v-for="(erreur, i) in detail.erreurs"
                                :key="i"
                                class="space-y-2 rounded-lg border p-3"
                            >
                                <p class="font-medium">
                                    {{ sansPrefixeCible(erreur.message) }}
                                </p>
                                <dl
                                    v-if="
                                        erreur.contexte.enveloppe_unitaire !==
                                        undefined
                                    "
                                    class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs"
                                >
                                    <template
                                        v-if="erreur.contexte.categorie_nom"
                                    >
                                        <dt class="text-muted-foreground">
                                            Catégorie
                                        </dt>
                                        <dd>
                                            {{ erreur.contexte.categorie_nom }}
                                        </dd>
                                    </template>
                                    <dt class="text-muted-foreground">
                                        Barème Livreur
                                    </dt>
                                    <dd>
                                        {{
                                            montant(
                                                erreur.contexte
                                                    .enveloppe_unitaire,
                                            )
                                        }}
                                        / unité
                                    </dd>
                                    <template
                                        v-if="
                                            erreur.contexte.attribue !==
                                            undefined
                                        "
                                    >
                                        <dt class="text-muted-foreground">
                                            Total des parts
                                        </dt>
                                        <dd>
                                            {{
                                                montant(
                                                    erreur.contexte.attribue,
                                                )
                                            }}
                                            / unité
                                        </dd>
                                        <dt class="text-muted-foreground">
                                            Écart
                                        </dt>
                                        <dd>
                                            {{ montant(erreur.contexte.ecart) }}
                                        </dd>
                                    </template>
                                    <template
                                        v-if="
                                            erreur.contexte.quantite !==
                                            undefined
                                        "
                                    >
                                        <dt class="text-muted-foreground">
                                            Quantité
                                        </dt>
                                        <dd>{{ erreur.contexte.quantite }}</dd>
                                    </template>
                                </dl>
                                <table
                                    v-if="erreur.contexte.parts?.length"
                                    class="w-full text-xs"
                                >
                                    <thead>
                                        <tr
                                            class="text-left text-muted-foreground"
                                        >
                                            <th class="py-1 font-medium">
                                                Membre
                                            </th>
                                            <th
                                                class="py-1 text-right font-medium"
                                            >
                                                Part / unité
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="part in erreur.contexte
                                                .parts"
                                            :key="part.livreur_id"
                                        >
                                            <td class="py-1">
                                                {{ part.livreur_nom }}
                                            </td>
                                            <td
                                                class="py-1 text-right tabular-nums"
                                            >
                                                {{
                                                    montant(
                                                        part.montant_unitaire,
                                                    )
                                                }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                                <p
                                    v-if="erreur.contexte.consultant_nom"
                                    class="text-xs text-muted-foreground"
                                >
                                    Consultant :
                                    {{ erreur.contexte.consultant_nom }}
                                </p>
                            </div>
                            <p
                                v-if="detail.ouverte"
                                class="text-muted-foreground"
                            >
                                <span class="font-medium text-foreground"
                                    >À faire :</span
                                >
                                {{ detail.action_corrective }}
                            </p>
                        </section>

                        <section class="space-y-2">
                            <h3 class="font-semibold">
                                Historique des tentatives ({{
                                    detail.nb_tentatives
                                }})
                            </h3>
                            <ol class="space-y-2">
                                <li
                                    v-for="t in detail.tentatives"
                                    :key="t.id"
                                    class="rounded-lg border p-3"
                                >
                                    <div
                                        class="flex items-center justify-between gap-2"
                                    >
                                        <StatusDot
                                            :status="t.statut"
                                            :label="t.statut_label"
                                        />
                                        <span
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ t.date }}
                                        </span>
                                    </div>
                                    <p
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        {{ t.declenchee_par }}
                                        <template v-if="t.auteur">
                                            · {{ t.auteur }}
                                        </template>
                                    </p>
                                    <p v-if="t.message" class="mt-1 text-xs">
                                        {{ t.message }}
                                    </p>
                                </li>
                            </ol>
                        </section>
                    </div>

                    <div
                        v-if="peutRelancer && detail.relancable"
                        class="border-t p-4"
                    >
                        <Button
                            class="w-full"
                            data-testid="monitoring-detail-relancer"
                            :disabled="relanceEnCours"
                            @click="relancer([detail.id])"
                        >
                            <RotateCw
                                class="mr-1.5 h-4 w-4"
                                :class="relanceEnCours && 'animate-spin'"
                            />
                            {{
                                relanceEnCours
                                    ? 'Relance en cours…'
                                    : 'Relancer la génération'
                            }}
                        </Button>
                    </div>
                </template>
            </SheetContent>
        </Sheet>
    </AppLayout>
</template>
