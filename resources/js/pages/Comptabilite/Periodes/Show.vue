<script setup lang="ts">
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { useClickableTableRow } from '@/composables/useClickableTableRow';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Calculator,
    CheckCircle,
    Download,
    ExternalLink,
    FileText,
    Loader2,
    Lock,
    Trash2,
    Truck,
    Wrench,
} from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed, onMounted, ref } from 'vue';

interface Periode {
    id: string;
    reference: string;
    type: string;
    type_label: string;
    site: { id: string; nom: string } | null;
    date_debut: string | null;
    date_fin: string | null;
    statut: string;
    statut_label: string;
    observations: string | null;
    nb_fiches: number;
    total_net: number;
    total_paye: number;
}

interface VehiculeCard {
    vehicule_id: string | null;
    vehicule_nom: string;
    vehicule_immat: string | null;
    nb_membres: number;
    taille_equipe: number | null;
    nb_commandes: number;
    theorique: number;
    ajuste: number;
    ecart: number;
    equilibre: boolean;
    deja_paye: number;
    reste: number;
    statut_validation: 'a_verifier' | 'validee' | 'a_reverifier' | 'payee';
}

interface BeneficiaireFiche {
    fiche_id: string;
    beneficiaire_nom: string;
    montant_brut: number;
    montant_net: number;
    montant_paye: number;
    reste: number;
    statut: string;
    statut_label: string;
}

const props = defineProps<{
    periode: Periode;
    vehicules: VehiculeCard[];
    beneficiaires: BeneficiaireFiche[];
    filters: Record<string, string>;
    recalcul: {
        effectue: boolean;
        nb_fiches: number;
    };
    validation: {
        possible: boolean;
        raison: string | null;
        commissions_hors_fiches: { nombre: number; montant: number };
    };
    stats: {
        total_brut: number;
        total_net: number;
        total_paye: number;
        reste: number;
    };
    can: {
        calculer: boolean;
        valider: boolean;
        cloturer: boolean;
        delete: boolean;
        ajuster: boolean;
    };
}>();

// Périodes livreur/propriétaire : ancrées véhicule (la commission s'ancre sur un véhicule).
// Autres types (salarié, site, consultant) : aucun concept de véhicule, chaque fiche EST déjà
// la ligne bénéficiaire — filtres et statuts adaptés en conséquence (cf. showBeneficiaires).
const isVehiculeType = computed(() =>
    ['livreur', 'proprietaire'].includes(props.periode.type),
);

const filterFields = computed<FilterField[]>(() =>
    isVehiculeType.value
        ? [
              {
                  key: 'vehicule',
                  label: 'Véhicule',
                  type: 'text',
                  placeholder: 'Nom ou immatriculation…',
                  inline: true,
              },
              {
                  key: 'livreur',
                  label: 'Livreur',
                  type: 'text',
                  placeholder: 'Nom du livreur…',
                  inline: true,
              },
              {
                  key: 'proprietaire',
                  label: 'Propriétaire',
                  type: 'text',
                  placeholder: 'Nom du propriétaire…',
              },
              {
                  key: 'etat',
                  label: 'État',
                  type: 'select',
                  inline: true,
                  options: [
                      { value: 'a_verifier', label: 'À vérifier' },
                      { value: 'validee', label: 'Validé' },
                      { value: 'a_reverifier', label: 'À revérifier' },
                      { value: 'payee', label: 'Payé' },
                  ],
              },
          ]
        : [
              {
                  key: 'beneficiaire',
                  label: 'Bénéficiaire',
                  type: 'text',
                  placeholder: 'Nom…',
                  inline: true,
              },
              {
                  key: 'etat',
                  label: 'État',
                  type: 'select',
                  inline: true,
                  options: [
                      { value: 'a_payer', label: 'À payer' },
                      {
                          value: 'partiellement_paye',
                          label: 'Partiellement payé',
                      },
                      { value: 'paye', label: 'Payé' },
                  ],
              },
          ],
);

// Colonnes du tableau véhicules : `w-px` + `whitespace-nowrap` réduit chaque colonne à la
// largeur de son contenu (montants toujours sur une ligne), le Véhicule (`w-full max-w-0`)
// absorbe le reste et tronque son nom. Seuils en requête de conteneur (largeur réelle du
// tableau, sidebar comprise) : < 1150px l'immatriculation passe sous le nom, < 1000px les
// en-têtes Commandes/Membres s'abrègent et la taille d'équipe passe sous le nom.
// Le `!` est nécessaire : le padding des cellules
// PrimeVue n'est pas dans un layer Tailwind.
const CELLULE = 'w-px whitespace-nowrap !px-3';
const COL_COMPACTE = {
    headerCell: { class: CELLULE },
    bodyCell: { class: CELLULE },
};
const COL_SELECTION = {
    headerCell: { class: 'w-px !pr-1 !pl-4' },
    bodyCell: { class: 'w-px !pr-1 !pl-4' },
};
const COL_VEHICULE = {
    headerCell: { class: 'w-full max-w-0 min-w-[7rem] !px-3' },
    bodyCell: { class: 'w-full max-w-0 min-w-[7rem] !px-3' },
};
const COL_IMMAT = {
    headerCell: { class: `${CELLULE} hidden @min-[1150px]:table-cell` },
    bodyCell: { class: `${CELLULE} hidden @min-[1150px]:table-cell` },
};
const COL_EQUIPE = {
    headerCell: { class: `${CELLULE} hidden @min-[1000px]:table-cell` },
    columnHeaderContent: { class: 'justify-center' },
    bodyCell: {
        class: `${CELLULE} !text-center hidden @min-[1000px]:table-cell`,
    },
};
const COL_NOMBRE = {
    headerCell: { class: CELLULE },
    columnHeaderContent: { class: 'justify-center' },
    bodyCell: { class: `${CELLULE} !text-center` },
};
const COL_MONTANT = {
    headerCell: { class: CELLULE },
    columnHeaderContent: { class: 'justify-end' },
    bodyCell: { class: `${CELLULE} !text-right` },
};
// Figée à droite : Valider/Ajuster restent accessibles quand le tableau défile (secours
// < 960px), avec une ombre signalant les colonnes masquées dessous.
const OMBRE_ACTIONS = '@max-[960px]:shadow-[-6px_0_6px_-4px_rgb(0_0_0/0.12)]';
const COL_ACTIONS = {
    headerCell: { class: `${CELLULE} sticky right-0 z-[1] ${OMBRE_ACTIONS}` },
    bodyCell: {
        class: `${CELLULE} sticky right-0 z-[1] bg-inherit ${OMBRE_ACTIONS}`,
    },
};

const STATUT_VALIDATION_LABELS: Record<string, string> = {
    a_verifier: 'À vérifier',
    validee: 'Validé',
    a_reverifier: 'À revérifier',
    payee: 'Payé',
};

function statutValidationLabel(statut: string): string {
    return STATUT_VALIDATION_LABELS[statut] ?? statut;
}

const controleVehicules = computed(() => {
    const total = props.vehicules.length;
    const valides = props.vehicules.filter(
        (v) =>
            v.statut_validation === 'validee' ||
            v.statut_validation === 'payee',
    ).length;
    const aRevoir = props.vehicules.filter(
        (v) => v.statut_validation === 'a_reverifier',
    ).length;

    return { total, valides, aRevoir };
});

const { onRowClick, bodyRowPt } = useClickableTableRow<VehiculeCard>((v) =>
    props.can.ajuster ? ajustementUrl(v.vehicule_id) : null,
);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité' },
    { title: 'Périodes', href: '/backoffice/comptabilite/periodes' },
    {
        title: props.periode.reference,
        href: `/backoffice/comptabilite/periodes/${props.periode.id}`,
    },
];

const confirm = useConfirm();
const toast = useToast();
const page = usePage();

const calculerWarning = ref<string | null>(null);

const voirCommissionsUrl = computed(() => {
    const d = props.periode.date_debut ?? '';
    const f = props.periode.date_fin ?? '';
    switch (props.periode.type) {
        case 'livreur':
            return `/backoffice/comptabilite/commission-logistique?date_debut=${d}&date_fin=${f}`;
        case 'proprietaire':
            return `/backoffice/comptabilite/commission-proprietaire?date_debut=${d}&date_fin=${f}`;
        case 'salarie':
            return `/backoffice/comptabilite/salaires`;
        case 'site':
            return `/backoffice/comptabilite/commissions/sites`;
        case 'consultant':
            return `/backoffice/comptabilite/commissions/consultants`;
        default:
            return '/backoffice/comptabilite/periodes';
    }
});

function fmt(n: number) {
    return new Intl.NumberFormat('fr-FR').format(Math.round(n)) + ' GNF';
}

const titreMetier = computed(
    () => `Paiement des ${props.periode.type_label.toLowerCase()}`,
);

const periodeFormatee = computed(() => {
    const { date_debut, date_fin } = props.periode;
    if (!date_debut || !date_fin) return null;

    const debut = new Date(date_debut);
    const fin = new Date(date_fin);
    const jourDebut = debut.getDate() === 1 ? '1er' : debut.getDate();
    const moisAnnee = fin.toLocaleDateString('fr-GN', {
        month: 'long',
        year: 'numeric',
    });

    return `${jourDebut} au ${fin.getDate()} ${moisAnnee}`;
});

function routeSegment(vehiculeId: string | null) {
    return vehiculeId ?? 'sans-vehicule';
}

function ajustementUrl(vehiculeId: string | null) {
    return `/backoffice/comptabilite/periodes/${props.periode.id}/ajustements/vehicules/${routeSegment(vehiculeId)}`;
}

// Validation directe d'un véhicule depuis la liste, sans passer par « Ajuster » : même règle
// backend que « Valider le véhicule » de l'écran d'ajustement (enveloppe équilibrée). Ne
// valide jamais la période elle-même (bouton « Valider la période de paiement »).
const peutValiderVehicules = computed(
    () => props.can.ajuster && props.periode.statut === 'calculee',
);

function estAValider(v: VehiculeCard): boolean {
    return (
        v.statut_validation === 'a_verifier' ||
        v.statut_validation === 'a_reverifier'
    );
}

function estValidable(v: VehiculeCard): boolean {
    return peutValiderVehicules.value && v.equilibre && estAValider(v);
}

const selected = ref<Set<string>>(new Set());
const validationEnCours = ref(false);

const selectableRows = computed(() => props.vehicules.filter(estValidable));

const lignesSelectionnees = computed(() =>
    selectableRows.value.filter((v) =>
        selected.value.has(routeSegment(v.vehicule_id)),
    ),
);

const allSelected = computed(
    () =>
        selectableRows.value.length > 0 &&
        lignesSelectionnees.value.length === selectableRows.value.length,
);

function toggleRow(v: VehiculeCard) {
    const next = new Set(selected.value);
    const cle = routeSegment(v.vehicule_id);
    if (next.has(cle)) {
        next.delete(cle);
    } else {
        next.add(cle);
    }
    selected.value = next;
}

function toggleAll() {
    selected.value = allSelected.value
        ? new Set()
        : new Set(selectableRows.value.map((v) => routeSegment(v.vehicule_id)));
}

function validerVehicules(segments: string[]) {
    if (segments.length === 0 || validationEnCours.value) return;
    validationEnCours.value = true;
    router.post(
        `/backoffice/comptabilite/periodes/${props.periode.id}/ajustements/valider-vehicules`,
        { vehicules: segments },
        {
            preserveScroll: true,
            onSuccess: () => {
                selected.value = new Set();
                const flash = (page.props as any).flash;
                toast.add({
                    severity: flash?.error ? 'warn' : 'success',
                    summary: flash?.error
                        ? 'Validation incomplète'
                        : 'Véhicules validés',
                    detail: flash?.error ?? flash?.success ?? '',
                    life: flash?.error ? 8000 : 4000,
                });
            },
            onFinish: () => {
                validationEnCours.value = false;
            },
        },
    );
}

function validerSelection() {
    validerVehicules(
        lignesSelectionnees.value.map((v) => routeSegment(v.vehicule_id)),
    );
}

function doCalculer() {
    calculerWarning.value = null;
    router.post(
        `/backoffice/comptabilite/periodes/${props.periode.id}/calculer`,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                const flash = (page.props as any).flash;
                if (flash?.warning) {
                    calculerWarning.value = flash.warning;
                    toast.add({
                        severity: 'warn',
                        summary: 'Aucune donnée trouvée',
                        detail: flash.warning,
                        life: 8000,
                    });
                } else {
                    calculerWarning.value = null;
                    toast.add({
                        severity: 'success',
                        summary: 'Fiches générées',
                        detail: flash?.success ?? '',
                        life: 4000,
                    });
                }
            },
        },
    );
}

// Les fiches sont générées/mises à jour automatiquement côté serveur à l'ouverture de la page
// (cf. PeriodeCalculatorService::calculerSiNecessaire) : pas de clic requis. On informe juste
// l'utilisateur quand ce recalcul silencieux a effectivement eu lieu.
onMounted(() => {
    if (props.recalcul.effectue && props.recalcul.nb_fiches > 0) {
        toast.add({
            severity: 'success',
            summary: 'Fiches mises à jour',
            detail: `${props.recalcul.nb_fiches} fiche(s) recalculée(s) automatiquement.`,
            life: 4000,
        });
    }
});

function doValider() {
    confirm.require({
        message:
            'Valider la période de paiement ? Les montants seront figés, les fiches ne pourront plus être recalculées et les commissions deviendront payables.',
        header: 'Valider la période de paiement',
        acceptLabel: 'Valider',
        rejectLabel: 'Annuler',
        accept: () =>
            router.post(
                `/backoffice/comptabilite/periodes/${props.periode.id}/valider`,
                {},
                {
                    onSuccess: () => {
                        const flash = (page.props as any).flash;
                        if (flash?.error) {
                            toast.add({
                                severity: 'warn',
                                summary: 'Validation impossible',
                                detail: flash.error,
                                life: 8000,
                            });
                        } else if (flash?.success) {
                            toast.add({
                                severity: 'success',
                                summary: 'Période validée',
                                detail: flash.success,
                                life: 4000,
                            });
                        }
                    },
                },
            ),
    });
}

function doCloturer() {
    confirm.require({
        message: 'Clôturer cette période ? Elle sera archivée définitivement.',
        header: 'Confirmer la clôture',
        acceptLabel: 'Clôturer',
        rejectLabel: 'Annuler',
        accept: () =>
            router.post(
                `/backoffice/comptabilite/periodes/${props.periode.id}/cloturer`,
            ),
    });
}

function doDelete() {
    confirm.require({
        message: 'Supprimer cette période ?',
        header: 'Confirmation',
        acceptLabel: 'Supprimer',
        rejectLabel: 'Annuler',
        acceptClass: 'p-button-danger',
        accept: () =>
            router.delete(
                `/backoffice/comptabilite/periodes/${props.periode.id}`,
                {
                    onSuccess: () =>
                        router.visit('/backoffice/comptabilite/periodes'),
                },
            ),
    });
}

function exportExcel() {
    window.open(
        `/backoffice/comptabilite/fiches/export/excel?periode_id=${props.periode.id}`,
        '_blank',
    );
}

function exportPdf() {
    window.open(
        `/backoffice/comptabilite/periodes/${props.periode.id}/pdf`,
        '_blank',
    );
}
</script>

<template>
    <Head :title="`Période ${periode.reference}`" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <!-- Header -->
            <div class="flex items-start justify-between">
                <div>
                    <div class="flex items-center gap-3">
                        <h1 class="text-xl font-semibold">
                            {{ titreMetier }}
                        </h1>
                        <StatusDot
                            :status="periode.statut"
                            :label="periode.statut_label"
                        />
                    </div>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ periodeFormatee ?? '—' }}
                        <span v-if="periode.site">
                            — {{ periode.site.nom }}</span
                        >
                    </p>
                    <p class="mt-0.5 font-mono text-xs text-muted-foreground">
                        Référence : {{ periode.reference }}
                    </p>
                    <p
                        v-if="periode.observations"
                        class="mt-1 text-xs text-muted-foreground italic"
                    >
                        {{ periode.observations }}
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <!-- span porteur du title : un bouton désactivé ne reçoit pas le survol -->
                    <span
                        v-if="can.valider"
                        :title="validation.raison ?? undefined"
                    >
                        <Button
                            size="sm"
                            :disabled="!validation.possible"
                            @click="doValider"
                        >
                            <CheckCircle class="mr-1.5 h-4 w-4" />
                            Valider la période de paiement
                        </Button>
                    </span>
                    <Button
                        v-if="can.cloturer"
                        variant="outline"
                        size="sm"
                        @click="doCloturer"
                    >
                        <Lock class="mr-1.5 h-4 w-4" />
                        Clôturer
                    </Button>
                    <Button variant="outline" size="sm" @click="exportPdf">
                        <FileText class="mr-1.5 h-4 w-4" />
                        PDF
                    </Button>
                    <Button variant="outline" size="sm" @click="exportExcel">
                        <Download class="mr-1.5 h-4 w-4" />
                        Excel
                    </Button>
                    <Button
                        v-if="can.calculer"
                        variant="ghost"
                        size="icon"
                        class="h-8 w-8 text-muted-foreground hover:text-foreground"
                        title="Recalcul manuel (technique) — la période se recalcule normalement toute seule dès qu'une commission ou un ajustement change"
                        @click="doCalculer"
                    >
                        <Calculator class="h-4 w-4" />
                    </Button>
                    <Button
                        v-if="can.delete"
                        variant="ghost"
                        size="icon"
                        class="h-8 w-8 text-destructive hover:text-destructive"
                        @click="doDelete"
                    >
                        <Trash2 class="h-4 w-4" />
                    </Button>
                </div>
            </div>

            <!-- Alerte calcul vide -->
            <div
                v-if="calculerWarning"
                class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-800/40 dark:bg-amber-950/20 dark:text-amber-300"
            >
                <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
                <div class="flex-1">
                    <p class="font-medium">Aucune donnée trouvée</p>
                    <p class="mt-0.5">{{ calculerWarning }}</p>
                </div>
                <Link
                    :href="voirCommissionsUrl"
                    class="flex shrink-0 items-center gap-1 text-xs font-medium text-amber-700 underline underline-offset-2 hover:text-amber-900 dark:text-amber-400 dark:hover:text-amber-200"
                >
                    <ExternalLink class="h-3.5 w-3.5" />
                    Voir les commissions de cette période
                </Link>
            </div>

            <div
                v-if="validation.commissions_hors_fiches.nombre > 0"
                class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-800/40 dark:bg-amber-950/20 dark:text-amber-300"
            >
                <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
                <div>
                    <p class="font-medium">
                        {{ validation.commissions_hors_fiches.nombre }}
                        commission{{
                            validation.commissions_hors_fiches.nombre > 1
                                ? 's'
                                : ''
                        }}
                        ({{ fmt(validation.commissions_hors_fiches.montant) }})
                        arrivée{{
                            validation.commissions_hors_fiches.nombre > 1
                                ? 's'
                                : ''
                        }}
                        après la validation
                    </p>
                    <p class="mt-0.5">
                        Elles sont datées dans cette période mais ne figurent
                        sur aucune fiche. La période est clôturée ou n'a pas pu
                        être rouverte automatiquement : ces commissions ne
                        seront pas payées en l'état.
                    </p>
                </div>
            </div>

            <!-- KPI stats -->
            <div class="grid gap-3 sm:grid-cols-4">
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">Total brut</p>
                    <p class="mt-1 text-lg font-bold tabular-nums">
                        {{ fmt(stats.total_brut) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">Net à payer</p>
                    <p
                        class="mt-1 text-lg font-bold text-emerald-600 tabular-nums dark:text-emerald-400"
                    >
                        {{ fmt(stats.total_net) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">Déjà payé</p>
                    <p class="mt-1 text-lg font-bold tabular-nums">
                        {{ fmt(stats.total_paye) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">Reste</p>
                    <p
                        class="mt-1 text-lg font-bold tabular-nums"
                        :class="
                            stats.reste > 0
                                ? 'text-amber-600 dark:text-amber-400'
                                : ''
                        "
                    >
                        {{ fmt(stats.reste) }}
                    </p>
                </div>
            </div>

            <!-- Contrôle des véhicules : dérivé de validated_at sur les commission_parts,
                 pas d'un snapshot séparé (cf. CommissionAdjustmentService::vehiculesParPeriode) -->
            <div
                v-if="controleVehicules.total > 0"
                class="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-xl border bg-card px-4 py-2.5 text-sm"
            >
                <span>
                    Contrôle des véhicules :
                    <span class="font-semibold">{{
                        controleVehicules.valides
                    }}</span>
                    / {{ controleVehicules.total }} validés
                </span>
                <span
                    v-if="controleVehicules.aRevoir > 0"
                    class="font-medium text-amber-600 dark:text-amber-400"
                >
                    {{ controleVehicules.aRevoir }} à revérifier
                </span>
            </div>

            <!-- Filtres -->
            <DataFilters
                :url="`/backoffice/comptabilite/periodes/${periode.id}`"
                :values="filters"
                :fields="filterFields"
                :result-count="
                    isVehiculeType ? vehicules.length : beneficiaires.length
                "
                hide-agence-selector
            />

            <div
                v-if="isVehiculeType && lignesSelectionnees.length > 0"
                class="flex items-center justify-between gap-3 rounded-lg border border-primary/30 bg-primary/5 px-4 py-2.5"
            >
                <span class="text-sm font-medium">
                    {{ lignesSelectionnees.length }} véhicule{{
                        lignesSelectionnees.length > 1 ? 's' : ''
                    }}
                    sélectionné{{ lignesSelectionnees.length > 1 ? 's' : '' }}
                </span>
                <div class="flex items-center gap-2">
                    <Button
                        variant="ghost"
                        size="sm"
                        :disabled="validationEnCours"
                        @click="selected = new Set()"
                    >
                        Annuler
                    </Button>
                    <Button
                        size="sm"
                        :disabled="validationEnCours"
                        @click="validerSelection"
                    >
                        <Loader2
                            v-if="validationEnCours"
                            class="mr-1.5 h-4 w-4 animate-spin"
                        />
                        <CheckCircle v-else class="mr-1.5 h-4 w-4" />
                        Valider les véhicules ({{ lignesSelectionnees.length }})
                    </Button>
                </div>
            </div>

            <!-- Commissions par véhicule (livreur/propriétaire uniquement) -->
            <!-- data-key="vehicule_id" : point d'extension pour un futur détail par ligne
                 (commandes/commissions composant le montant), via un DataTable expander. -->
            <!-- Largeurs : chaque colonne secondaire s'ajuste à son contenu sans retour à la
                 ligne (COL_*), le Véhicule absorbe l'espace restant. En conteneur étroit,
                 l'immatriculation passe sous le nom du véhicule et Actions reste figée à droite. -->
            <div
                v-if="isVehiculeType"
                class="@container overflow-x-auto rounded-xl border bg-card"
            >
                <DataTable
                    :value="vehicules"
                    :paginator="vehicules.length > 20"
                    :rows="20"
                    data-key="vehicule_id"
                    striped-rows
                    removable-sort
                    class="text-sm"
                    :pt="{
                        root: { class: 'w-full' },
                        tbody: { class: 'divide-y' },
                        bodyRow: bodyRowPt,
                    }"
                    @row-click="onRowClick"
                >
                    <Column v-if="peutValiderVehicules" :pt="COL_SELECTION">
                        <template #header>
                            <Checkbox
                                :model-value="allSelected"
                                :disabled="
                                    selectableRows.length === 0 ||
                                    validationEnCours
                                "
                                aria-label="Sélectionner tous les véhicules à valider"
                                @update:model-value="toggleAll"
                            />
                        </template>
                        <template #body="{ data }">
                            <Checkbox
                                :model-value="
                                    selected.has(routeSegment(data.vehicule_id))
                                "
                                :disabled="
                                    !estValidable(data) || validationEnCours
                                "
                                :aria-label="`Sélectionner ${data.vehicule_nom}`"
                                @update:model-value="toggleRow(data)"
                            />
                        </template>
                    </Column>

                    <Column
                        field="vehicule_nom"
                        header="Véhicule"
                        sortable
                        :pt="COL_VEHICULE"
                    >
                        <template #body="{ data }">
                            <div
                                class="flex items-center gap-2"
                                :title="data.vehicule_nom"
                            >
                                <Truck
                                    class="h-4 w-4 shrink-0 text-muted-foreground"
                                />
                                <div class="min-w-0">
                                    <div class="truncate font-medium">
                                        {{ data.vehicule_nom }}
                                    </div>
                                    <div
                                        class="truncate text-xs text-muted-foreground"
                                    >
                                        <span class="@min-[1150px]:hidden">{{
                                            data.vehicule_immat ?? '—'
                                        }}</span>
                                        <span
                                            v-if="data.taille_equipe !== null"
                                            class="@min-[1000px]:hidden"
                                        >
                                            · Équipe {{ data.taille_equipe }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </Column>

                    <Column
                        field="vehicule_immat"
                        header="Immatriculation"
                        :pt="COL_IMMAT"
                    >
                        <template #body="{ data }">
                            <span class="text-muted-foreground">{{
                                data.vehicule_immat ?? '—'
                            }}</span>
                        </template>
                    </Column>

                    <Column field="nb_commandes" :pt="COL_NOMBRE">
                        <template #header>
                            <span class="font-semibold" title="Commandes">
                                <span class="@min-[1000px]:hidden">Cmd.</span>
                                <span class="hidden @min-[1000px]:inline"
                                    >Commandes</span
                                >
                            </span>
                        </template>
                        <template #body="{ data }">
                            <span class="text-muted-foreground tabular-nums">{{
                                data.nb_commandes
                            }}</span>
                        </template>
                    </Column>

                    <Column field="nb_membres" :pt="COL_NOMBRE">
                        <template #header>
                            <span
                                class="font-semibold"
                                title="Membres ayant une commission sur la période"
                            >
                                <span class="@min-[1000px]:hidden">Memb.</span>
                                <span class="hidden @min-[1000px]:inline"
                                    >Membres</span
                                >
                            </span>
                        </template>
                        <template #body="{ data }">
                            <span class="text-muted-foreground tabular-nums">{{
                                data.nb_membres
                            }}</span>
                        </template>
                    </Column>

                    <Column field="taille_equipe" :pt="COL_EQUIPE">
                        <template #header>
                            <span
                                class="font-semibold"
                                title="Membres actuels de l'équipe du véhicule"
                                >Équipe</span
                            >
                        </template>
                        <template #body="{ data }">
                            <span class="text-muted-foreground tabular-nums">{{
                                data.taille_equipe ?? '—'
                            }}</span>
                        </template>
                    </Column>

                    <Column
                        field="theorique"
                        header="Montant"
                        sortable
                        :pt="COL_MONTANT"
                    >
                        <template #body="{ data }">
                            <span class="font-semibold tabular-nums">{{
                                fmt(data.theorique)
                            }}</span>
                        </template>
                    </Column>

                    <Column
                        field="deja_paye"
                        header="Déjà payé"
                        sortable
                        :pt="COL_MONTANT"
                    >
                        <template #body="{ data }">
                            <span class="text-muted-foreground tabular-nums">{{
                                fmt(data.deja_paye)
                            }}</span>
                        </template>
                    </Column>

                    <Column
                        field="reste"
                        header="Reste à payer"
                        sortable
                        :pt="COL_MONTANT"
                    >
                        <template #body="{ data }">
                            <span
                                class="tabular-nums"
                                :class="
                                    data.reste > 0
                                        ? 'font-medium text-amber-600 dark:text-amber-400'
                                        : 'text-muted-foreground'
                                "
                                >{{ fmt(data.reste) }}</span
                            >
                        </template>
                    </Column>

                    <Column
                        field="statut_validation"
                        header="État"
                        sortable
                        :pt="COL_COMPACTE"
                    >
                        <template #body="{ data }">
                            <StatusDot
                                :status="data.statut_validation"
                                :label="
                                    statutValidationLabel(
                                        data.statut_validation,
                                    )
                                "
                            />
                        </template>
                    </Column>

                    <Column header="" :pt="COL_ACTIONS">
                        <template #body="{ data }">
                            <div
                                v-if="can.ajuster"
                                class="flex justify-end gap-2"
                            >
                                <Button
                                    v-if="
                                        peutValiderVehicules &&
                                        estAValider(data)
                                    "
                                    size="sm"
                                    :disabled="
                                        !data.equilibre || validationEnCours
                                    "
                                    :title="
                                        data.equilibre
                                            ? 'Valider toutes les commissions de ce véhicule'
                                            : 'Reste à répartir : passez par « Ajuster » avant de valider'
                                    "
                                    @click.stop="
                                        validerVehicules([
                                            routeSegment(data.vehicule_id),
                                        ])
                                    "
                                >
                                    <CheckCircle class="h-3.5 w-3.5" />
                                    Valider
                                </Button>
                                <Link :href="ajustementUrl(data.vehicule_id)">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        @click.stop
                                    >
                                        <Wrench class="h-3.5 w-3.5" />
                                        Ajuster
                                    </Button>
                                </Link>
                            </div>
                        </template>
                    </Column>

                    <template #empty>
                        <div
                            class="py-16 text-center text-sm text-muted-foreground"
                        >
                            Aucune commission trouvée pour cette période.
                        </div>
                    </template>
                </DataTable>
            </div>

            <!-- Commissions par bénéficiaire (salarié/site/consultant) : une ligne = une fiche,
                 aucun regroupement par véhicule (concept absent pour ces types). -->
            <div v-else class="overflow-x-auto rounded-xl border bg-card">
                <DataTable
                    :value="beneficiaires"
                    :paginator="beneficiaires.length > 20"
                    :rows="20"
                    data-key="fiche_id"
                    striped-rows
                    removable-sort
                    class="text-sm"
                    :pt="{
                        root: { class: 'w-full min-w-[900px]' },
                        tbody: { class: 'divide-y' },
                    }"
                >
                    <Column
                        field="beneficiaire_nom"
                        header="Bénéficiaire"
                        sortable
                        style="min-width: 220px"
                    >
                        <template #body="{ data }">
                            <span class="font-medium">{{
                                data.beneficiaire_nom
                            }}</span>
                        </template>
                    </Column>

                    <Column
                        field="montant_brut"
                        header="Brut"
                        sortable
                        style="width: 150px"
                    >
                        <template #body="{ data }">
                            <span class="text-muted-foreground tabular-nums">{{
                                fmt(data.montant_brut)
                            }}</span>
                        </template>
                    </Column>

                    <Column
                        field="montant_net"
                        header="Net à payer"
                        sortable
                        style="width: 150px"
                    >
                        <template #body="{ data }">
                            <span class="font-semibold tabular-nums">{{
                                fmt(data.montant_net)
                            }}</span>
                        </template>
                    </Column>

                    <Column
                        field="montant_paye"
                        header="Déjà payé"
                        sortable
                        style="width: 140px"
                    >
                        <template #body="{ data }">
                            <span class="text-muted-foreground tabular-nums">{{
                                fmt(data.montant_paye)
                            }}</span>
                        </template>
                    </Column>

                    <Column
                        field="reste"
                        header="Reste à payer"
                        sortable
                        style="width: 140px"
                    >
                        <template #body="{ data }">
                            <span
                                class="tabular-nums"
                                :class="
                                    data.reste > 0
                                        ? 'font-medium text-amber-600 dark:text-amber-400'
                                        : 'text-muted-foreground'
                                "
                                >{{ fmt(data.reste) }}</span
                            >
                        </template>
                    </Column>

                    <Column
                        field="statut"
                        header="Statut"
                        sortable
                        style="width: 160px"
                    >
                        <template #body="{ data }">
                            <StatusDot
                                :status="data.statut"
                                :label="data.statut_label"
                            />
                        </template>
                    </Column>

                    <template #empty>
                        <div
                            class="py-16 text-center text-sm text-muted-foreground"
                        >
                            Aucune commission trouvée pour cette période.
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>
