<script setup lang="ts">
import GraviteAnomalie from '@/components/controle-encaissements/GraviteAnomalie.vue';
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import KpiCard from '@/components/KpiCard.vue';
import ListPageActions from '@/components/ListPageActions.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useFlashToast } from '@/composables/useFlashToast';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatGNF } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { Clock, RefreshCw, ShieldCheck, TriangleAlert } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Option {
    value: string;
    label: string;
}

interface Ligne {
    id: string;
    type: string;
    type_label: string;
    gravite: string;
    gravite_label: string;
    statut: string;
    statut_label: string;
    ouverte: boolean;
    ecart_present: boolean;
    reference: string;
    facture_reference: string | null;
    site_nom: string | null;
    agents: string[];
    montant_facture: number | null;
    montant_encaisse_net: number | null;
    surplus: number;
    detectee_le: string | null;
    nb_relances: number;
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
    anomalies: Paginated<Ligne>;
    kpis: {
        nouvelles: number;
        critiques: number;
        en_cours: number;
        resolues: number;
        surplus: number;
    };
    filtres: {
        site_ids: string[];
        statut: string;
        reference: string;
        type: string;
        gravite: string;
        agent: string;
        detection_debut: string;
        detection_fin: string;
    };
    sites: { id: string; nom: string }[];
    statut_options: Option[];
    type_options: Option[];
    gravite_options: Option[];
    agent_options: Option[];
    controle: {
        dernier_planifie: string | null;
        planificateur_en_retard: boolean;
        dernier: string | null;
        dernier_declencheur: string | null;
        dernier_erreur: string | null;
    };
    can_traiter: boolean;
}>();

const URL_CONTROLE =
    '/backoffice/comptabilite/tresorerie/controle-encaissements';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité' },
    { title: 'Trésorerie' },
    { title: 'Contrôle des encaissements', href: URL_CONTROLE },
];

useFlashToast();
const { can } = usePermissions();

// Même clé que ControleEncaissementsController::analyser().
const peutTraiter = computed(
    () => props.can_traiter && can('encaissements.traiter_anomalies'),
);

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
        label: 'Commande / agent',
        type: 'text',
        inline: true,
        placeholder: 'Référence, facture, agent…',
    },
    {
        key: 'gravite',
        label: 'Gravité',
        type: 'select',
        options: props.gravite_options,
    },
    {
        key: 'type',
        label: "Type d'anomalie",
        type: 'select',
        options: props.type_options,
    },
    {
        key: 'agent',
        label: 'Agent',
        type: 'select',
        options: props.agent_options,
    },
    {
        key: 'detection',
        label: 'Détectée entre',
        type: 'date-range',
    },
]);

const filterValues = computed(() => ({ ...props.filtres }));

function filtrer(valeurs: Partial<typeof props.filtres>) {
    router.get(
        URL_CONTROLE,
        { ...props.filtres, ...valeurs, page: undefined },
        { preserveScroll: true, preserveState: true },
    );
}

const cartes = computed(() => [
    {
        id: 'nouvelles',
        titre: 'Anomalies nouvelles',
        valeur: props.kpis.nouvelles,
        detail: 'À examiner',
        actif: props.filtres.statut === 'nouvelle',
        filtre: { statut: 'nouvelle', gravite: '' },
    },
    {
        id: 'critiques',
        titre: 'Critiques ouvertes',
        valeur: props.kpis.critiques,
        detail: 'Écart certain ou incohérence comptable',
        actif:
            props.filtres.gravite === 'critique' &&
            props.filtres.statut === 'ouvertes',
        filtre: { statut: 'ouvertes', gravite: 'critique' },
    },
    {
        id: 'surplus',
        titre: 'Surplus à vérifier',
        valeur: formatGNF(props.kpis.surplus),
        detail: 'Total des écarts ouverts',
        actif: false,
        filtre: { statut: 'ouvertes', gravite: '' },
    },
    {
        id: 'en_cours',
        titre: "En cours d'analyse",
        valeur: props.kpis.en_cours,
        detail: 'Prises en charge',
        actif: props.filtres.statut === 'en_cours',
        filtre: { statut: 'en_cours', gravite: '' },
    },
    {
        id: 'resolues',
        titre: 'Résolues',
        valeur: props.kpis.resolues,
        detail: 'Écart disparu, clôturées',
        actif: props.filtres.statut === 'resolue',
        filtre: { statut: 'resolue', gravite: '' },
    },
]);

const analyseEnCours = ref(false);

function analyser() {
    if (analyseEnCours.value) return;
    analyseEnCours.value = true;
    router.post(
        `${URL_CONTROLE}/analyser`,
        {},
        {
            preserveScroll: true,
            onFinish: () => (analyseEnCours.value = false),
        },
    );
}

function montant(val: number | null): string {
    return val === null ? '—' : formatGNF(val);
}
</script>

<template>
    <Head title="Contrôle des encaissements" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-5 p-4 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Contrôle des encaissements
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Doubles encaissements, surplus et incohérences détectés
                        automatiquement : analyse et régularisation contrôlée.
                    </p>
                </div>
                <ListPageActions>
                    <template #filters>
                        <DataFilters
                            trigger-only
                            :url="URL_CONTROLE"
                            :values="filterValues"
                            :fields="filterFields"
                            :sites="sites"
                            :result-count="anomalies.total"
                        />
                    </template>
                    <template v-if="peutTraiter" #primary>
                        <Button
                            size="sm"
                            data-testid="controle-analyser"
                            :disabled="analyseEnCours"
                            :aria-busy="analyseEnCours"
                            @click="analyser"
                        >
                            <Spinner v-if="analyseEnCours" class="mr-1.5" />
                            <RefreshCw v-else class="mr-1.5 h-3.5 w-3.5" />
                            {{
                                analyseEnCours
                                    ? 'Analyse…'
                                    : 'Analyser maintenant'
                            }}
                        </Button>
                    </template>
                </ListPageActions>
            </div>

            <div
                class="flex items-start gap-2 rounded-lg border px-4 py-3 text-sm"
                :class="
                    controle.planificateur_en_retard
                        ? 'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-300'
                        : 'text-muted-foreground'
                "
                data-testid="controle-etat-planificateur"
            >
                <TriangleAlert
                    v-if="controle.planificateur_en_retard"
                    class="mt-0.5 h-4 w-4 shrink-0"
                />
                <Clock v-else class="mt-0.5 h-4 w-4 shrink-0" />
                <p>
                    <template v-if="controle.planificateur_en_retard">
                        Le contrôle automatique horaire n'a pas tourné
                        récemment<template v-if="controle.dernier_planifie">
                            (dernier :
                            {{ controle.dernier_planifie }})</template
                        >. Vérifiez la tâche planifiée du serveur
                        (<code>schedule:run</code>). Les encaissements restent
                        contrôlés au moment de leur saisie.
                    </template>
                    <template v-else>
                        Contrôle automatique toutes les heures — dernier passage
                        : {{ controle.dernier_planifie }}.
                    </template>
                    <template v-if="controle.dernier_erreur">
                        Dernier contrôle en erreur :
                        {{ controle.dernier_erreur }}</template
                    >
                </p>
            </div>

            <div class="@container">
                <div
                    class="grid grid-cols-1 gap-5 @[40rem]:grid-cols-2 @[80rem]:grid-cols-5"
                    data-testid="controle-kpis"
                >
                    <div
                        v-for="carte in cartes"
                        :key="carte.id"
                        :data-testid="`controle-kpi-${carte.id}`"
                    >
                        <KpiCard
                            as="button"
                            :title="carte.titre"
                            :value="carte.valeur"
                            :detail="carte.detail"
                            :active="carte.actif"
                            @click="filtrer(carte.filtre)"
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
                        class="w-full min-w-[1100px] text-sm"
                        data-testid="controle-table"
                    >
                        <thead>
                            <tr class="border-b bg-muted/50 text-left">
                                <th
                                    class="px-4 py-3 font-semibold text-foreground/70"
                                >
                                    Gravité
                                </th>
                                <th
                                    class="px-4 py-3 font-semibold text-foreground/70"
                                >
                                    Commande
                                </th>
                                <th
                                    class="px-4 py-3 font-semibold text-foreground/70"
                                >
                                    Anomalie
                                </th>
                                <th
                                    class="px-4 py-3 font-semibold text-foreground/70"
                                >
                                    Agence / agent
                                </th>
                                <th
                                    class="px-4 py-3 text-right font-semibold text-foreground/70"
                                >
                                    Facturé
                                </th>
                                <th
                                    class="px-4 py-3 text-right font-semibold text-foreground/70"
                                >
                                    Encaissé net
                                </th>
                                <th
                                    class="px-4 py-3 text-right font-semibold text-foreground/70"
                                >
                                    Surplus
                                </th>
                                <th
                                    class="px-4 py-3 font-semibold text-foreground/70"
                                >
                                    Détectée le
                                </th>
                                <th
                                    class="px-4 py-3 font-semibold text-foreground/70"
                                >
                                    Statut
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="a in anomalies.data"
                                :key="a.id"
                                class="cursor-pointer even:bg-muted/20 hover:bg-muted/40"
                                :data-testid="`controle-ligne-${a.reference}`"
                                @click="router.visit(`${URL_CONTROLE}/${a.id}`)"
                            >
                                <td class="px-4 py-3">
                                    <GraviteAnomalie
                                        :gravite="a.gravite"
                                        :label="a.gravite_label"
                                    />
                                </td>
                                <td class="px-4 py-3">
                                    <Link
                                        :href="`${URL_CONTROLE}/${a.id}`"
                                        class="font-mono text-xs font-medium hover:underline"
                                        @click.stop
                                        >{{ a.reference }}</Link
                                    >
                                    <p
                                        v-if="a.facture_reference"
                                        class="text-xs text-muted-foreground"
                                    >
                                        Facture {{ a.facture_reference }}
                                    </p>
                                </td>
                                <td class="px-4 py-3">
                                    {{ a.type_label }}
                                    <p
                                        v-if="!a.ecart_present && a.ouverte"
                                        class="text-xs text-emerald-600 dark:text-emerald-400"
                                    >
                                        Écart disparu — à clôturer
                                    </p>
                                    <p
                                        v-else-if="a.nb_relances > 0"
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ a.nb_relances }} relance(s)
                                    </p>
                                </td>
                                <td class="px-4 py-3">
                                    {{ a.site_nom ?? '—' }}
                                    <p class="text-xs text-muted-foreground">
                                        {{ a.agents.join(', ') || '—' }}
                                    </p>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ montant(a.montant_facture) }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ montant(a.montant_encaisse_net) }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right font-medium tabular-nums"
                                >
                                    {{
                                        a.surplus > 0
                                            ? formatGNF(a.surplus)
                                            : '—'
                                    }}
                                </td>
                                <td
                                    class="px-4 py-3 whitespace-nowrap text-muted-foreground"
                                >
                                    {{ a.detectee_le }}
                                </td>
                                <td class="px-4 py-3">
                                    <StatusDot
                                        :status="a.statut"
                                        :label="a.statut_label"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div
                    v-else
                    data-testid="controle-vide"
                    class="flex flex-col items-center gap-3 py-16 text-muted-foreground"
                >
                    <ShieldCheck class="h-12 w-12 opacity-30" />
                    <p class="text-sm">Aucune anomalie pour ces filtres.</p>
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
    </AppLayout>
</template>
