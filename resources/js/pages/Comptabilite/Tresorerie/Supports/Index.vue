<script setup lang="ts">
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import InfoTooltip from '@/components/InfoTooltip.vue';
import KpiCard from '@/components/KpiCard.vue';
import ListPageActions from '@/components/ListPageActions.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useFlashToast } from '@/composables/useFlashToast';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatGNF, formatQuantite } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    ArrowRightLeft,
    CheckCircle2,
    Info,
    MoreVertical,
    Pencil,
    PiggyBank,
    Plus,
    Power,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed, ref, watch } from 'vue';
import {
    actionsMenu,
    alerteSoldeOuverture,
    cartesResume,
    natureAffichee,
    resumeSupports,
    type CompteTresorerie,
    type Nature,
} from './partials/presentation';

interface Agent {
    id: string;
    nom: string;
    site_ids: string[];
}

const props = defineProps<{
    comptes: CompteTresorerie[];
    filters: {
        site_ids: string[];
        statut: string;
        type: string;
        nature: string;
        agent_id: string;
    };
    sites: { id: string; nom: string }[];
    type_options: { value: string; label: string }[];
    operateur_options: { value: string; label: string }[];
    destinations_versement: { id: string; site_id: string; libelle: string }[];
    agents: Agent[];
    caisses_dediees_actives: { agent_id: string; site_id: string }[];
    agents_filtre: { value: string; label: string }[];
    comptes_comptables: {
        id: string;
        numero: string;
        libelle: string;
        type_support: string | null;
    }[];
}>();

useFlashToast('top');

// Création, modification et soldes d'ouverture : réservés à ceux qui gèrent les supports. Un simple
// lecteur (tresorerie.read) voit la liste de ses agences ; « Verser à l'agence » dépend, lui, de
// l'indicateur `peut_verser` de chaque ligne (permission, portée, état de la caisse).
const { can } = usePermissions();
const peutGerer = computed(() => can('tresorerie.gerer_soldes_ouverture'));
// Valider un support (brouillon → actif) : permission dédiée, distincte de celle de gestion.
const peutValider = computed(() => can('tresorerie.valider_supports'));

const URL_SUPPORTS = '/backoffice/comptabilite/tresorerie/supports';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité' },
    { title: 'Supports de trésorerie', href: '#' },
];

// Le détail du solde d'ouverture n'apparaît que dans « Modifier le support » : la liste ne le
// signale que lorsqu'une action est requise (cf. alerteSoldeOuverture).
const soldeStatutLabels: Record<string, string> = {
    brouillon: 'À valider',
    valide: 'Validé',
};

function dateFr(iso: string | null): string {
    return iso ? new Date(iso).toLocaleDateString('fr-FR') : '';
}

// ── Filtres (source de vérité : URL + backend) ───────────────────────────────

const filterFields = computed((): FilterField[] => [
    {
        key: 'statut',
        label: 'Statut',
        type: 'select',
        options: [
            { value: 'actif', label: 'Actif' },
            { value: 'inactif', label: 'Inactif' },
            { value: 'brouillon', label: 'Brouillon' },
        ],
    },
    { key: 'type', label: 'Type', type: 'select', options: props.type_options },
    {
        key: 'nature',
        label: 'Nature',
        type: 'select',
        options: [
            { value: 'agence', label: "Caisse de l'agence" },
            { value: 'dediee', label: 'Caisse dédiée à un agent' },
        ],
    },
    {
        key: 'agent_id',
        label: 'Agent',
        type: 'select',
        options: props.agents_filtre,
    },
]);

const filtreActif = computed(
    () =>
        props.filters.site_ids.length > 0 ||
        !!props.filters.statut ||
        !!props.filters.type ||
        !!props.filters.nature ||
        !!props.filters.agent_id,
);

const confirm = useConfirm();
const toast = useToast();

// Nature affichée et actions du menu ⋮ calculées une fois par ligne (cf. partials/presentation.ts).
const lignes = computed(() =>
    props.comptes.map((c) => ({
        c,
        nature: natureAffichee(c),
        actions: actionsMenu(c, peutGerer.value),
        alerte: alerteSoldeOuverture(c),
    })),
);

// Cartes de synthèse des supports affichés (filtres actifs inclus) : titre court, valeur, au plus
// une information secondaire — le détail métier reste en infobulle (cf. cartesResume). Le solde
// total est une vue de situation, jamais le « Disponible » de Financement ; l'en cours de
// versement n'est jamais ajouté au solde.
const cartes = computed(() =>
    cartesResume(resumeSupports(props.comptes), filtreActif.value),
);

// Un compte Mobile Money ne doit pas être sélectionnable pour un support
// Caisse (et inversement) — cf. revue du 2026-08-22. type_support est déduit
// côté serveur depuis compta_mappings ; un compte non classé (null) reste
// affiché pour ne pas bloquer un paramétrage encore incomplet.
function comptesCompatibles(type: string) {
    return props.comptes_comptables.filter(
        (c) => c.type_support === null || c.type_support === type,
    );
}

// ── Création ─────────────────────────────────────────────────────────────────

const createOpen = ref(false);

const form = useForm({
    nature: 'agence' as Nature,
    site_id: '',
    agent_id: '',
    type: 'caisse',
    operateur_mobile_money: '',
    compte_comptable_id: '',
    libelle: '',
    moyen_paiement_defaut: '',
});

const comptesFiltres = computed(() => comptesCompatibles(form.type));

// Présélectionne le compte quand une seule option est compatible avec le type,
// sinon laisse l'utilisateur choisir. Rappelée à l'ouverture du dialogue : le
// reset() du formulaire vide le compte sans changer le type, donc sans
// redéclencher le watcher.
function preselectionnerCompte() {
    const compatibles = comptesFiltres.value;
    const selectionToujoursValide = compatibles.some(
        (c) => c.id === form.compte_comptable_id,
    );
    if (selectionToujoursValide) return;
    form.compte_comptable_id =
        compatibles.length === 1 ? compatibles[0].id : '';
}

watch(() => form.type, preselectionnerCompte, { immediate: true });

// Agents proposés : rattachés à l'agence choisie et sans caisse dédiée active
// sur cette agence. Le contrôle réel reste côté serveur (CaisseAgentService).
const agentsEligibles = computed(() => {
    if (!form.site_id) return [];
    return props.agents.filter(
        (a) =>
            a.site_ids.includes(form.site_id) &&
            !props.caisses_dediees_actives.some(
                (c) => c.agent_id === a.id && c.site_id === form.site_id,
            ),
    );
});

watch([() => form.site_id, () => form.nature], () => {
    if (!agentsEligibles.value.some((a) => a.id === form.agent_id)) {
        form.agent_id = '';
    }
});

// Aperçu du libellé auto-généré si l'utilisateur ne saisit rien — la valeur
// réelle est calculée côté serveur (App\Models\CompteTresorerie::boot()),
// ceci n'est qu'un aperçu pour que l'utilisateur comprenne ce qui sera créé.
const libellePreview = computed(() => {
    if (form.nature === 'dediee') {
        const agent = props.agents.find((a) => a.id === form.agent_id);
        return agent
            ? `Automatique : Caisse ${agent.nom}`
            : 'Libellé (optionnel)';
    }
    const site = props.sites.find((s) => s.id === form.site_id);
    const type = props.type_options.find((t) => t.value === form.type);
    if (!site || !type) return 'Libellé (optionnel)';
    const operateur =
        form.type === 'mobile_money'
            ? props.operateur_options.find(
                  (o) => o.value === form.operateur_mobile_money,
              )
            : undefined;
    return `Automatique : ${operateur?.label ?? type.label} de ${site.nom}`;
});

function ouvrirCreation() {
    form.reset();
    form.clearErrors();
    preselectionnerCompte();
    createOpen.value = true;
}

function creerSupport() {
    form.transform((data) =>
        data.nature === 'dediee'
            ? {
                  nature: data.nature,
                  site_id: data.site_id,
                  agent_id: data.agent_id,
                  libelle: data.libelle,
              }
            : {
                  nature: data.nature,
                  site_id: data.site_id,
                  type: data.type,
                  operateur_mobile_money:
                      data.type === 'mobile_money'
                          ? data.operateur_mobile_money
                          : null,
                  compte_comptable_id: data.compte_comptable_id,
                  libelle: data.libelle,
                  moyen_paiement_defaut: data.moyen_paiement_defaut,
              },
    ).post(URL_SUPPORTS, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            createOpen.value = false;
            form.reset();
        },
    });
}

// ── Modification ─────────────────────────────────────────────────────────────

const editForm = useForm({
    libelle: '',
    type: '',
    operateur_mobile_money: '',
    compte_comptable_id: '',
    moyen_paiement_defaut: '',
    actif: true,
});
const editDialogPour = ref<CompteTresorerie | null>(null);
const editVisible = computed({
    get: () => editDialogPour.value !== null,
    set: (visible: boolean) => {
        if (!visible) editDialogPour.value = null;
    },
});
const editEstDediee = computed(() => editDialogPour.value?.nature === 'dediee');

// Une fois un solde d'ouverture saisi, le type et le compte comptable sont
// figés côté serveur (cf. CompteTresorerieController::update()) — les selects
// sont désactivés en cohérence, seul le libellé reste modifiable.
const editionTypeCompteVerrouillee = computed(
    () => !!editDialogPour.value?.solde_ouverture,
);

const comptesFiltresEdition = computed(() => comptesCompatibles(editForm.type));

watch(
    () => editForm.type,
    () => {
        if (!editDialogPour.value || editionTypeCompteVerrouillee.value) return;
        const compatibles = comptesFiltresEdition.value;
        const selectionToujoursValide = compatibles.some(
            (c) => c.id === editForm.compte_comptable_id,
        );
        if (selectionToujoursValide) return;
        editForm.compte_comptable_id =
            compatibles.length === 1 ? compatibles[0].id : '';
    },
);

function ouvrirEdition(compte: CompteTresorerie) {
    editForm.clearErrors();
    editForm.libelle = compte.libelle;
    editForm.type = compte.type;
    editForm.operateur_mobile_money = compte.operateur_mobile_money ?? '';
    editForm.compte_comptable_id = compte.compte_comptable_id;
    editForm.moyen_paiement_defaut = compte.moyen_paiement_defaut ?? '';
    editForm.actif = compte.actif;
    editDialogPour.value = compte;
}

function enregistrerEdition() {
    if (!editDialogPour.value) return;
    const dediee = editEstDediee.value;
    editForm
        .transform((data) =>
            dediee
                ? { libelle: data.libelle, actif: data.actif }
                : {
                      ...data,
                      operateur_mobile_money:
                          data.type === 'mobile_money'
                              ? data.operateur_mobile_money
                              : null,
                  },
        )
        .put(`${URL_SUPPORTS}/${editDialogPour.value.id}`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                editDialogPour.value = null;
            },
        });
}

// ── Activation / désactivation depuis le menu d'actions ──────────────────────
// Même requête que le dialogue de modification (mêmes champs, `actif` inversé) : aucune règle
// nouvelle, le serveur reste seul juge (CompteTresorerieController::update()).

function basculerActif(compte: CompteTresorerie) {
    const actif = !compte.actif;
    const donnees =
        compte.nature === 'dediee'
            ? { libelle: compte.libelle, actif }
            : {
                  libelle: compte.libelle,
                  type: compte.type,
                  operateur_mobile_money: compte.operateur_mobile_money,
                  compte_comptable_id: compte.compte_comptable_id,
                  moyen_paiement_defaut: compte.moyen_paiement_defaut ?? '',
                  actif,
              };

    router.put(`${URL_SUPPORTS}/${compte.id}`, donnees, {
        preserveScroll: true,
        preserveState: true,
        onError: (errors) =>
            toast.add({
                severity: 'error',
                summary: 'Modification impossible',
                detail: Object.values(errors)[0],
                life: 6000,
                group: 'top',
            }),
    });
}

function confirmerDesactivation(compte: CompteTresorerie) {
    confirm.require({
        header: 'Désactiver ce support ?',
        message: `« ${compte.libelle} » sera désactivé. Son solde reste affiché et vous pourrez le réactiver à tout moment.`,
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Annuler',
        acceptLabel: 'Désactiver',
        acceptClass: 'p-button-danger',
        accept: () => basculerActif(compte),
    });
}

// ── Validation d'un support (brouillon → actif) ──────────────────────────────
// Le serveur reste seul juge (permission, agence, état, conditions propres à une caisse dédiée :
// SupportTresorerieValidationService) ; un refus s'affiche en toast.

const validationSupportEnCours = ref<string | null>(null);

function validerSupport(compte: CompteTresorerie) {
    if (validationSupportEnCours.value) return;

    validationSupportEnCours.value = compte.id;
    router.post(
        `${URL_SUPPORTS}/${compte.id}/valider`,
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onError: (errors) =>
                toast.add({
                    severity: 'error',
                    summary: 'Validation impossible',
                    detail: Object.values(errors)[0],
                    life: 6000,
                    group: 'top',
                }),
            onFinish: () => {
                validationSupportEnCours.value = null;
            },
        },
    );
}

function confirmerValidation(compte: CompteTresorerie) {
    confirm.require({
        header: 'Valider ce support ?',
        message: `« ${compte.libelle} » deviendra actif : il pourra recevoir des encaissements et des mouvements de fonds. La validation est tracée à votre nom.`,
        icon: 'pi pi-check-circle',
        rejectLabel: 'Annuler',
        acceptLabel: 'Valider',
        accept: () => validerSupport(compte),
    });
}

// ── Solde d'ouverture (supports d'agence uniquement) ─────────────────────────

const soldeForm = useForm({
    compte_tresorerie_id: '',
    date_situation: new Date().toISOString().slice(0, 10),
    montant: '',
    commentaire: '',
});
const soldeDialogPourId = ref<string | null>(null);
const soldeVisible = computed({
    get: () => soldeDialogPourId.value !== null,
    set: (visible: boolean) => {
        if (!visible) soldeDialogPourId.value = null;
    },
});
const soldeMontantDisplay = ref('');

// Saisie d'un montant entier : valeur brute envoyée au serveur + affichage avec séparateurs.
function lireMontantSaisi(e: Event): { brut: string; affiche: string } {
    const raw = (e.target as HTMLInputElement).value.replace(/\D/g, '');
    return {
        brut: raw ? String(Number.parseInt(raw, 10)) : '',
        affiche: raw
            ? Number.parseInt(raw, 10).toLocaleString('fr-FR', {
                  maximumFractionDigits: 0,
              })
            : '',
    };
}

function handleSoldeMontantInput(e: Event) {
    const { brut, affiche } = lireMontantSaisi(e);
    soldeForm.montant = brut;
    soldeMontantDisplay.value = affiche;
}

function ouvrirSolde(id: string) {
    soldeDialogPourId.value = id;
    soldeForm.clearErrors();
    soldeForm.compte_tresorerie_id = id;
    soldeForm.montant = '';
    soldeMontantDisplay.value = '';
}

function enregistrerSolde() {
    soldeForm.post('/backoffice/comptabilite/tresorerie/soldes-ouverture', {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            soldeDialogPourId.value = null;
        },
    });
}

// Protège contre un double clic pendant la requête (idempotent côté serveur,
// mais évite quand même deux visites Inertia superflues).
const validationEnCours = ref<string | null>(null);

function validerSolde(compte: CompteTresorerie) {
    if (!compte.solde_ouverture || validationEnCours.value) return;

    validationEnCours.value = compte.id;
    router.post(
        `/backoffice/comptabilite/tresorerie/soldes-ouverture/${compte.solde_ouverture.id}/valider`,
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                validationEnCours.value = null;
            },
        },
    );
}

// ── Versement d'une caisse dédiée vers la caisse de l'agence ─────────────────
// Crée un mouvement « Envoyé » : la caisse de l'agent baisse tout de suite, celle de l'agence
// n'augmente qu'à la confirmation de réception par un autre utilisateur (écran Mouvements).

const versementCible = ref<CompteTresorerie | null>(null);
const versementVisible = computed({
    get: () => versementCible.value !== null,
    set: (visible: boolean) => {
        if (!visible) versementCible.value = null;
    },
});
const versementForm = useForm({
    compte_tresorerie_destination_id: '',
    montant: '',
    motif: 'Versement caisse agent',
});
const versementMontantDisplay = ref('');
// Erreur portant sur la caisse source (pas un champ du formulaire) : caisse désactivée, non dédiée...
const erreurCaisseSource = computed(
    () =>
        (versementForm.errors as Record<string, string | undefined>)
            .compte_tresorerie_id,
);

const destinationsDuVersement = computed(() =>
    props.destinations_versement.filter(
        (d) => d.site_id === versementCible.value?.site_id,
    ),
);
const versementMontant = computed(() => Number(versementForm.montant || 0));
const soldeApresVersement = computed(
    () => (versementCible.value?.solde ?? 0) - versementMontant.value,
);
const versementInvalide = computed(
    () =>
        versementMontant.value <= 0 ||
        soldeApresVersement.value < 0 ||
        !versementForm.compte_tresorerie_destination_id,
);

function ouvrirVersement(compte: CompteTresorerie) {
    versementForm.reset();
    versementForm.clearErrors();
    versementMontantDisplay.value = '';
    versementCible.value = compte;
    // Une seule caisse de destination possible : présélectionnée.
    versementForm.compte_tresorerie_destination_id =
        destinationsDuVersement.value.length === 1
            ? destinationsDuVersement.value[0].id
            : '';
}

function handleVersementMontantInput(e: Event) {
    const { brut, affiche } = lireMontantSaisi(e);
    versementForm.montant = brut;
    versementMontantDisplay.value = affiche;
}

function envoyerVersement() {
    if (!versementCible.value || versementInvalide.value) return;

    versementForm.post(`${URL_SUPPORTS}/${versementCible.value.id}/verser`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            versementCible.value = null;
        },
    });
}

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm disabled:opacity-50';
</script>

<template>
    <Head title="Supports de trésorerie" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full space-y-6 p-4 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex flex-col gap-1">
                    <h1 class="flex items-center gap-2 text-xl font-semibold">
                        Supports de trésorerie
                        <InfoTooltip
                            label="Informations sur les supports de trésorerie"
                        >
                            <p class="mb-2 font-semibold">
                                Cette page regroupe :
                            </p>
                            <ul class="list-disc space-y-2 pl-4">
                                <li>
                                    Les
                                    <strong>caisses de l'agence</strong>.
                                </li>
                                <li>
                                    Les
                                    <strong>caisses dédiées aux agents</strong>.
                                </li>
                                <li>Les <strong>banques</strong>.</li>
                                <li>
                                    Les comptes
                                    <strong>Mobile Money</strong>.
                                </li>
                            </ul>
                            <p class="mt-3">
                                Le <strong>solde actuel</strong> de chaque
                                support est calculé à partir du grand livre.
                            </p>
                        </InfoTooltip>
                    </h1>
                </div>
                <ListPageActions>
                    <template #filters>
                        <DataFilters
                            trigger-only
                            :url="URL_SUPPORTS"
                            :values="filters"
                            :fields="filterFields"
                            :sites="sites"
                            :result-count="comptes.length"
                            hide-result-count
                        />
                    </template>
                    <template v-if="peutGerer" #primary>
                        <Button
                            size="sm"
                            data-testid="support-create-open"
                            @click="ouvrirCreation"
                        >
                            <Plus class="mr-1.5 h-3.5 w-3.5" />
                            Nouvelle caisse
                        </Button>
                    </template>
                </ListPageActions>
            </div>

            <!-- Grille de la carte KPI commune : 1 / 2 / 4 colonnes selon la zone disponible. -->
            <div class="@container">
                <div
                    class="grid grid-cols-12 gap-7 font-apollo antialiased"
                    data-testid="support-kpis"
                >
                    <div
                        v-for="carte in cartes"
                        :key="carte.id"
                        class="col-span-12 @[40rem]:col-span-6 @[70rem]:col-span-3"
                        :title="carte.astuce"
                        :data-testid="`support-kpi-${carte.id}`"
                    >
                        <KpiCard
                            :title="carte.titre"
                            :value="carte.valeur"
                            :unit="carte.unite ?? undefined"
                            :detail="carte.detail ?? undefined"
                        />
                    </div>
                </div>
            </div>

            <div class="overflow-x-auto rounded-xl border bg-card">
                <table class="w-full min-w-[1060px] text-sm">
                    <thead>
                        <tr class="border-b bg-muted/40 text-left">
                            <th class="px-4 py-3 font-medium">Agence</th>
                            <th class="px-4 py-3 font-medium">Caisse</th>
                            <th class="px-4 py-3 font-medium">Compte</th>
                            <th class="px-4 py-3 font-medium">Nature</th>
                            <th class="px-4 py-3 font-medium">Responsable</th>
                            <th class="px-4 py-3 text-right font-medium">
                                Solde
                            </th>
                            <th class="px-4 py-3 text-left font-medium">
                                Statut
                            </th>
                            <th class="px-4 py-3 text-right font-medium">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="{ c, nature, actions, alerte } in lignes"
                            :key="c.id"
                            data-testid="support-row"
                        >
                            <td class="px-4 py-4 align-middle">{{ c.site }}</td>
                            <td class="px-4 py-4 align-middle">
                                <div class="font-medium">{{ c.libelle }}</div>
                                <div
                                    v-if="alerte"
                                    class="mt-0.5 text-xs font-medium text-amber-600 dark:text-amber-400"
                                    data-testid="support-alerte-solde"
                                >
                                    {{ alerte }}
                                </div>
                            </td>
                            <td
                                class="px-4 py-4 align-middle tabular-nums"
                                data-testid="support-compte"
                            >
                                <span v-if="c.compte_numero">{{
                                    c.compte_numero
                                }}</span>
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </td>
                            <td class="px-4 py-4 align-middle">
                                <Badge
                                    :variant="nature.variante"
                                    :title="nature.titre"
                                    data-testid="support-nature"
                                >
                                    <component
                                        :is="nature.icone"
                                        aria-hidden="true"
                                    />
                                    {{ nature.label }}
                                </Badge>
                            </td>
                            <td class="px-4 py-4 align-middle">
                                <span v-if="c.agent" class="font-medium">{{
                                    c.agent.nom
                                }}</span>
                                <Badge
                                    v-else
                                    variant="outline"
                                    class="font-normal text-muted-foreground"
                                    >Agence</Badge
                                >
                            </td>
                            <td
                                class="px-4 py-4 text-right align-middle whitespace-nowrap"
                            >
                                <span
                                    class="text-base font-semibold tabular-nums"
                                    data-testid="support-solde"
                                >
                                    {{ formatQuantite(c.solde) }}
                                    <span
                                        class="text-xs font-medium text-muted-foreground"
                                        >GNF</span
                                    >
                                </span>
                                <!-- Versement envoyé, pas encore reçu : déjà sorti du solde ci-dessus, pas encore
                                     crédité à la caisse de l'agence. Information de suivi, pas du solde. -->
                                <div
                                    v-if="c.en_cours_versement > 0"
                                    class="mt-0.5 text-xs font-medium text-blue-600 dark:text-blue-400"
                                    :title="`Envoyé, en attente de confirmation par la caisse de l'agence : ce montant n'est plus dans le solde de cette caisse et n'est pas encore crédité ailleurs.`"
                                    data-testid="support-en-cours-versement"
                                >
                                    En cours de versement :
                                    {{ formatGNF(c.en_cours_versement) }}
                                </div>
                            </td>
                            <td class="px-4 py-4 align-middle">
                                <StatusDot
                                    :status="c.statut"
                                    :label="c.statut_label"
                                    data-testid="support-statut"
                                />
                            </td>
                            <td
                                class="px-4 py-4 text-right align-middle whitespace-nowrap"
                            >
                                <div
                                    class="flex items-center justify-end gap-2"
                                >
                                    <Button
                                        v-if="peutValider && c.peut_valider"
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        data-testid="support-valider"
                                        :disabled="
                                            validationSupportEnCours === c.id
                                        "
                                        @click="confirmerValidation(c)"
                                    >
                                        <CheckCircle2
                                            class="mr-1.5 h-3.5 w-3.5"
                                        />
                                        Valider
                                    </Button>
                                    <Button
                                        v-if="c.peut_verser"
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        data-testid="support-verser"
                                        @click="ouvrirVersement(c)"
                                    >
                                        <ArrowRightLeft
                                            class="mr-1.5 h-3.5 w-3.5"
                                        />
                                        Verser à l'agence
                                    </Button>
                                    <DropdownMenu v-if="actions.length > 0">
                                        <DropdownMenuTrigger as-child>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                class="h-8 w-8 shrink-0"
                                                data-testid="support-actions"
                                                :aria-label="`Actions pour ${c.libelle}`"
                                            >
                                                <MoreVertical class="h-4 w-4" />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent
                                            align="end"
                                            class="w-60"
                                        >
                                            <DropdownMenuItem
                                                v-if="
                                                    actions.includes('modifier')
                                                "
                                                class="cursor-pointer"
                                                @click="ouvrirEdition(c)"
                                            >
                                                <Pencil class="h-4 w-4" />
                                                Modifier
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                v-if="
                                                    actions.includes(
                                                        'saisir_solde_ouverture',
                                                    )
                                                "
                                                class="cursor-pointer"
                                                @click="ouvrirSolde(c.id)"
                                            >
                                                <PiggyBank class="h-4 w-4" />
                                                Saisir le solde d'ouverture
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                v-if="
                                                    actions.includes(
                                                        'valider_solde_ouverture',
                                                    )
                                                "
                                                class="cursor-pointer"
                                                :disabled="
                                                    validationEnCours === c.id
                                                "
                                                @click="validerSolde(c)"
                                            >
                                                <CheckCircle2 class="h-4 w-4" />
                                                Valider le solde d'ouverture
                                            </DropdownMenuItem>
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem
                                                v-if="
                                                    actions.includes(
                                                        'desactiver',
                                                    )
                                                "
                                                class="cursor-pointer text-destructive focus:text-destructive"
                                                @click="
                                                    confirmerDesactivation(c)
                                                "
                                            >
                                                <Power class="h-4 w-4" />
                                                Désactiver
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                v-if="
                                                    actions.includes(
                                                        'reactiver',
                                                    )
                                                "
                                                class="cursor-pointer"
                                                @click="basculerActif(c)"
                                            >
                                                <Power class="h-4 w-4" />
                                                Réactiver
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="comptes.length === 0">
                            <td
                                colspan="8"
                                class="px-4 py-10 text-center text-muted-foreground"
                            >
                                {{
                                    filtreActif
                                        ? 'Aucun support ne correspond à ces filtres.'
                                        : 'Aucun support de trésorerie configuré.'
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>

    <!-- Versement d'une caisse dédiée vers la caisse de l'agence -->
    <Dialog
        v-model:visible="versementVisible"
        modal
        header="Verser à l'agence"
        :style="{ width: 'min(480px, 95vw)' }"
        :dismissable-mask="true"
    >
        <form
            v-if="versementCible"
            id="versement-form"
            class="space-y-4 pb-1"
            data-testid="versement-form"
            @submit.prevent="envoyerVersement"
        >
            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1 text-sm">
                <span class="text-muted-foreground">Depuis</span>
                <span class="font-medium">{{ versementCible.libelle }}</span>
            </div>

            <div>
                <Label for="vers-dest" class="mb-1.5 block text-xs font-medium">
                    Caisse destination
                    <span class="text-destructive">*</span>
                    <span class="font-normal text-muted-foreground">
                        · {{ versementCible.site }}
                    </span>
                </Label>
                <select
                    id="vers-dest"
                    v-model="versementForm.compte_tresorerie_destination_id"
                    :class="selectClass"
                >
                    <option value="" disabled>Choisir une caisse…</option>
                    <option
                        v-for="d in destinationsDuVersement"
                        :key="d.id"
                        :value="d.id"
                    >
                        {{ d.libelle }}
                    </option>
                </select>
                <p
                    v-if="versementForm.errors.compte_tresorerie_destination_id"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ versementForm.errors.compte_tresorerie_destination_id }}
                </p>
            </div>

            <div>
                <Label
                    for="vers-montant"
                    class="mb-1.5 block text-xs font-medium"
                >
                    Montant (GNF) <span class="text-destructive">*</span>
                </Label>
                <input
                    id="vers-montant"
                    :value="versementMontantDisplay"
                    type="text"
                    inputmode="numeric"
                    placeholder="0"
                    class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm tabular-nums shadow-sm"
                    @input="handleVersementMontantInput"
                />
                <p
                    v-if="versementForm.errors.montant"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ versementForm.errors.montant }}
                </p>
                <dl
                    class="mt-2 grid grid-cols-2 gap-3 text-xs"
                    data-testid="versement-recapitulatif"
                >
                    <div>
                        <dt class="text-muted-foreground">Disponible</dt>
                        <dd class="mt-0.5 font-medium tabular-nums">
                            {{ formatGNF(versementCible.solde) }}
                        </dd>
                    </div>
                    <div class="text-right">
                        <dt class="text-muted-foreground">Reste après envoi</dt>
                        <dd
                            class="mt-0.5 font-medium tabular-nums"
                            :class="{
                                'text-destructive': soldeApresVersement < 0,
                            }"
                            aria-live="polite"
                        >
                            {{ formatGNF(soldeApresVersement) }}
                        </dd>
                    </div>
                </dl>
            </div>
            <p
                v-if="soldeApresVersement < 0"
                class="text-xs text-destructive"
                data-testid="versement-depassement"
            >
                Le montant dépasse le solde disponible de la caisse.
            </p>

            <div>
                <Label for="vers-motif" class="mb-1.5 block text-xs font-medium"
                    >Motif</Label
                >
                <Input id="vers-motif" v-model="versementForm.motif" />
                <p
                    v-if="versementForm.errors.motif"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ versementForm.errors.motif }}
                </p>
            </div>

            <p v-if="erreurCaisseSource" class="text-xs text-destructive">
                {{ erreurCaisseSource }}
            </p>

            <div
                class="flex items-center gap-2 text-xs text-muted-foreground"
                data-testid="versement-confirmation"
            >
                <p>La caisse de l'agence sera créditée après confirmation.</p>
                <TooltipProvider :delay-duration="150">
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <button
                                type="button"
                                aria-label="Comment confirmer la réception des fonds"
                                class="shrink-0 rounded-sm text-primary outline-none hover:text-primary/80 focus-visible:ring-2 focus-visible:ring-ring"
                            >
                                <Info class="h-4 w-4" aria-hidden="true" />
                            </button>
                        </TooltipTrigger>
                        <TooltipContent
                            side="top"
                            class="z-[1200] w-80 max-w-[calc(100vw-2rem)] px-4 py-3 text-sm leading-relaxed"
                        >
                            Un autre utilisateur habilité de l'agence
                            {{ versementCible.site }} doit confirmer la
                            réception dans « Mouvements de fonds ». La caisse de
                            l'agence est créditée uniquement après cette
                            confirmation.
                        </TooltipContent>
                    </Tooltip>
                </TooltipProvider>
            </div>
        </form>
        <template #footer>
            <div class="flex w-full justify-end gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="versementCible = null"
                    >Annuler</Button
                >
                <Button
                    type="submit"
                    form="versement-form"
                    size="sm"
                    data-testid="versement-envoyer"
                    :disabled="versementForm.processing || versementInvalide"
                >
                    {{ versementForm.processing ? 'Envoi…' : 'Envoyer' }}
                </Button>
            </div>
        </template>
    </Dialog>

    <!-- Création -->
    <Dialog
        v-model:visible="createOpen"
        modal
        header="Créer une caisse"
        :style="{ width: 'min(520px, 95vw)' }"
        :dismissable-mask="true"
    >
        <form class="space-y-4 pt-2 pb-1" @submit.prevent="creerSupport">
            <div>
                <Label for="sup-site" class="mb-1.5 block text-xs font-medium">
                    Agence <span class="text-destructive">*</span>
                </Label>
                <select
                    id="sup-site"
                    v-model="form.site_id"
                    :class="selectClass"
                >
                    <option value="" disabled>Choisir une agence…</option>
                    <option v-for="s in sites" :key="s.id" :value="s.id">
                        {{ s.nom }}
                    </option>
                </select>
                <p
                    v-if="form.errors.site_id"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ form.errors.site_id }}
                </p>
            </div>

            <div>
                <Label
                    for="sup-nature"
                    class="mb-1.5 block text-xs font-medium"
                >
                    Nature <span class="text-destructive">*</span>
                </Label>
                <select
                    id="sup-nature"
                    v-model="form.nature"
                    :class="selectClass"
                >
                    <option value="agence">Caisse de l'agence</option>
                    <option value="dediee">Caisse dédiée à un agent</option>
                </select>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{
                        form.nature === 'dediee'
                            ? 'Alimentée par ses encaissements en espèces.'
                            : "Caisse de l'agence, compte bancaire ou compte Mobile Money."
                    }}
                </p>
                <p
                    v-if="form.errors.nature"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ form.errors.nature }}
                </p>
            </div>

            <template v-if="form.nature === 'dediee'">
                <div>
                    <Label
                        for="sup-agent"
                        class="mb-1.5 block text-xs font-medium"
                    >
                        Agent <span class="text-destructive">*</span>
                    </Label>
                    <select
                        id="sup-agent"
                        v-model="form.agent_id"
                        :disabled="!form.site_id"
                        :class="selectClass"
                    >
                        <option value="" disabled>
                            {{
                                form.site_id
                                    ? 'Choisir un agent…'
                                    : "Choisissez d'abord l'agence"
                            }}
                        </option>
                        <option
                            v-for="a in agentsEligibles"
                            :key="a.id"
                            :value="a.id"
                        >
                            {{ a.nom }}
                        </option>
                    </select>
                    <p
                        v-if="form.site_id && agentsEligibles.length === 0"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        Aucun agent disponible : il doit être rattaché à cette
                        agence et ne pas déjà avoir de caisse dédiée active.
                    </p>
                    <p
                        v-if="form.errors.agent_id"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ form.errors.agent_id }}
                    </p>
                </div>
                <p
                    class="rounded-lg border bg-muted/30 p-3 text-xs text-muted-foreground"
                >
                    Type : Caisse. Le compte comptable est créé automatiquement.
                    La caisse démarre à 0 GNF : elle s'alimente par les
                    encaissements en espèces de l'agent ou par un transfert
                    depuis la caisse de l'agence.
                </p>
            </template>

            <template v-else>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <Label
                            for="sup-type"
                            class="mb-1.5 block text-xs font-medium"
                        >
                            Type <span class="text-destructive">*</span>
                        </Label>
                        <select
                            id="sup-type"
                            v-model="form.type"
                            :class="selectClass"
                        >
                            <option
                                v-for="t in type_options"
                                :key="t.value"
                                :value="t.value"
                            >
                                {{ t.label }}
                            </option>
                        </select>
                        <p
                            v-if="form.errors.type"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ form.errors.type }}
                        </p>
                    </div>
                    <div>
                        <Label
                            for="sup-compte"
                            class="mb-1.5 block text-xs font-medium"
                        >
                            Compte comptable
                            <span class="text-destructive">*</span>
                        </Label>
                        <select
                            id="sup-compte"
                            v-model="form.compte_comptable_id"
                            :class="selectClass"
                        >
                            <option value="" disabled>Compte…</option>
                            <option
                                v-for="c in comptesFiltres"
                                :key="c.id"
                                :value="c.id"
                            >
                                {{ c.numero }} — {{ c.libelle }}
                            </option>
                        </select>
                        <p
                            v-if="form.errors.compte_comptable_id"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ form.errors.compte_comptable_id }}
                        </p>
                    </div>
                </div>
            </template>

            <div
                v-if="form.nature !== 'dediee' && form.type === 'mobile_money'"
            >
                <Label
                    for="sup-operateur"
                    class="mb-1.5 block text-xs font-medium"
                >
                    Opérateur <span class="text-destructive">*</span>
                </Label>
                <select
                    id="sup-operateur"
                    v-model="form.operateur_mobile_money"
                    :class="selectClass"
                >
                    <option value="" disabled>Opérateur…</option>
                    <option
                        v-for="o in operateur_options"
                        :key="o.value"
                        :value="o.value"
                    >
                        {{ o.label }}
                    </option>
                </select>
                <p class="mt-1 text-xs text-muted-foreground">
                    Chaque opérateur a son propre compte : ce support le rend
                    proposable à l'encaissement dans cette agence.
                </p>
                <p
                    v-if="form.errors.operateur_mobile_money"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ form.errors.operateur_mobile_money }}
                </p>
            </div>

            <div>
                <Label
                    for="sup-libelle"
                    class="mb-1.5 block text-xs font-medium"
                    >Nom de la caisse (optionnel)</Label
                >
                <Input
                    id="sup-libelle"
                    v-model="form.libelle"
                    :placeholder="libellePreview"
                    :class="{ 'border-destructive': form.errors.libelle }"
                />
                <p
                    v-if="form.errors.libelle"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ form.errors.libelle }}
                </p>
            </div>

            <div class="flex justify-between pt-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="createOpen = false"
                    >Annuler</Button
                >
                <Button type="submit" size="sm" :disabled="form.processing">
                    {{ form.processing ? 'Création…' : 'Créer la caisse' }}
                </Button>
            </div>
        </form>
    </Dialog>

    <!-- Modification -->
    <Dialog
        v-model:visible="editVisible"
        modal
        header="Modifier le support"
        :style="{ width: 'min(480px, 95vw)' }"
        :dismissable-mask="true"
    >
        <form
            v-if="editDialogPour"
            class="space-y-4 pt-2 pb-1"
            @submit.prevent="enregistrerEdition"
        >
            <div>
                <Label
                    for="edit-libelle"
                    class="mb-1.5 block text-xs font-medium"
                    >Libellé</Label
                >
                <Input
                    id="edit-libelle"
                    v-model="editForm.libelle"
                    :class="{ 'border-destructive': editForm.errors.libelle }"
                />
                <p
                    v-if="editForm.errors.libelle"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ editForm.errors.libelle }}
                </p>
            </div>

            <template v-if="editEstDediee">
                <p
                    class="rounded-lg border bg-muted/30 p-3 text-xs text-muted-foreground"
                >
                    Caisse dédiée à
                    <span class="font-medium text-foreground">{{
                        editDialogPour.agent?.nom
                    }}</span>
                    · {{ editDialogPour.site }} · compte
                    {{ editDialogPour.compte_numero }}. L'agent, le type et le
                    compte ne peuvent pas être modifiés.
                </p>
            </template>
            <template v-else>
                <div>
                    <Label
                        for="edit-type"
                        class="mb-1.5 block text-xs font-medium"
                        >Type</Label
                    >
                    <select
                        id="edit-type"
                        v-model="editForm.type"
                        :disabled="editionTypeCompteVerrouillee"
                        :class="selectClass"
                    >
                        <option
                            v-for="t in type_options"
                            :key="t.value"
                            :value="t.value"
                        >
                            {{ t.label }}
                        </option>
                    </select>
                    <p
                        v-if="editForm.errors.type"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ editForm.errors.type }}
                    </p>
                </div>
                <div>
                    <Label
                        for="edit-compte"
                        class="mb-1.5 block text-xs font-medium"
                        >Compte comptable</Label
                    >
                    <select
                        id="edit-compte"
                        v-model="editForm.compte_comptable_id"
                        :disabled="editionTypeCompteVerrouillee"
                        :class="selectClass"
                    >
                        <option
                            v-for="c in comptesFiltresEdition"
                            :key="c.id"
                            :value="c.id"
                        >
                            {{ c.numero }} — {{ c.libelle }}
                        </option>
                    </select>
                    <p
                        v-if="editForm.errors.compte_comptable_id"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ editForm.errors.compte_comptable_id }}
                    </p>
                    <p
                        v-if="editionTypeCompteVerrouillee"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        Verrouillé : un solde d'ouverture existe déjà pour ce
                        support.
                    </p>
                </div>
                <div v-if="editForm.type === 'mobile_money'">
                    <Label
                        for="edit-operateur"
                        class="mb-1.5 block text-xs font-medium"
                        >Opérateur</Label
                    >
                    <select
                        id="edit-operateur"
                        v-model="editForm.operateur_mobile_money"
                        :class="selectClass"
                    >
                        <option value="" disabled>Opérateur…</option>
                        <option
                            v-for="o in operateur_options"
                            :key="o.value"
                            :value="o.value"
                        >
                            {{ o.label }}
                        </option>
                    </select>
                    <p
                        v-if="editForm.errors.operateur_mobile_money"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ editForm.errors.operateur_mobile_money }}
                    </p>
                </div>
            </template>

            <div
                v-if="editDialogPour.nature === 'agence'"
                class="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-lg border bg-muted/30 p-3 text-xs text-muted-foreground"
                data-testid="edit-solde-ouverture"
            >
                <span>Solde d'ouverture :</span>
                <template v-if="editDialogPour.solde_ouverture">
                    <span class="font-medium text-foreground tabular-nums">{{
                        formatGNF(editDialogPour.solde_ouverture.montant)
                    }}</span>
                    <StatusDot
                        :status="editDialogPour.solde_ouverture.statut"
                        :label="
                            soldeStatutLabels[
                                editDialogPour.solde_ouverture.statut
                            ] ?? editDialogPour.solde_ouverture.statut
                        "
                    />
                </template>
                <span v-else-if="editDialogPour.statut === 'brouillon'"
                    >saisissable après la validation du support</span
                >
                <span v-else>non saisi</span>
            </div>

            <p
                v-if="editDialogPour.statut === 'brouillon'"
                class="rounded-lg border bg-muted/30 p-3 text-xs text-muted-foreground"
                data-testid="edit-brouillon"
            >
                Ce support est en brouillon : il devra être validé avant d'être
                utilisable.
            </p>
            <p
                v-else-if="editDialogPour.valide_par"
                class="text-xs text-muted-foreground"
                data-testid="edit-validation"
            >
                Validé le {{ dateFr(editDialogPour.valide_le) }} par
                {{ editDialogPour.valide_par }}.
            </p>

            <div v-if="editDialogPour.statut !== 'brouillon'">
                <label class="flex items-center gap-2 text-sm">
                    <input
                        v-model="editForm.actif"
                        type="checkbox"
                        class="h-4 w-4 rounded border-input"
                    />
                    Actif
                </label>
                <p
                    v-if="
                        editEstDediee &&
                        editDialogPour.actif &&
                        editDialogPour.solde !== 0
                    "
                    class="mt-1 text-xs text-muted-foreground"
                >
                    Cette caisse détient {{ formatGNF(editDialogPour.solde) }} :
                    son solde doit être versé à la caisse de l'agence avant de
                    pouvoir la désactiver.
                </p>
                <p
                    v-if="editForm.errors.actif"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ editForm.errors.actif }}
                </p>
            </div>

            <div class="flex justify-between pt-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="editDialogPour = null"
                    >Annuler</Button
                >
                <Button type="submit" size="sm" :disabled="editForm.processing">
                    Enregistrer
                </Button>
            </div>
        </form>
    </Dialog>

    <!-- Solde d'ouverture -->
    <Dialog
        v-model:visible="soldeVisible"
        modal
        header="Solde d'ouverture"
        :style="{ width: 'min(400px, 95vw)' }"
        :dismissable-mask="true"
    >
        <form class="space-y-4 pt-2 pb-1" @submit.prevent="enregistrerSolde">
            <div>
                <Label for="solde-date" class="mb-1.5 block text-xs font-medium"
                    >Date de situation</Label
                >
                <Input
                    id="solde-date"
                    v-model="soldeForm.date_situation"
                    type="date"
                />
                <p
                    v-if="soldeForm.errors.date_situation"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ soldeForm.errors.date_situation }}
                </p>
            </div>
            <div>
                <Label
                    for="solde-montant"
                    class="mb-1.5 block text-xs font-medium"
                    >Montant (GNF)</Label
                >
                <input
                    id="solde-montant"
                    :value="soldeMontantDisplay"
                    type="text"
                    inputmode="numeric"
                    placeholder="0"
                    class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm tabular-nums shadow-sm"
                    @input="handleSoldeMontantInput"
                />
                <p
                    v-if="soldeForm.errors.montant"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ soldeForm.errors.montant }}
                </p>
                <p
                    v-if="soldeForm.errors.compte_tresorerie_id"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ soldeForm.errors.compte_tresorerie_id }}
                </p>
            </div>
            <div class="flex justify-between pt-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="soldeDialogPourId = null"
                    >Annuler</Button
                >
                <Button
                    type="submit"
                    size="sm"
                    :disabled="soldeForm.processing"
                >
                    Enregistrer
                </Button>
            </div>
        </form>
    </Dialog>
</template>
