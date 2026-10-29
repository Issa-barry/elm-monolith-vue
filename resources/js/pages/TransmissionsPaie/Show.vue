<script setup lang="ts">
import AuditDrawer from '@/components/AuditDrawer.vue';
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useFlashToast } from '@/composables/useFlashToast';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    BadgeCheck,
    Ban,
    CheckCircle2,
    History,
    Loader2,
    Lock,
    RefreshCw,
    Send,
    ShieldAlert,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import { useToast } from 'primevue/usetoast';
import { computed, ref } from 'vue';

interface Resume {
    id: string;
    annee: number;
    mois: number;
    libelle_mois: string;
    version: number;
    statut: string;
    statut_label: string;
    motif: string | null;
    nb_salaries: number;
    nb_bloquantes: number;
    nb_avertissements: number;
    preparee_par: string | null;
    preparee_le: string | null;
    validee_par: string | null;
    validee_le: string | null;
    transmise_par: string | null;
    transmise_le: string | null;
    prise_en_charge_le: string | null;
    prise_en_charge_reference: string | null;
    statut_comptabilite: string | null;
}

interface Transmission extends Resume {
    version_precedente: number | null;
    est_figee: boolean;
    integre: boolean | null;
    empreinte: string | null;
    organisation_complete: boolean;
    total_verse_organisation: number | null;
    total_valorise_organisation: number | null;
}

interface Ligne {
    id: string;
    origine: 'contrat' | 'affectation';
    code: string | null;
    libelle: string;
    categorie_label: string;
    versement: 'verse' | 'valorise';
    du: string;
    au: string;
    jours: number;
    jours_mois: number;
    montant_reference: number | null;
    base_calcul: number | null;
    taux: number | null;
    montant: number;
    detail_calcul: string;
    source: string;
}

interface Salarie {
    employe_id: string;
    salarie: string;
    matricule: string | null;
    site: string | null;
    fonction: string | null;
    total_verse: number;
    total_valorise: number;
    lignes: Ligne[];
}

interface Anomalie {
    code: string;
    niveau: 'bloquante' | 'avertissement';
    salarie: string | null;
    message: string;
}

interface Ecart {
    salarie: string;
    element: string;
    avant: number | null;
    apres: number | null;
    ecart: number;
}

const props = defineProps<{
    transmission: Transmission;
    salaries: Salarie[];
    recapitulatif: {
        cle: string;
        element: string;
        code: string | null;
        categorie_label: string;
        versement: string;
        nb_salaries: number;
        total: number;
    }[];
    totaux_visibles: { verse: number; valorise: number; nb_salaries: number };
    anomalies: Anomalie[];
    versions: Resume[];
    ecarts_precedente: Ecart[];
    changements_depuis: Ecart[];
    filters: Record<string, unknown>;
    sites: { id: string; nom: string }[];
    peut_agir_toutes_agences: boolean;
}>();

useFlashToast();
const toast = useToast();
const { can } = usePermissions();

const t = computed(() => props.transmission);
const url = computed(() => `/backoffice/transmissions-paie/${t.value.id}`);

const breadcrumbs = computed((): BreadcrumbItem[] => [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Transmission de paie', href: '/backoffice/transmissions-paie' },
    {
        title: `${t.value.libelle_mois} · v${t.value.version}`,
        href: url.value,
    },
]);

const formatGnf = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 });
function gnf(valeur: number | null): string {
    return valeur === null ? '—' : `${formatGnf.format(valeur)} GNF`;
}

const bloquantes = computed(() =>
    props.anomalies.filter((a) => a.niveau === 'bloquante'),
);
const avertissements = computed(() =>
    props.anomalies.filter((a) => a.niveau === 'avertissement'),
);

const filterFields = computed((): FilterField[] => [
    {
        key: 'salarie',
        label: 'Salarié (nom ou matricule)',
        type: 'text',
        inline: true,
    },
]);

// ── Droits (le serveur revérifie chaque action) ──────────────────────────────

const toutesAgences = computed(() => props.peut_agir_toutes_agences);
const estBrouillon = computed(() => t.value.statut === 'brouillon');
const estDerniereFigee = computed(
    () =>
        t.value.est_figee &&
        props.versions.find((v) =>
            ['validee', 'transmise', 'remplacee'].includes(v.statut),
        )?.id === t.value.id,
);
const brouillonExistant = computed(() =>
    props.versions.some((v) => v.statut === 'brouillon'),
);

const peutPreparer = computed(
    () => can('rh-transmissions-paie.preparer') && toutesAgences.value,
);
const peutValider = computed(
    () =>
        estBrouillon.value &&
        can('rh-transmissions-paie.valider') &&
        toutesAgences.value,
);
const peutTransmettre = computed(
    () =>
        t.value.statut === 'validee' &&
        can('rh-transmissions-paie.transmettre') &&
        toutesAgences.value,
);
const peutNouvelleVersion = computed(
    () =>
        estDerniereFigee.value &&
        t.value.statut !== 'remplacee' &&
        !brouillonExistant.value &&
        peutPreparer.value,
);

// ── Actions ──────────────────────────────────────────────────────────────────

type Action = 'recalculer' | 'abandonner' | 'valider' | 'transmettre';

const LIBELLES: Record<Action, string> = {
    recalculer: 'Recalculer',
    abandonner: 'Abandonner le brouillon',
    valider: 'Valider et figer',
    transmettre: 'Transmettre à la Comptabilité',
};

const EXPLICATIONS: Record<Action, string> = {
    recalculer:
        'Les montants sont recalculés à partir des contrats et des affectations validées d’aujourd’hui.',
    abandonner:
        'Le brouillon est abandonné. Son numéro de version reste dans l’historique.',
    valider:
        'La version est figée : ses montants ne changeront plus. Toute correction ultérieure passera par une nouvelle version.',
    transmettre:
        'La Comptabilité lira cette version pour la paie du mois. Si une version avait déjà été transmise, elle est remplacée.',
};

const confirmation = ref<Action | null>(null);
const enCours = ref(false);

function fermer(visible: boolean) {
    if (!visible && !enCours.value) confirmation.value = null;
}

function confirmer() {
    const action = confirmation.value;
    if (!action || enCours.value) return;
    enCours.value = true;
    router.post(
        `${url.value}/${action}`,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                confirmation.value = null;
            },
            onError: (errors: Record<string, string>) => {
                confirmation.value = null;
                toast.add({
                    group: 'top',
                    severity: 'error',
                    summary: 'Action refusée',
                    detail: Object.values(errors)[0],
                    life: 7000,
                });
            },
            onFinish: () => {
                enCours.value = false;
            },
        },
    );
}

// Nouvelle version (correction) : motif obligatoire.
const showNouvelle = ref(false);
const formNouvelle = useForm({ motif: '' });

const erreurNouvelle = computed(() => {
    const erreurs = formNouvelle.errors as Record<string, string>;
    return erreurs.motif ?? erreurs.mois ?? '';
});

function nouvelleVersion() {
    formNouvelle
        .transform((data) => ({
            annee: t.value.annee,
            mois: t.value.mois,
            motif: data.motif.trim(),
        }))
        .post('/backoffice/transmissions-paie', {
            onSuccess: () => {
                showNouvelle.value = false;
            },
        });
}

const historiqueVisible = ref(false);

function periodeLigne(l: Ligne): string {
    return l.jours < l.jours_mois
        ? `${l.du} → ${l.au} (${l.jours}/${l.jours_mois} j)`
        : 'Mois entier';
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`Transmission ${t.libelle_mois} v${t.version}`" />

        <div class="w-full space-y-5 p-4 sm:p-6">
            <!-- En-tête -->
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p
                        class="text-xs font-medium tracking-wider text-muted-foreground uppercase"
                    >
                        Transmission de paie
                    </p>
                    <h1 class="mt-0.5 text-xl font-semibold">
                        {{ t.libelle_mois }} — version {{ t.version }}
                    </h1>
                    <div class="mt-1 flex flex-wrap items-center gap-3 text-sm">
                        <StatusDot :status="t.statut" :label="t.statut_label" />
                        <span
                            v-if="t.statut_comptabilite"
                            class="text-xs text-muted-foreground"
                        >
                            {{ t.statut_comptabilite }}
                        </span>
                    </div>
                    <p
                        v-if="t.motif"
                        class="mt-1 text-sm text-muted-foreground"
                    >
                        Motif : {{ t.motif }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        @click="historiqueVisible = true"
                    >
                        <History class="mr-1.5 h-3.5 w-3.5" />
                        Historique
                    </Button>
                    <template v-if="estBrouillon && peutPreparer">
                        <Button
                            variant="outline"
                            size="sm"
                            @click="confirmation = 'recalculer'"
                        >
                            <RefreshCw class="mr-1.5 h-3.5 w-3.5" />
                            Recalculer
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            @click="confirmation = 'abandonner'"
                        >
                            <Ban class="mr-1.5 h-3.5 w-3.5" />
                            Abandonner
                        </Button>
                    </template>
                    <Button
                        v-if="peutValider"
                        size="sm"
                        :disabled="bloquantes.length > 0"
                        data-testid="transmission-valider"
                        @click="confirmation = 'valider'"
                    >
                        <BadgeCheck class="mr-1.5 h-3.5 w-3.5" />
                        Valider et figer
                    </Button>
                    <Button
                        v-if="peutTransmettre"
                        size="sm"
                        data-testid="transmission-transmettre"
                        @click="confirmation = 'transmettre'"
                    >
                        <Send class="mr-1.5 h-3.5 w-3.5" />
                        Transmettre à la Comptabilité
                    </Button>
                    <Button
                        v-if="peutNouvelleVersion"
                        variant="outline"
                        size="sm"
                        @click="showNouvelle = true"
                    >
                        Nouvelle version (correction)
                    </Button>
                </div>
            </div>

            <!-- État de la version : calculée, figée, transmise -->
            <div
                v-if="estBrouillon"
                class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-100"
            >
                <strong>Brouillon — montants calculés, non figés.</strong>
                Calculés le {{ t.preparee_le
                }}<template v-if="t.preparee_par">
                    par {{ t.preparee_par }}</template
                >
                à partir des contrats et des affectations validées. Ils peuvent
                encore changer : recalculez après toute correction RH, puis
                validez pour figer la version.
                <span
                    v-if="peutValider && bloquantes.length > 0"
                    class="mt-1 block font-medium"
                >
                    Validation impossible tant que les anomalies bloquantes ne
                    sont pas corrigées.
                </span>
            </div>
            <div
                v-else-if="t.est_figee"
                class="flex flex-wrap items-start gap-3 rounded-lg border px-4 py-3 text-sm"
                :class="
                    t.integre === false
                        ? 'border-red-200 bg-red-50 text-red-900 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100'
                        : 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100'
                "
            >
                <Lock class="mt-0.5 h-4 w-4 shrink-0" />
                <div>
                    <p>
                        <strong>Version figée</strong> le {{ t.validee_le }} par
                        {{ t.validee_par }} : les montants ci-dessous sont
                        définitifs pour cette version.
                        <template v-if="t.transmise_le">
                            Transmise le {{ t.transmise_le }} par
                            {{ t.transmise_par }}.
                        </template>
                        <template v-if="t.prise_en_charge_le">
                            Reprise par la Comptabilité le
                            {{ t.prise_en_charge_le }}.
                        </template>
                    </p>
                    <p class="mt-1 flex items-center gap-1.5 text-xs">
                        <CheckCircle2 v-if="t.integre" class="h-3.5 w-3.5" />
                        <ShieldAlert v-else class="h-3.5 w-3.5" />
                        {{
                            t.integre
                                ? 'Contenu intact (empreinte vérifiée).'
                                : 'Le contenu ne correspond plus à l’empreinte enregistrée : ne pas transmettre, prévenir un administrateur.'
                        }}
                        <span class="font-mono opacity-70"
                            >{{ t.empreinte?.slice(0, 16) }}…</span
                        >
                    </p>
                </div>
            </div>
            <div
                v-else
                class="rounded-lg bg-muted/50 px-4 py-3 text-sm text-muted-foreground"
            >
                Brouillon abandonné : il n’a jamais été validé ni transmis.
            </div>

            <!-- Changements intervenus depuis la version figée -->
            <div
                v-if="changements_depuis.length > 0"
                class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100"
            >
                <p class="flex items-center gap-2 font-medium">
                    <AlertTriangle class="h-4 w-4" />
                    {{ changements_depuis.length }} changement(s) dans les
                    données RH depuis cette version
                </p>
                <p class="mt-1 text-xs">
                    Cette version reste inchangée. Pour les transmettre, ouvrez
                    une nouvelle version.
                </p>
                <table class="mt-2 w-full text-xs">
                    <tbody>
                        <tr
                            v-for="(c, i) in changements_depuis"
                            :key="i"
                            class="border-t border-amber-200/60 dark:border-amber-900/60"
                        >
                            <td class="py-1 pr-2">{{ c.salarie }}</td>
                            <td class="py-1 pr-2">{{ c.element }}</td>
                            <td class="py-1 pr-2 text-right tabular-nums">
                                {{ gnf(c.avant) }} → {{ gnf(c.apres) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Totaux -->
            <div class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">Salariés</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums">
                        {{ totaux_visibles.nb_salaries }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">
                        Total versé (salaires, primes, indemnités, allocations)
                    </p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums">
                        {{ gnf(totaux_visibles.verse) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">
                        Avantages en nature valorisés (hors net à payer)
                    </p>
                    <p
                        class="mt-1 text-2xl font-semibold text-muted-foreground tabular-nums"
                    >
                        {{ gnf(totaux_visibles.valorise) }}
                    </p>
                </div>
            </div>
            <p
                v-if="!t.organisation_complete"
                class="text-xs text-muted-foreground"
            >
                Montants limités aux salariés de vos agences ; la version couvre
                toute l’organisation.
            </p>

            <!-- Anomalies -->
            <div v-if="anomalies.length > 0" class="space-y-2">
                <div
                    v-if="bloquantes.length > 0"
                    class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100"
                >
                    <p class="font-medium">
                        {{ bloquantes.length }} anomalie(s) bloquante(s) — à
                        corriger dans RH, puis recalculer
                    </p>
                    <ul class="mt-1 list-disc space-y-0.5 pl-5 text-xs">
                        <li v-for="(a, i) in bloquantes" :key="i">
                            <strong>{{ a.salarie }}</strong> : {{ a.message }}
                        </li>
                    </ul>
                </div>
                <div
                    v-if="avertissements.length > 0"
                    class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100"
                >
                    <p class="font-medium">
                        {{ avertissements.length }} avertissement(s) —
                        n’empêchent pas la validation
                    </p>
                    <ul class="mt-1 list-disc space-y-0.5 pl-5 text-xs">
                        <li v-for="(a, i) in avertissements" :key="i">
                            <strong>{{ a.salarie }}</strong> : {{ a.message }}
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Écarts avec la version précédente -->
            <div v-if="t.version_precedente" class="rounded-xl border bg-card">
                <div class="border-b px-4 py-3">
                    <h2 class="text-sm font-semibold">
                        Écarts avec la version {{ t.version_precedente }}
                    </h2>
                </div>
                <p
                    v-if="ecarts_precedente.length === 0"
                    class="p-4 text-sm text-muted-foreground"
                >
                    Aucun écart de montant.
                </p>
                <table v-else class="w-full text-sm">
                    <thead class="text-xs text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 text-left font-medium">
                                Salarié
                            </th>
                            <th class="px-4 py-2 text-left font-medium">
                                Élément
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                v{{ t.version_precedente }}
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                v{{ t.version }}
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Écart
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="(e, i) in ecarts_precedente" :key="i">
                            <td class="px-4 py-2">{{ e.salarie }}</td>
                            <td class="px-4 py-2">{{ e.element }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ gnf(e.avant) }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ gnf(e.apres) }}
                            </td>
                            <td
                                class="px-4 py-2 text-right font-medium tabular-nums"
                                :class="
                                    e.ecart < 0
                                        ? 'text-red-600 dark:text-red-400'
                                        : 'text-emerald-700 dark:text-emerald-400'
                                "
                            >
                                {{ e.ecart > 0 ? '+' : '' }}{{ gnf(e.ecart) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Récapitulatif par élément -->
            <div class="rounded-xl border bg-card">
                <div class="border-b px-4 py-3">
                    <h2 class="text-sm font-semibold">
                        Récapitulatif par élément
                    </h2>
                </div>
                <table class="w-full text-sm">
                    <thead class="text-xs text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 text-left font-medium">
                                Élément
                            </th>
                            <th class="px-4 py-2 text-left font-medium">
                                Catégorie
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Salariés
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Total du mois
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="r in recapitulatif" :key="r.cle">
                            <td class="px-4 py-2">
                                {{ r.element }}
                                <span
                                    v-if="r.code"
                                    class="ml-1 rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground"
                                    >{{ r.code }}</span
                                >
                            </td>
                            <td class="px-4 py-2 text-muted-foreground">
                                {{ r.categorie_label }}
                                <template v-if="r.versement === 'valorise'">
                                    · valorisé</template
                                >
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ r.nb_salaries }}
                            </td>
                            <td
                                class="px-4 py-2 text-right font-medium tabular-nums"
                            >
                                {{ gnf(r.total) }}
                            </td>
                        </tr>
                        <tr v-if="recapitulatif.length === 0">
                            <td
                                colspan="4"
                                class="px-4 py-6 text-center text-muted-foreground"
                            >
                                Aucun élément.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Détail par salarié -->
            <div class="space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-sm font-semibold">Détail par salarié</h2>
                </div>
                <DataFilters
                    :url="url"
                    :values="filters"
                    :fields="filterFields"
                    :sites="sites"
                    :result-count="salaries.length"
                />
                <p class="text-xs text-muted-foreground">
                    <strong>Montant validé (RH)</strong> : salaire du contrat,
                    montant attribué ou montant saisi du mois, tel que validé
                    côté RH. <strong>Montant du mois</strong> : montant calculé
                    pour la partie du mois (prorata, pourcentage).
                    {{
                        t.est_figee
                            ? 'Ces montants sont figés dans cette version.'
                            : 'Ils seront figés à la validation.'
                    }}
                </p>

                <div
                    v-for="s in salaries"
                    :key="s.employe_id"
                    class="overflow-hidden rounded-xl border bg-card"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3"
                    >
                        <div>
                            <p class="font-medium">{{ s.salarie }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ s.matricule ?? '—'
                                }}<template v-if="s.site">
                                    · {{ s.site }}</template
                                ><template v-if="s.fonction">
                                    · {{ s.fonction }}</template
                                >
                            </p>
                        </div>
                        <div class="text-right text-sm">
                            <p class="font-semibold tabular-nums">
                                {{ gnf(s.total_verse) }}
                            </p>
                            <p
                                v-if="s.total_valorise > 0"
                                class="text-xs text-muted-foreground tabular-nums"
                            >
                                + {{ gnf(s.total_valorise) }} valorisés
                            </p>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[760px] text-sm">
                            <thead class="text-xs text-muted-foreground">
                                <tr>
                                    <th class="px-4 py-2 text-left font-medium">
                                        Élément
                                    </th>
                                    <th class="px-4 py-2 text-left font-medium">
                                        Origine
                                    </th>
                                    <th class="px-4 py-2 text-left font-medium">
                                        Période
                                    </th>
                                    <th
                                        class="px-4 py-2 text-right font-medium"
                                    >
                                        Montant validé (RH)
                                    </th>
                                    <th class="px-4 py-2 text-left font-medium">
                                        Calcul
                                    </th>
                                    <th
                                        class="px-4 py-2 text-right font-medium"
                                    >
                                        Montant du mois
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-for="l in s.lignes" :key="l.id">
                                    <td class="px-4 py-2">
                                        {{ l.libelle }}
                                        <p
                                            class="text-[11px] text-muted-foreground"
                                        >
                                            {{ l.categorie_label
                                            }}<template
                                                v-if="
                                                    l.versement === 'valorise'
                                                "
                                            >
                                                · valorisé, hors net</template
                                            >
                                        </p>
                                    </td>
                                    <td class="px-4 py-2 text-muted-foreground">
                                        {{ l.source }}
                                    </td>
                                    <td
                                        class="px-4 py-2 whitespace-nowrap text-muted-foreground"
                                    >
                                        {{ periodeLigne(l) }}
                                    </td>
                                    <td
                                        class="px-4 py-2 text-right tabular-nums"
                                    >
                                        <template
                                            v-if="l.montant_reference !== null"
                                            >{{
                                                gnf(l.montant_reference)
                                            }}</template
                                        >
                                        <template v-else-if="l.taux !== null"
                                            >{{
                                                l.taux.toLocaleString('fr-FR')
                                            }}
                                            %</template
                                        >
                                    </td>
                                    <td
                                        class="px-4 py-2 text-xs text-muted-foreground"
                                    >
                                        {{ l.detail_calcul }}
                                    </td>
                                    <td
                                        class="px-4 py-2 text-right font-medium tabular-nums"
                                        :class="{
                                            'text-muted-foreground':
                                                l.versement === 'valorise',
                                        }"
                                    >
                                        {{ gnf(l.montant) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <p
                    v-if="salaries.length === 0"
                    class="rounded-xl border bg-card p-6 text-center text-sm text-muted-foreground"
                >
                    Aucun salarié ne correspond.
                </p>
            </div>

            <!-- Versions du mois -->
            <div class="rounded-xl border bg-card">
                <div class="border-b px-4 py-3">
                    <h2 class="text-sm font-semibold">
                        Versions de {{ t.libelle_mois.toLowerCase() }}
                    </h2>
                </div>
                <div class="divide-y">
                    <Link
                        v-for="v in versions"
                        :key="v.id"
                        :href="`/backoffice/transmissions-paie/${v.id}`"
                        class="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5 text-sm hover:bg-muted/40"
                        :class="{ 'bg-muted/30': v.id === t.id }"
                    >
                        <span class="font-medium">Version {{ v.version }}</span>
                        <StatusDot :status="v.statut" :label="v.statut_label" />
                        <span class="text-xs text-muted-foreground">
                            {{ v.motif ?? `Préparée ${v.preparee_le ?? ''}` }}
                        </span>
                    </Link>
                </div>
            </div>
        </div>
    </AppLayout>

    <!-- Confirmation -->
    <Dialog
        :visible="confirmation !== null"
        modal
        :header="confirmation ? LIBELLES[confirmation] : ''"
        :style="{ width: 'min(30rem, 95vw)' }"
        :draggable="false"
        :closable="!enCours"
        @update:visible="fermer"
    >
        <p v-if="confirmation" class="text-sm text-muted-foreground">
            {{ EXPLICATIONS[confirmation] }}
        </p>
        <template #footer>
            <div class="flex justify-end gap-2">
                <Button
                    variant="outline"
                    :disabled="enCours"
                    @click="fermer(false)"
                    >Fermer</Button
                >
                <Button
                    :variant="
                        confirmation === 'abandonner'
                            ? 'destructive'
                            : 'default'
                    "
                    :disabled="enCours"
                    data-testid="transmission-confirmer"
                    @click="confirmer"
                >
                    <Loader2
                        v-if="enCours"
                        class="mr-1.5 h-3.5 w-3.5 animate-spin"
                    />
                    {{ confirmation ? LIBELLES[confirmation] : '' }}
                </Button>
            </div>
        </template>
    </Dialog>

    <!-- Nouvelle version -->
    <Dialog
        v-model:visible="showNouvelle"
        modal
        header="Nouvelle version (correction)"
        :style="{ width: 'min(30rem, 95vw)' }"
        :draggable="false"
        :closable="!formNouvelle.processing"
    >
        <form class="space-y-3" @submit.prevent="nouvelleVersion">
            <p class="text-sm text-muted-foreground">
                La version {{ t.version }} reste consultable telle quelle. La
                nouvelle version est un brouillon recalculé à partir des données
                RH actuelles ; elle ne remplacera la version transmise qu’une
                fois validée puis transmise.
            </p>
            <div>
                <Label for="nv-motif" class="mb-1.5 block text-xs font-medium">
                    Motif <span class="text-destructive">*</span>
                </Label>
                <textarea
                    id="nv-motif"
                    v-model="formNouvelle.motif"
                    rows="2"
                    class="flex min-h-[60px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                />
                <p v-if="erreurNouvelle" class="mt-1 text-xs text-destructive">
                    {{ erreurNouvelle }}
                </p>
            </div>
            <div class="flex justify-end gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="formNouvelle.processing"
                    @click="showNouvelle = false"
                    >Annuler</Button
                >
                <Button
                    type="submit"
                    size="sm"
                    :disabled="
                        formNouvelle.processing || !formNouvelle.motif.trim()
                    "
                >
                    <Loader2
                        v-if="formNouvelle.processing"
                        class="mr-1.5 h-3.5 w-3.5 animate-spin"
                    />
                    Ouvrir la version
                    {{ (versions[0]?.version ?? t.version) + 1 }}
                </Button>
            </div>
        </form>
    </Dialog>

    <AuditDrawer
        v-model:visible="historiqueVisible"
        :title="`Historique — ${t.libelle_mois} v${t.version}`"
        auditable-type="transmission_paie"
        :auditable-id="t.id"
        :endpoint="`${url}/historique`"
    />
</template>
