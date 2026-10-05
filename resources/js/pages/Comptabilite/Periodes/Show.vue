<script setup lang="ts">
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import ListPageActions from '@/components/ListPageActions.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { useClickableTableRow } from '@/composables/useClickableTableRow';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Calculator,
    CalendarDays,
    CheckCircle,
    ChevronDown,
    Download,
    ExternalLink,
    FileText,
    Loader2,
    Lock,
    MoreHorizontal,
    Search,
    Trash2,
    Truck,
    Wrench,
    X,
} from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Select from 'primevue/select';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed, onMounted, ref, watch } from 'vue';

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
    type_vehicule_id: string | null;
    type_vehicule_nom: string | null;
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
    typesVehicule: { value: string; label: string }[];
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
                  key: 'etat',
                  label: 'État',
                  type: 'select',
                  options: [
                      { value: 'a_verifier', label: 'À vérifier' },
                      { value: 'validee', label: 'Validé' },
                      { value: 'a_reverifier', label: 'À revérifier' },
                      { value: 'payee', label: 'Payé' },
                  ],
              },
              {
                  key: 'livreur',
                  label: 'Livreur',
                  type: 'text',
                  placeholder: 'Nom du livreur…',
              },
              {
                  key: 'proprietaire',
                  label: 'Propriétaire',
                  type: 'text',
                  placeholder: 'Nom du propriétaire…',
              },
          ]
        : [
              {
                  key: 'etat',
                  label: 'État',
                  type: 'select',
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

// Les montants restent sur une ligne. L'immatriculation et la taille d'équipe
// sont regroupées avec le véhicule et les membres pour alléger le tableau.
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

const rechercheKey = computed(() =>
    isVehiculeType.value ? 'vehicule' : 'beneficiaire',
);
const recherche = ref(props.filters[rechercheKey.value] ?? '');
watch(
    () => props.filters[rechercheKey.value],
    (value) => {
        recherche.value = value ?? '';
    },
);
const filtresVisiblesParams = computed(() => {
    const params: Record<string, string> = {};
    for (const key of [rechercheKey.value, 'type_vehicule_id']) {
        if (props.filters[key]) params[key] = props.filters[key];
    }
    return params;
});

function appliquerTypeVehicule(value: string | null) {
    const params = { ...props.filters };
    if (value) params.type_vehicule_id = value;
    else delete params.type_vehicule_id;
    router.get(
        `/backoffice/comptabilite/periodes/${props.periode.id}`,
        params,
        { preserveScroll: true, preserveState: true, replace: true },
    );
}

function appliquerRecherche() {
    const params = { ...props.filters };
    const value = recherche.value.trim();
    if (value) params[rechercheKey.value] = value;
    else delete params[rechercheKey.value];
    router.get(
        `/backoffice/comptabilite/periodes/${props.periode.id}`,
        params,
        { preserveScroll: true, preserveState: true, replace: true },
    );
}

function effacerRecherche() {
    recherche.value = '';
    appliquerRecherche();
}

const filtresActifs = computed(() =>
    Object.values(props.filters).some(Boolean),
);

const progressionControle = computed(() =>
    controleVehicules.value.total > 0
        ? Math.round(
              (controleVehicules.value.valides /
                  controleVehicules.value.total) *
                  100,
          )
        : 0,
);

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
        <div class="flex min-w-0 flex-col gap-5 p-4 sm:p-6">
            <!-- Header -->
            <div
                class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between"
            >
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <h1 class="text-xl font-semibold">
                            {{ titreMetier }}
                        </h1>
                        <StatusDot
                            :status="periode.statut"
                            :label="periode.statut_label"
                        />
                    </div>
                    <p
                        class="mt-2 flex flex-wrap items-center gap-2 text-sm text-muted-foreground"
                    >
                        <CalendarDays class="h-4 w-4 shrink-0" />
                        {{ periodeFormatee ?? '—' }}
                        <span v-if="periode.site">
                            — {{ periode.site.nom }}</span
                        >
                    </p>
                    <p
                        class="mt-1 font-mono text-xs break-all text-muted-foreground"
                    >
                        Référence : {{ periode.reference }}
                    </p>
                    <p
                        v-if="periode.observations"
                        class="mt-1 text-xs text-muted-foreground italic"
                    >
                        {{ periode.observations }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
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
                            Valider la période
                        </Button>
                    </span>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button variant="outline" size="sm">
                                <Download class="h-4 w-4" />
                                Exporter
                                <ChevronDown
                                    class="h-3.5 w-3.5 text-muted-foreground"
                                />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem @select="exportPdf">
                                <FileText /> Télécharger le PDF
                            </DropdownMenuItem>
                            <DropdownMenuItem @select="exportExcel">
                                <Download /> Télécharger Excel
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <DropdownMenu
                        v-if="can.cloturer || can.calculer || can.delete"
                    >
                        <DropdownMenuTrigger as-child>
                            <Button variant="outline" size="sm">
                                <MoreHorizontal class="h-4 w-4" />
                                Actions
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem
                                v-if="can.cloturer"
                                @select="doCloturer"
                            >
                                <Lock /> Clôturer la période
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="can.calculer"
                                @select="doCalculer"
                            >
                                <Calculator /> Recalculer les montants
                            </DropdownMenuItem>
                            <DropdownMenuSeparator
                                v-if="
                                    can.delete && (can.cloturer || can.calculer)
                                "
                            />
                            <DropdownMenuItem
                                v-if="can.delete"
                                class="text-destructive focus:text-destructive"
                                @select="doDelete"
                            >
                                <Trash2 class="text-destructive" /> Supprimer la
                                période
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>

            <div
                v-if="can.valider && !validation.possible && validation.raison"
                role="status"
                class="flex items-start gap-2 rounded-lg border bg-muted/30 px-4 py-3 text-sm text-muted-foreground"
            >
                <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
                <p>{{ validation.raison }}</p>
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
            <div
                class="grid grid-cols-2 gap-3 lg:grid-cols-3"
                aria-label="Montants de la période"
            >
                <div
                    class="col-span-2 rounded-xl border bg-muted/30 p-4 lg:col-span-1"
                >
                    <p class="text-sm font-medium text-muted-foreground">
                        Reste à payer
                    </p>
                    <p
                        class="mt-2 text-2xl font-semibold tracking-tight tabular-nums"
                        :class="
                            stats.reste > 0
                                ? 'text-amber-600 dark:text-amber-400'
                                : ''
                        "
                    >
                        {{ fmt(stats.reste) }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Sur l'ensemble de la période
                    </p>
                </div>
                <div class="min-w-0 rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">Net à payer</p>
                    <p
                        class="mt-2 text-base font-semibold break-words tabular-nums sm:text-xl"
                    >
                        {{ fmt(stats.total_net) }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Total brut : {{ fmt(stats.total_brut) }}
                    </p>
                </div>
                <div class="min-w-0 rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">Déjà payé</p>
                    <p
                        class="mt-2 text-base font-semibold break-words tabular-nums sm:text-xl"
                    >
                        {{ fmt(stats.total_paye) }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Paiements enregistrés
                    </p>
                </div>
            </div>

            <section
                class="min-w-0 overflow-hidden rounded-xl border bg-card"
                aria-labelledby="periode-lignes-title"
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-4"
                >
                    <div class="min-w-0">
                        <h2
                            id="periode-lignes-title"
                            class="text-base font-semibold"
                        >
                            {{
                                isVehiculeType
                                    ? 'Contrôle des véhicules'
                                    : 'Bénéficiaires'
                            }}
                        </h2>
                        <div
                            class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground"
                        >
                            <template
                                v-if="
                                    isVehiculeType &&
                                    controleVehicules.total > 0
                                "
                            >
                                <span
                                    ><span class="font-medium text-foreground"
                                        >{{ controleVehicules.valides }} /
                                        {{ controleVehicules.total }}</span
                                    >
                                    véhicules
                                    {{
                                        filtresActifs ? 'affichés ' : ''
                                    }}validés</span
                                >
                                <div
                                    role="progressbar"
                                    aria-label="Contrôle des véhicules affichés"
                                    :aria-valuenow="progressionControle"
                                    :aria-valuemin="0"
                                    :aria-valuemax="100"
                                    class="h-1.5 w-20 overflow-hidden rounded-full bg-muted"
                                >
                                    <div
                                        class="h-full bg-primary transition-[width]"
                                        :style="{
                                            width: `${progressionControle}%`,
                                        }"
                                    />
                                </div>
                                <span
                                    v-if="controleVehicules.aRevoir > 0"
                                    class="text-amber-600 dark:text-amber-400"
                                    >{{ controleVehicules.aRevoir }} à
                                    revérifier</span
                                >
                            </template>
                            <span v-else
                                >{{
                                    isVehiculeType
                                        ? vehicules.length
                                        : beneficiaires.length
                                }}
                                résultat{{
                                    (isVehiculeType
                                        ? vehicules.length
                                        : beneficiaires.length) > 1
                                        ? 's'
                                        : ''
                                }}</span
                            >
                        </div>
                    </div>
                    <div
                        class="flex w-full flex-wrap items-center gap-2 sm:w-auto"
                    >
                        <Select
                            v-if="isVehiculeType"
                            :model-value="filters.type_vehicule_id ?? null"
                            :options="typesVehicule"
                            option-label="label"
                            option-value="value"
                            placeholder="Tous les types de véhicule"
                            aria-label="Type de véhicule"
                            show-clear
                            class="w-full sm:w-60"
                            :pt="{ root: { class: 'h-9' } }"
                            data-testid="periode-type-vehicule"
                            @update:model-value="appliquerTypeVehicule"
                        />
                        <form
                            class="flex min-w-0 flex-1 items-center gap-2 sm:flex-none"
                            role="search"
                            @submit.prevent="appliquerRecherche"
                        >
                            <div class="relative min-w-0 flex-1 sm:w-64">
                                <Input
                                    v-model="recherche"
                                    :aria-label="
                                        isVehiculeType
                                            ? 'Rechercher un véhicule'
                                            : 'Rechercher un bénéficiaire'
                                    "
                                    :placeholder="
                                        isVehiculeType
                                            ? 'Nom ou immatriculation…'
                                            : 'Nom du bénéficiaire…'
                                    "
                                    class="h-9 pr-8"
                                    data-testid="periode-recherche"
                                />
                                <button
                                    v-if="recherche"
                                    type="button"
                                    class="absolute inset-y-0 right-0 flex w-8 items-center justify-center text-muted-foreground hover:text-foreground"
                                    aria-label="Effacer la recherche"
                                    @click="effacerRecherche"
                                >
                                    <X class="h-4 w-4" />
                                </button>
                            </div>
                            <Button
                                type="submit"
                                variant="outline"
                                size="icon"
                                class="h-9 w-9 shrink-0"
                                aria-label="Lancer la recherche"
                                title="Rechercher"
                                ><Search class="h-4 w-4"
                            /></Button>
                        </form>
                        <ListPageActions>
                            <template #filters>
                                <DataFilters
                                    trigger-only
                                    :url="`/backoffice/comptabilite/periodes/${periode.id}`"
                                    :values="filters"
                                    :base-params="filtresVisiblesParams"
                                    :fields="filterFields"
                                    :result-count="
                                        isVehiculeType
                                            ? vehicules.length
                                            : beneficiaires.length
                                    "
                                    hide-agence-selector
                                />
                            </template>
                        </ListPageActions>
                    </div>
                </div>

                <div
                    v-if="isVehiculeType && lignesSelectionnees.length > 0"
                    class="flex flex-wrap items-center justify-between gap-3 border-b bg-primary/5 px-4 py-3"
                >
                    <span class="text-sm font-medium">
                        {{ lignesSelectionnees.length }} véhicule{{
                            lignesSelectionnees.length > 1 ? 's' : ''
                        }}
                        sélectionné{{
                            lignesSelectionnees.length > 1 ? 's' : ''
                        }}
                    </span>
                    <div class="flex flex-wrap items-center gap-2">
                        <Button
                            variant="ghost"
                            size="sm"
                            :disabled="validationEnCours"
                            @click="selected = new Set()"
                        >
                            Désélectionner
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
                            Valider la sélection ({{
                                lignesSelectionnees.length
                            }})
                        </Button>
                    </div>
                </div>

                <div
                    v-if="isVehiculeType"
                    class="divide-y md:hidden"
                    data-testid="vehicules-mobile"
                >
                    <article
                        v-for="vehicule in vehicules"
                        :key="routeSegment(vehicule.vehicule_id)"
                        class="p-4"
                    >
                        <div class="flex items-start gap-3">
                            <Checkbox
                                v-if="peutValiderVehicules"
                                class="mt-1"
                                :model-value="
                                    selected.has(
                                        routeSegment(vehicule.vehicule_id),
                                    )
                                "
                                :disabled="
                                    !estValidable(vehicule) || validationEnCours
                                "
                                :aria-label="`Sélectionner ${vehicule.vehicule_nom}`"
                                @update:model-value="toggleRow(vehicule)"
                            />
                            <div class="min-w-0 flex-1">
                                <Link
                                    v-if="can.ajuster"
                                    :href="ajustementUrl(vehicule.vehicule_id)"
                                    class="block truncate font-medium hover:underline"
                                    >{{ vehicule.vehicule_nom }}</Link
                                >
                                <p v-else class="truncate font-medium">
                                    {{ vehicule.vehicule_nom }}
                                </p>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    {{
                                        vehicule.vehicule_immat ??
                                        'Sans immatriculation'
                                    }}
                                    <span v-if="vehicule.type_vehicule_nom">
                                        · {{ vehicule.type_vehicule_nom }}</span
                                    >
                                </p>
                            </div>
                            <StatusDot
                                :status="vehicule.statut_validation"
                                :label="
                                    statutValidationLabel(
                                        vehicule.statut_validation,
                                    )
                                "
                                class="shrink-0"
                            />
                        </div>
                        <p class="mt-3 text-xs text-muted-foreground">
                            {{ vehicule.nb_commandes }} commande{{
                                vehicule.nb_commandes > 1 ? 's' : ''
                            }}
                            · {{ vehicule.nb_membres }} membre{{
                                vehicule.nb_membres > 1 ? 's' : ''
                            }}
                            <span v-if="vehicule.taille_equipe !== null">
                                · Équipe : {{ vehicule.taille_equipe }}</span
                            >
                        </p>
                        <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Montant
                                </dt>
                                <dd class="mt-1 font-medium tabular-nums">
                                    {{ fmt(vehicule.theorique) }}
                                </dd>
                            </div>
                            <div class="text-right">
                                <dt class="text-xs text-muted-foreground">
                                    Reste à payer
                                </dt>
                                <dd
                                    class="mt-1 font-semibold tabular-nums"
                                    :class="
                                        vehicule.reste > 0
                                            ? 'text-amber-600 dark:text-amber-400'
                                            : 'text-muted-foreground'
                                    "
                                >
                                    {{ fmt(vehicule.reste) }}
                                </dd>
                            </div>
                        </dl>
                        <p class="mt-2 text-xs text-muted-foreground">
                            Déjà payé : {{ fmt(vehicule.deja_paye) }}
                        </p>
                        <div
                            v-if="can.ajuster"
                            class="mt-3 flex justify-end gap-2"
                        >
                            <Button
                                v-if="
                                    peutValiderVehicules &&
                                    estAValider(vehicule)
                                "
                                size="sm"
                                :disabled="
                                    !vehicule.equilibre || validationEnCours
                                "
                                @click="
                                    validerVehicules([
                                        routeSegment(vehicule.vehicule_id),
                                    ])
                                "
                                ><CheckCircle class="h-3.5 w-3.5" />
                                Valider</Button
                            >
                            <Button as-child variant="outline" size="sm"
                                ><Link
                                    :href="ajustementUrl(vehicule.vehicule_id)"
                                    ><Wrench class="h-3.5 w-3.5" />
                                    Ajuster</Link
                                ></Button
                            >
                        </div>
                        <p
                            v-if="
                                peutValiderVehicules &&
                                estAValider(vehicule) &&
                                !vehicule.equilibre
                            "
                            class="mt-2 text-xs text-muted-foreground"
                        >
                            Reste à répartir : ajustez les commissions avant de
                            valider.
                        </p>
                    </article>
                    <p
                        v-if="vehicules.length === 0"
                        class="px-4 py-12 text-center text-sm text-muted-foreground"
                    >
                        {{
                            filtresActifs
                                ? 'Aucun véhicule ne correspond aux filtres.'
                                : 'Aucune commission trouvée pour cette période.'
                        }}
                    </p>
                </div>

                <!-- Commissions par véhicule (livreur/propriétaire uniquement) -->
                <!-- data-key="vehicule_id" : point d'extension pour un futur détail par ligne
                 (commandes/commissions composant le montant), via un DataTable expander. -->
                <!-- Le tableau est remplacé par des cartes sur mobile. -->
                <div
                    v-if="isVehiculeType"
                    class="@container hidden overflow-x-auto md:block"
                    data-testid="vehicules-table"
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
                                        selected.has(
                                            routeSegment(data.vehicule_id),
                                        )
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
                                            <span>{{
                                                data.vehicule_immat ?? '—'
                                            }}</span>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </Column>

                        <Column
                            field="type_vehicule_nom"
                            header="Type de véhicule"
                            sortable
                            :pt="COL_COMPACTE"
                        >
                            <template #body="{ data }"
                                ><span class="text-muted-foreground">{{
                                    data.type_vehicule_nom ?? '—'
                                }}</span></template
                            >
                        </Column>

                        <Column field="nb_commandes" :pt="COL_NOMBRE">
                            <template #header>
                                <span class="font-semibold" title="Commandes">
                                    <span class="@min-[1000px]:hidden"
                                        >Cmd.</span
                                    >
                                    <span class="hidden @min-[1000px]:inline"
                                        >Commandes</span
                                    >
                                </span>
                            </template>
                            <template #body="{ data }">
                                <span
                                    class="text-muted-foreground tabular-nums"
                                    >{{ data.nb_commandes }}</span
                                >
                            </template>
                        </Column>

                        <Column field="nb_membres" :pt="COL_NOMBRE">
                            <template #header>
                                <span
                                    class="font-semibold"
                                    title="Membres ayant une commission sur la période"
                                >
                                    <span class="@min-[1000px]:hidden"
                                        >Memb.</span
                                    >
                                    <span class="hidden @min-[1000px]:inline"
                                        >Membres</span
                                    >
                                </span>
                            </template>
                            <template #body="{ data }">
                                <span
                                    class="text-muted-foreground tabular-nums"
                                    >{{ data.nb_membres }}</span
                                >
                                <p
                                    v-if="data.taille_equipe !== null"
                                    class="text-xs text-muted-foreground"
                                    title="Membres actuels de l'équipe du véhicule"
                                >
                                    Équipe : {{ data.taille_equipe }}
                                </p>
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
                                <span
                                    class="text-muted-foreground tabular-nums"
                                    >{{ fmt(data.deja_paye) }}</span
                                >
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

                        <Column header="Actions" :pt="COL_ACTIONS">
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
                                        variant="outline"
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
                                    <Button as-child variant="ghost" size="sm">
                                        <Link
                                            :href="
                                                ajustementUrl(data.vehicule_id)
                                            "
                                            @click.stop
                                        >
                                            <Wrench class="h-3.5 w-3.5" />
                                            Ajuster
                                        </Link>
                                    </Button>
                                </div>
                            </template>
                        </Column>

                        <template #empty>
                            <div
                                class="py-16 text-center text-sm text-muted-foreground"
                            >
                                {{
                                    filtresActifs
                                        ? 'Aucun véhicule ne correspond aux filtres.'
                                        : 'Aucune commission trouvée pour cette période.'
                                }}
                            </div>
                        </template>
                    </DataTable>
                </div>

                <!-- Commissions par bénéficiaire (salarié/site/consultant) : une ligne = une fiche,
                 aucun regroupement par véhicule (concept absent pour ces types). -->
                <div v-if="!isVehiculeType" class="overflow-x-auto">
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
                                <span
                                    class="text-muted-foreground tabular-nums"
                                    >{{ fmt(data.montant_brut) }}</span
                                >
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
                                <span
                                    class="text-muted-foreground tabular-nums"
                                    >{{ fmt(data.montant_paye) }}</span
                                >
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
                                {{
                                    filtresActifs
                                        ? 'Aucun bénéficiaire ne correspond aux filtres.'
                                        : 'Aucune commission trouvée pour cette période.'
                                }}
                            </div>
                        </template>
                    </DataTable>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
