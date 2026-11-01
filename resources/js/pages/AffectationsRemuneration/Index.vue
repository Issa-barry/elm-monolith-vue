<script setup lang="ts">
import AuditDrawer from '@/components/AuditDrawer.vue';
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import MontantInput from '@/components/MontantInput.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFlashToast } from '@/composables/useFlashToast';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    BadgeCheck,
    CalendarClock,
    CalendarX,
    Gift,
    History,
    ListOrdered,
    Loader2,
    MoreHorizontal,
    Pencil,
    Plus,
    Undo2,
    XCircle,
} from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Dialog from 'primevue/dialog';
import { useToast } from 'primevue/usetoast';
import { computed, ref } from 'vue';

interface Option {
    value: string;
    label: string;
}

interface EmployeOption extends Option {
    site: string | null;
    date_embauche: string | null;
    date_sortie: string | null;
    attribuable: boolean;
}

interface TypeOption extends Option {
    code: string;
    categorie: string;
    categorie_label: string;
    valorise: boolean;
    mode_calcul: string;
    periodicite: string;
    nature_montant: string;
    cumul_autorise: boolean;
    montant_defaut: number | null;
    taux_defaut: number | null;
    date_debut_application: string | null;
    date_fin_application: string | null;
    statut: string;
    attribuable: boolean;
}

interface Saisie {
    id: string;
    annee: number;
    mois: number;
    montant: number | null;
    taux: number | null;
    commentaire: string | null;
    statut: 'a_valider' | 'validee';
    statut_label: string;
}

interface Affectation {
    id: string;
    employe: {
        id: string;
        nom_complet: string;
        matricule: string | null;
        site: string | null;
    };
    type: {
        id: string;
        code: string;
        libelle: string;
        categorie: string;
        categorie_label: string;
        valorise: boolean;
        mode_calcul: string;
        periodicite: string;
        periodicite_label: string;
        nature_montant: string;
        proratisable: boolean;
        statut: string;
    };
    date_debut: string;
    date_fin: string | null;
    montant: number | null;
    taux: number | null;
    valeur_label: string;
    commentaire: string | null;
    statut: 'a_valider' | 'validee' | 'annulee';
    etat: string;
    etat_label: string;
    attend_validation: boolean;
    remplace_affectation_id: string | null;
    est_changement: boolean;
    successeur_statut: string | null;
    demande_fin: {
        date: string;
        motif: string | null;
        par: string | null;
    } | null;
    motif_fin: string | null;
    motif_annulation: string | null;
    est_transmise: boolean;
    peut_agir: boolean;
    saisies: Saisie[];
    cree_par: string | null;
    valide_par: string | null;
    valide_le: string | null;
    created_at: string | null;
}

const props = defineProps<{
    affectations: Affectation[];
    filters: Record<string, unknown>;
    nb_a_valider: number;
    premier_jour_modifiable: string | null;
    sites: { id: string; nom: string }[];
    employes: EmployeOption[];
    types: TypeOption[];
    etats: Option[];
}>();

useFlashToast();
const toast = useToast();
const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    {
        title: 'Primes des salariés',
        href: '/backoffice/affectations-remuneration',
    },
];

const BASE = '/backoffice/affectations-remuneration';

// ── Filtres (serveur) ─────────────────────────────────────────────────────────

const filterFields = computed((): FilterField[] => [
    {
        key: 'etat',
        label: 'Statut',
        type: 'select',
        options: props.etats,
        inline: true,
    },
    {
        key: 'employe_id',
        label: 'Salarié',
        type: 'select',
        options: props.employes.map((e) => ({
            value: e.value,
            label: e.label,
        })),
        searchable: true,
        inline: true,
    },
    {
        key: 'type_remuneration_id',
        label: 'Type',
        type: 'select',
        options: props.types.map((t) => ({ value: t.value, label: t.label })),
        searchable: true,
    },
    {
        key: 'periode',
        label: 'En vigueur sur la période',
        type: 'date-range',
        startKey: 'periode_du',
        endKey: 'periode_au',
    },
]);

// ── Droits (le serveur revérifie chaque action) ──────────────────────────────

const peutValider = computed(() => can('rh-affectations-remuneration.valider'));

function enCours(a: Affectation): boolean {
    return a.statut === 'validee' && a.etat !== 'terminee';
}

function peutModifier(a: Affectation): boolean {
    return (
        a.peut_agir &&
        a.statut === 'a_valider' &&
        can('rh-affectations-remuneration.update')
    );
}

function peutValiderLigne(a: Affectation): boolean {
    return a.peut_agir && a.attend_validation && peutValider.value;
}

function sansDemandeEnCours(a: Affectation): boolean {
    return !a.demande_fin && a.successeur_statut === null;
}

function peutChangerMontant(a: Affectation): boolean {
    return (
        a.peut_agir &&
        enCours(a) &&
        sansDemandeEnCours(a) &&
        a.type.periodicite !== 'ponctuelle' &&
        a.type.nature_montant === 'fixe' &&
        a.type.statut !== 'inactif' &&
        can('rh-affectations-remuneration.create')
    );
}

function peutMettreFin(a: Affectation): boolean {
    return (
        a.peut_agir &&
        enCours(a) &&
        sansDemandeEnCours(a) &&
        a.type.periodicite !== 'ponctuelle' &&
        can('rh-affectations-remuneration.update')
    );
}

function peutRetirerFin(a: Affectation): boolean {
    return (
        a.peut_agir &&
        a.demande_fin !== null &&
        can('rh-affectations-remuneration.update')
    );
}

function peutSaisir(a: Affectation): boolean {
    return (
        a.peut_agir &&
        a.statut === 'validee' &&
        a.type.nature_montant === 'variable' &&
        can('rh-affectations-remuneration.create')
    );
}

function peutAnnuler(a: Affectation): boolean {
    if (!a.peut_agir || !can('rh-affectations-remuneration.delete')) {
        return false;
    }
    if (a.statut === 'a_valider') return true;
    return (
        a.statut === 'validee' &&
        peutValider.value &&
        !a.est_transmise &&
        a.successeur_statut === null
    );
}

function libelleValidation(a: Affectation): string {
    if (a.demande_fin) return 'Valider la fin d’effet';
    if (a.est_changement) return 'Valider le changement de montant';
    return 'Valider l’affectation';
}

// ── Affichage ────────────────────────────────────────────────────────────────

const formatGnf = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 });

function formatDate(iso: string | null): string {
    if (!iso) return '';
    const [y, m, d] = iso.split('-');
    return `${d}/${m}/${y}`;
}

function periode(a: Affectation): string {
    if (a.type.periodicite === 'ponctuelle') {
        const [y, m] = a.date_debut.split('-');
        return `Versée en ${m}/${y}`;
    }
    return a.date_fin
        ? `Du ${formatDate(a.date_debut)} au ${formatDate(a.date_fin)}`
        : `À partir du ${formatDate(a.date_debut)}`;
}

const MOIS = [
    'janvier',
    'février',
    'mars',
    'avril',
    'mai',
    'juin',
    'juillet',
    'août',
    'septembre',
    'octobre',
    'novembre',
    'décembre',
];

function libelleMois(annee: number, mois: number): string {
    return `${MOIS[mois - 1]} ${annee}`;
}

/** Mois qui précède le premier jour modifiable (dernier mois figé). */
const dernierMoisFige = computed(() => {
    if (!props.premier_jour_modifiable) return '';
    const [y, m] = props.premier_jour_modifiable.split('-').map(Number);
    return m === 1 ? libelleMois(y - 1, 12) : libelleMois(y, m - 1);
});

function valeurSaisie(a: Affectation, s: Saisie): string {
    return a.type.mode_calcul === 'pourcentage_salaire_base'
        ? `${(s.taux ?? 0).toLocaleString('fr-FR')} %`
        : `${formatGnf.format(s.montant ?? 0)} GNF`;
}

function nombreOuNull(valeur: string | number | null): number | null {
    return valeur === '' || valeur === null ? null : Number(valeur);
}

function premiereErreur(errors: Record<string, string>): string {
    return Object.values(errors)[0] ?? 'Action refusée.';
}

function toastErreur(errors: Record<string, string>) {
    toast.add({
        group: 'top',
        severity: 'error',
        summary: 'Action refusée',
        detail: premiereErreur(errors),
        life: 6000,
    });
}

// ── Attribuer / modifier une proposition ─────────────────────────────────────

const showForm = ref(false);
const enEdition = ref<Affectation | null>(null);

const form = useForm({
    employe_id: '',
    type_remuneration_id: '',
    date_debut: '',
    date_fin: '',
    mois_versement: '',
    montant: '' as string | number | null,
    taux: '' as string | number,
    commentaire: '',
    valider: false,
});

const typesAttribuables = computed(() =>
    props.types.filter((t) => t.attribuable),
);
const employesAttribuables = computed(() =>
    props.employes.filter((e) => e.attribuable),
);

const typeChoisi = computed((): TypeOption | undefined => {
    const id = enEdition.value?.type.id ?? form.type_remuneration_id;
    return props.types.find((t) => t.value === id);
});

const typePonctuel = computed(
    () => typeChoisi.value?.periodicite === 'ponctuelle',
);
const typeVariable = computed(
    () => typeChoisi.value?.nature_montant === 'variable',
);
const typePourcentage = computed(
    () => typeChoisi.value?.mode_calcul === 'pourcentage_salaire_base',
);

function choisirType(id: string) {
    form.type_remuneration_id = id;
    const t = props.types.find((x) => x.value === id);
    // Valeur par défaut du type : proposée, toujours modifiable, jamais imposée.
    form.montant = t?.montant_defaut ?? '';
    form.taux = t?.taux_defaut ?? '';
    if (t?.date_fin_application && !form.date_fin) {
        form.date_fin = t.date_fin_application;
    }
}

function ouvrirAttribution() {
    enEdition.value = null;
    form.reset();
    form.clearErrors();
    showForm.value = true;
}

function ouvrirModification(a: Affectation) {
    enEdition.value = a;
    form.clearErrors();
    form.employe_id = a.employe.id;
    form.type_remuneration_id = a.type.id;
    form.date_debut = a.date_debut;
    form.date_fin = a.date_fin ?? '';
    form.mois_versement = a.date_debut.slice(0, 7);
    form.montant = a.montant ?? '';
    form.taux = a.taux ?? '';
    form.commentaire = a.commentaire ?? '';
    form.valider = false;
    showForm.value = true;
}

const erreurGenerale = computed(() => {
    const champs = [
        'employe_id',
        'type_remuneration_id',
        'date_debut',
        'date_fin',
        'montant',
        'taux',
        'commentaire',
    ];
    const autres = Object.entries(form.errors).filter(
        ([k]) => !champs.includes(k),
    );
    return autres.length > 0 ? (autres[0][1] as string) : '';
});

function enregistrer(valider: boolean) {
    form.valider = valider;
    form.transform((data) => {
        const payload: Record<string, unknown> = {
            date_debut: typePonctuel.value
                ? `${data.mois_versement}-01`
                : data.date_debut,
            date_fin: typePonctuel.value ? null : data.date_fin || null,
            montant:
                typeVariable.value || typePourcentage.value
                    ? null
                    : nombreOuNull(data.montant),
            taux:
                !typeVariable.value && typePourcentage.value
                    ? nombreOuNull(data.taux)
                    : null,
            commentaire: data.commentaire.trim() || null,
            valider: data.valider,
        };
        if (!enEdition.value) {
            payload.employe_id = data.employe_id;
            payload.type_remuneration_id = data.type_remuneration_id;
        }
        return payload;
    });
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            showForm.value = false;
            form.reset();
        },
    };
    if (enEdition.value) {
        form.put(`${BASE}/${enEdition.value.id}`, options);
    } else {
        form.post(BASE, options);
    }
}

// ── Changer le montant ───────────────────────────────────────────────────────

const changement = ref<Affectation | null>(null);
const formChangement = useForm({
    date_effet: '',
    date_fin: '',
    montant: '' as string | number | null,
    taux: '' as string | number,
    commentaire: '',
    valider: false,
});

function ouvrirChangement(a: Affectation) {
    changement.value = a;
    formChangement.reset();
    formChangement.clearErrors();
    formChangement.date_fin = a.date_fin ?? '';
    formChangement.montant = a.montant ?? '';
    formChangement.taux = a.taux ?? '';
}

function enregistrerChangement(valider: boolean) {
    const a = changement.value;
    if (!a) return;
    const pourcentage = a.type.mode_calcul === 'pourcentage_salaire_base';
    formChangement.valider = valider;
    formChangement
        .transform((data) => ({
            date_effet: data.date_effet,
            date_fin: data.date_fin || null,
            montant: pourcentage ? null : nombreOuNull(data.montant),
            taux: pourcentage ? nombreOuNull(data.taux) : null,
            commentaire: data.commentaire.trim() || null,
            valider: data.valider,
        }))
        .post(`${BASE}/${a.id}/changement-montant`, {
            preserveScroll: true,
            onSuccess: () => {
                changement.value = null;
            },
        });
}

// Refus du serveur sur l'affectation elle-même (demande déjà en cours…).
const erreurChangement = computed(
    () => (formChangement.errors as Record<string, string>).affectation ?? '',
);

// ── Mettre fin ───────────────────────────────────────────────────────────────

const fin = ref<Affectation | null>(null);
const formFin = useForm({ date_fin: '', motif: '', valider: false });

const erreurFin = computed(
    () => (formFin.errors as Record<string, string>).affectation ?? '',
);

function ouvrirFin(a: Affectation) {
    fin.value = a;
    formFin.reset();
    formFin.clearErrors();
}

function enregistrerFin(valider: boolean) {
    const a = fin.value;
    if (!a) return;
    formFin.valider = valider;
    formFin.post(`${BASE}/${a.id}/fin`, {
        preserveScroll: true,
        onSuccess: () => {
            fin.value = null;
        },
    });
}

// ── Montants mensuels ────────────────────────────────────────────────────────

const saisiesDe = ref<Affectation | null>(null);
const formSaisie = useForm({
    mois: '',
    montant: '' as string | number | null,
    taux: '' as string | number,
    commentaire: '',
    valider: false,
});

const affectationSaisies = computed(
    () => props.affectations.find((a) => a.id === saisiesDe.value?.id) ?? null,
);

function ouvrirSaisies(a: Affectation) {
    saisiesDe.value = a;
    formSaisie.reset();
    formSaisie.clearErrors();
}

function enregistrerSaisie(valider: boolean) {
    const a = saisiesDe.value;
    if (!a) return;
    const pourcentage = a.type.mode_calcul === 'pourcentage_salaire_base';
    formSaisie.valider = valider;
    formSaisie
        .transform((data) => {
            const [annee, mois] = data.mois.split('-');
            return {
                annee: Number(annee),
                mois: Number(mois),
                montant: pourcentage ? null : nombreOuNull(data.montant),
                taux: pourcentage ? nombreOuNull(data.taux) : null,
                commentaire: data.commentaire.trim() || null,
                valider: data.valider,
            };
        })
        .post(`${BASE}/${a.id}/saisies`, {
            preserveScroll: true,
            onSuccess: () => {
                formSaisie.reset();
            },
        });
}

function corrigerSaisie(s: Saisie) {
    formSaisie.mois = `${s.annee}-${String(s.mois).padStart(2, '0')}`;
    formSaisie.montant = s.montant ?? '';
    formSaisie.taux = s.taux ?? '';
    formSaisie.commentaire = s.commentaire ?? '';
}

// Même règle que le serveur : droit de modification, plus le droit de validation si le montant est validé.
function peutSupprimerSaisie(s: Saisie): boolean {
    return (
        can('rh-affectations-remuneration.update') &&
        (s.statut === 'a_valider' || peutValider.value)
    );
}

const saisieEnCours = ref<string | null>(null);

function actionSaisie(s: Saisie, action: 'valider' | 'supprimer') {
    saisieEnCours.value = s.id;
    const options = {
        preserveScroll: true,
        onError: toastErreur,
        onFinish: () => {
            saisieEnCours.value = null;
        },
    };
    if (action === 'valider') {
        router.patch(`${BASE}/saisies/${s.id}/valider`, {}, options);
    } else {
        router.delete(`${BASE}/saisies/${s.id}`, options);
    }
}

// ── Confirmations : valider, annuler, retirer une demande de fin ──────────────

type ActionConfirmee = 'valider' | 'annuler' | 'retirer_fin';

const confirmation = ref<{
    affectation: Affectation;
    action: ActionConfirmee;
} | null>(null);
const motifAnnulation = ref('');
const confirmationEnCours = ref(false);

function demander(affectation: Affectation, action: ActionConfirmee) {
    motifAnnulation.value = '';
    confirmation.value = { affectation, action };
}

function fermerConfirmation(visible: boolean) {
    if (!visible && !confirmationEnCours.value) confirmation.value = null;
}

const titreConfirmation = computed(() => {
    const c = confirmation.value;
    if (!c) return '';
    if (c.action === 'valider') return libelleValidation(c.affectation);
    if (c.action === 'annuler') return 'Annuler l’affectation';
    return 'Retirer la demande de fin d’effet';
});

const texteConfirmation = computed(() => {
    const c = confirmation.value;
    if (!c) return '';
    const a = c.affectation;
    if (c.action === 'valider') {
        if (a.demande_fin) {
            return `« ${a.type.libelle} » de ${a.employe.nom_complet} s’arrêtera le ${formatDate(a.demande_fin.date)}.`;
        }
        if (a.est_changement) {
            return `Le nouveau montant (${a.valeur_label}) s’appliquera à partir du ${formatDate(a.date_debut)} ; le montant précédent s’arrêtera la veille.`;
        }
        return `« ${a.type.libelle} » (${a.valeur_label}) sera attribué à ${a.employe.nom_complet} : ${periode(a).toLowerCase()}.`;
    }
    if (c.action === 'annuler') {
        return 'L’affectation restera visible dans l’historique, avec le statut « Annulée ». Elle ne produira aucun montant.';
    }
    return 'L’affectation continue sans changement de date de fin.';
});

function confirmer() {
    const c = confirmation.value;
    if (!c || confirmationEnCours.value) return;
    confirmationEnCours.value = true;
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            confirmation.value = null;
        },
        onError: (errors: Record<string, string>) => {
            confirmation.value = null;
            toastErreur(errors);
        },
        onFinish: () => {
            confirmationEnCours.value = false;
        },
    };
    const url = `${BASE}/${c.affectation.id}`;
    if (c.action === 'valider') {
        router.patch(`${url}/valider`, {}, options);
    } else if (c.action === 'annuler') {
        router.patch(
            `${url}/annuler`,
            { motif: motifAnnulation.value.trim() || null },
            options,
        );
    } else {
        router.delete(`${url}/fin`, options);
    }
}

// ── Historique ───────────────────────────────────────────────────────────────

const historique = ref<Affectation | null>(null);
const historiqueVisible = ref(false);

function ouvrirHistorique(a: Affectation) {
    historique.value = a;
    historiqueVisible.value = true;
}

const champSelect =
    'flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Primes des salariés" />

        <div class="w-full space-y-5 p-4 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-xl font-semibold">Primes des salariés</h1>
                    <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
                        Primes, indemnités, allocations et avantages en nature
                        attribués à chaque salarié, avec leurs dates d’effet. Le
                        salaire de base reste porté par le contrat.
                    </p>
                </div>
                <Button
                    v-if="can('rh-affectations-remuneration.create')"
                    size="sm"
                    data-testid="affectation-attribuer"
                    @click="ouvrirAttribution"
                >
                    <Plus class="mr-1.5 h-3.5 w-3.5" />
                    Attribuer
                </Button>
            </div>

            <DataFilters
                :url="BASE"
                :values="filters"
                :fields="filterFields"
                :sites="sites"
                :result-count="affectations.length"
            />

            <div
                v-if="nb_a_valider > 0"
                class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
            >
                {{ nb_a_valider }}
                {{
                    nb_a_valider > 1
                        ? 'éléments attendent une validation'
                        : 'élément attend une validation'
                }}
                (affectation, changement de montant ou fin d’effet) : tant
                qu’ils ne sont pas validés, ils ne produisent aucun montant et
                bloquent la transmission du mois concerné.
            </div>
            <p
                v-if="premier_jour_modifiable"
                class="rounded-lg bg-muted/50 px-4 py-2 text-xs text-muted-foreground"
            >
                La paie est figée jusqu’à fin {{ dernierMoisFige }} : toute
                nouvelle date d’effet commence au plus tôt le
                {{ formatDate(premier_jour_modifiable) }}.
            </p>

            <div class="overflow-hidden rounded-xl border bg-card">
                <DataTable
                    :value="affectations"
                    :paginator="affectations.length > 25"
                    :rows="25"
                    data-key="id"
                    striped-rows
                    class="text-sm"
                    :pt="{ root: { class: 'w-full' } }"
                >
                    <Column header="Salarié" style="min-width: 200px">
                        <template #body="{ data }">
                            <p class="font-medium">
                                {{ data.employe.nom_complet }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ data.employe.matricule ?? '—' }}
                                <template v-if="data.employe.site">
                                    · {{ data.employe.site }}</template
                                >
                            </p>
                        </template>
                    </Column>

                    <Column header="Prime ou avantage" style="min-width: 220px">
                        <template #body="{ data }">
                            <p class="flex flex-wrap items-center gap-2">
                                {{ data.type.libelle }}
                                <span
                                    v-if="data.type.valorise"
                                    class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-700 dark:bg-amber-900 dark:text-amber-300"
                                    >Avantage en nature · hors net à payer</span
                                >
                            </p>
                            <p
                                v-if="data.est_changement"
                                class="text-[11px] text-muted-foreground"
                            >
                                Changement de montant
                            </p>
                        </template>
                    </Column>

                    <Column header="Période d’effet" style="min-width: 190px">
                        <template #body="{ data }">
                            <p>{{ periode(data) }}</p>
                            <p
                                v-if="data.demande_fin"
                                class="text-xs text-amber-700 dark:text-amber-300"
                            >
                                Fin demandée au
                                {{ formatDate(data.demande_fin.date) }}
                            </p>
                            <p
                                v-else-if="data.motif_fin"
                                class="text-xs text-muted-foreground"
                            >
                                {{ data.motif_fin }}
                            </p>
                        </template>
                    </Column>

                    <Column header="Montant attribué" style="min-width: 160px">
                        <template #body="{ data }">
                            <p class="font-medium tabular-nums">
                                {{ data.valeur_label }}
                            </p>
                            <p
                                v-if="data.type.nature_montant === 'variable'"
                                class="text-xs text-muted-foreground"
                            >
                                {{ data.saisies.length }} mois saisi(s)
                            </p>
                        </template>
                    </Column>

                    <Column header="Statut" style="width: 170px">
                        <template #body="{ data }">
                            <StatusDot
                                :status="
                                    data.attend_validation
                                        ? 'a_valider'
                                        : data.etat
                                "
                                :label="
                                    data.attend_validation &&
                                    data.statut === 'validee'
                                        ? 'Fin à valider'
                                        : data.etat_label
                                "
                            />
                            <p
                                v-if="data.valide_par"
                                class="mt-0.5 text-[11px] text-muted-foreground"
                            >
                                Validée par {{ data.valide_par }}
                            </p>
                            <p
                                v-else-if="data.cree_par"
                                class="mt-0.5 text-[11px] text-muted-foreground"
                            >
                                Proposée par {{ data.cree_par }}
                            </p>
                        </template>
                    </Column>

                    <Column header="" style="width: 56px">
                        <template #body="{ data }">
                            <div class="flex justify-end">
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="h-8 w-8"
                                            :data-testid="`affectation-actions-${data.id}`"
                                        >
                                            <MoreHorizontal class="h-4 w-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent
                                        align="end"
                                        class="w-64"
                                    >
                                        <DropdownMenuLabel>{{
                                            data.type.libelle
                                        }}</DropdownMenuLabel>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                            @click="ouvrirHistorique(data)"
                                        >
                                            <History class="mr-2 h-4 w-4" />
                                            Historique
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            v-if="peutValiderLigne(data)"
                                            data-testid="affectation-valider"
                                            @click="demander(data, 'valider')"
                                        >
                                            <BadgeCheck class="mr-2 h-4 w-4" />
                                            {{ libelleValidation(data) }}
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            v-if="peutModifier(data)"
                                            @click="ouvrirModification(data)"
                                        >
                                            <Pencil class="mr-2 h-4 w-4" />
                                            Modifier la proposition
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            v-if="peutChangerMontant(data)"
                                            @click="ouvrirChangement(data)"
                                        >
                                            <CalendarClock
                                                class="mr-2 h-4 w-4"
                                            />
                                            Changer le montant
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            v-if="peutSaisir(data)"
                                            @click="ouvrirSaisies(data)"
                                        >
                                            <ListOrdered class="mr-2 h-4 w-4" />
                                            Montants mensuels
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            v-if="peutMettreFin(data)"
                                            @click="ouvrirFin(data)"
                                        >
                                            <CalendarX class="mr-2 h-4 w-4" />
                                            Mettre fin
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            v-if="peutRetirerFin(data)"
                                            @click="
                                                demander(data, 'retirer_fin')
                                            "
                                        >
                                            <Undo2 class="mr-2 h-4 w-4" />
                                            Retirer la demande de fin
                                        </DropdownMenuItem>
                                        <template v-if="peutAnnuler(data)">
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem
                                                class="text-destructive focus:text-destructive"
                                                @click="
                                                    demander(data, 'annuler')
                                                "
                                            >
                                                <XCircle class="mr-2 h-4 w-4" />
                                                Annuler
                                            </DropdownMenuItem>
                                        </template>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </template>
                    </Column>

                    <template #empty>
                        <div
                            class="flex flex-col items-center gap-3 py-12 text-muted-foreground"
                        >
                            <Gift class="h-10 w-10 opacity-30" />
                            <p class="text-sm">
                                Aucune prime ni aucun avantage ne correspond.
                            </p>
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>
    </AppLayout>

    <!-- Attribuer / modifier une proposition -->
    <Dialog
        v-model:visible="showForm"
        modal
        :header="
            enEdition
                ? 'Modifier la proposition'
                : 'Attribuer une prime ou un avantage'
        "
        :style="{ width: 'min(620px, 95vw)' }"
        :draggable="false"
        :closable="!form.processing"
        @hide="form.clearErrors()"
    >
        <form class="space-y-4 pt-2 pb-1" @submit.prevent="enregistrer(false)">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <Label
                        for="af-employe"
                        class="mb-1.5 block text-xs font-medium"
                    >
                        Salarié <span class="text-destructive">*</span>
                    </Label>
                    <select
                        id="af-employe"
                        v-model="form.employe_id"
                        :class="champSelect"
                        :disabled="enEdition !== null"
                    >
                        <option value="" disabled>Choisir un salarié</option>
                        <option
                            v-for="e in enEdition
                                ? employes
                                : employesAttribuables"
                            :key="e.value"
                            :value="e.value"
                        >
                            {{ e.label }}{{ e.site ? ` — ${e.site}` : '' }}
                        </option>
                    </select>
                    <p
                        v-if="form.errors.employe_id"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ form.errors.employe_id }}
                    </p>
                </div>
                <div>
                    <Label
                        for="af-type"
                        class="mb-1.5 block text-xs font-medium"
                    >
                        Prime ou avantage
                        <span class="text-destructive">*</span>
                    </Label>
                    <select
                        id="af-type"
                        :value="form.type_remuneration_id"
                        :class="champSelect"
                        :disabled="enEdition !== null"
                        @change="
                            choisirType(
                                ($event.target as HTMLSelectElement).value,
                            )
                        "
                    >
                        <option value="" disabled>
                            Choisir un type validé
                        </option>
                        <option
                            v-for="t in enEdition ? types : typesAttribuables"
                            :key="t.value"
                            :value="t.value"
                        >
                            {{ t.label
                            }}{{ t.valorise ? ' (avantage en nature)' : '' }}
                        </option>
                    </select>
                    <p
                        v-if="form.errors.type_remuneration_id"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ form.errors.type_remuneration_id }}
                    </p>
                    <p
                        v-if="!enEdition && typesAttribuables.length === 0"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        Aucun type actif : un type doit être validé dans
                        Paramètres → Primes et avantages.
                    </p>
                </div>
            </div>

            <p
                v-if="typeChoisi?.valorise"
                class="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950/40 dark:text-amber-200"
            >
                Avantage en nature : valorisé (valeur estimée), jamais versé
                dans le net à payer.
            </p>

            <div v-if="typePonctuel">
                <Label for="af-mois" class="mb-1.5 block text-xs font-medium">
                    Mois de versement <span class="text-destructive">*</span>
                </Label>
                <Input
                    id="af-mois"
                    v-model="form.mois_versement"
                    type="month"
                />
                <p
                    v-if="form.errors.date_debut"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ form.errors.date_debut }}
                </p>
            </div>
            <div v-else class="grid gap-4 sm:grid-cols-2">
                <div>
                    <Label
                        for="af-debut"
                        class="mb-1.5 block text-xs font-medium"
                    >
                        Début d’effet <span class="text-destructive">*</span>
                    </Label>
                    <Input
                        id="af-debut"
                        v-model="form.date_debut"
                        type="date"
                    />
                    <p
                        v-if="form.errors.date_debut"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ form.errors.date_debut }}
                    </p>
                </div>
                <div>
                    <Label
                        for="af-fin"
                        class="mb-1.5 block text-xs font-medium"
                    >
                        Fin d’effet
                    </Label>
                    <Input id="af-fin" v-model="form.date_fin" type="date" />
                    <p
                        v-if="form.errors.date_fin"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ form.errors.date_fin }}
                    </p>
                    <p v-else class="mt-1 text-xs text-muted-foreground">
                        Vide : sans date de fin.
                    </p>
                </div>
            </div>

            <div v-if="typeChoisi">
                <p
                    v-if="typeVariable"
                    class="rounded-md bg-muted/50 px-3 py-2 text-xs text-muted-foreground"
                >
                    Montant saisi chaque mois : aucun montant permanent n’est
                    attribué. Après validation, saisissez le montant de chaque
                    mois (menu ⋮ → Montants mensuels).
                </p>
                <template v-else-if="typePourcentage">
                    <Label
                        for="af-taux"
                        class="mb-1.5 block text-xs font-medium"
                    >
                        Taux attribué (% du salaire de base)
                        <span class="text-destructive">*</span>
                    </Label>
                    <Input
                        id="af-taux"
                        v-model.number="form.taux"
                        type="number"
                        min="0"
                        max="100"
                        step="0.01"
                    />
                    <p
                        v-if="form.errors.taux"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ form.errors.taux }}
                    </p>
                </template>
                <template v-else>
                    <Label
                        for="af-montant"
                        class="mb-1.5 block text-xs font-medium"
                    >
                        Montant attribué (GNF, par mois)
                        <span class="text-destructive">*</span>
                    </Label>
                    <MontantInput
                        id="af-montant"
                        v-model="form.montant"
                        class="h-9"
                    />
                    <p
                        v-if="form.errors.montant"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ form.errors.montant }}
                    </p>
                    <p
                        v-else-if="typeChoisi.montant_defaut !== null"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        Valeur par défaut du type :
                        {{ formatGnf.format(typeChoisi.montant_defaut) }} GNF
                        (modifiable).
                    </p>
                </template>
            </div>

            <div>
                <Label
                    for="af-commentaire"
                    class="mb-1.5 block text-xs font-medium"
                >
                    Commentaire
                </Label>
                <textarea
                    id="af-commentaire"
                    v-model="form.commentaire"
                    rows="2"
                    class="flex min-h-[60px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                />
            </div>

            <p
                v-if="erreurGenerale"
                class="rounded-md bg-destructive/10 px-3 py-2 text-xs text-destructive"
            >
                {{ erreurGenerale }}
            </p>
            <p
                v-if="!peutValider"
                class="rounded-md bg-muted/50 px-3 py-2 text-xs text-muted-foreground"
            >
                L’affectation sera « À valider » : elle ne produira aucun
                montant tant qu’elle n’est pas validée.
            </p>

            <div class="flex flex-wrap justify-between gap-2 pt-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="form.processing"
                    @click="showForm = false"
                    >Annuler</Button
                >
                <div class="flex flex-wrap gap-2">
                    <Button
                        type="submit"
                        size="sm"
                        :variant="peutValider ? 'outline' : 'default'"
                        :disabled="form.processing"
                        data-testid="affectation-enregistrer"
                    >
                        <Loader2
                            v-if="form.processing && !form.valider"
                            class="mr-1.5 h-3.5 w-3.5 animate-spin"
                        />
                        Enregistrer
                    </Button>
                    <Button
                        v-if="peutValider"
                        type="button"
                        size="sm"
                        :disabled="form.processing"
                        data-testid="affectation-enregistrer-valider"
                        @click="enregistrer(true)"
                    >
                        <Loader2
                            v-if="form.processing && form.valider"
                            class="mr-1.5 h-3.5 w-3.5 animate-spin"
                        />
                        Enregistrer et valider
                    </Button>
                </div>
            </div>
        </form>
    </Dialog>

    <!-- Changer le montant -->
    <Dialog
        :visible="changement !== null"
        modal
        header="Changer le montant à partir d’une date"
        :style="{ width: 'min(520px, 95vw)' }"
        :draggable="false"
        :closable="!formChangement.processing"
        @update:visible="
            (v: boolean) => {
                if (!v && !formChangement.processing) changement = null;
            }
        "
    >
        <form
            v-if="changement"
            class="space-y-4 pt-1"
            @submit.prevent="enregistrerChangement(false)"
        >
            <p class="text-sm text-muted-foreground">
                {{ changement.employe.nom_complet }} —
                {{ changement.type.libelle }} : actuellement
                {{ changement.valeur_label }}. Le montant actuel reste appliqué
                jusqu’à la veille de la date d’effet ; les mois écoulés ne
                changent pas.
            </p>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <Label
                        for="ch-effet"
                        class="mb-1.5 block text-xs font-medium"
                    >
                        À partir du <span class="text-destructive">*</span>
                    </Label>
                    <Input
                        id="ch-effet"
                        v-model="formChangement.date_effet"
                        type="date"
                    />
                    <p
                        v-if="formChangement.errors.date_effet"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ formChangement.errors.date_effet }}
                    </p>
                </div>
                <div>
                    <Label
                        for="ch-fin"
                        class="mb-1.5 block text-xs font-medium"
                    >
                        Fin d’effet
                    </Label>
                    <Input
                        id="ch-fin"
                        v-model="formChangement.date_fin"
                        type="date"
                    />
                    <p
                        v-if="formChangement.errors.date_fin"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ formChangement.errors.date_fin }}
                    </p>
                </div>
            </div>
            <div
                v-if="
                    changement.type.mode_calcul === 'pourcentage_salaire_base'
                "
            >
                <Label for="ch-taux" class="mb-1.5 block text-xs font-medium">
                    Nouveau taux (% du salaire de base)
                    <span class="text-destructive">*</span>
                </Label>
                <Input
                    id="ch-taux"
                    v-model.number="formChangement.taux"
                    type="number"
                    min="0"
                    max="100"
                    step="0.01"
                />
                <p
                    v-if="formChangement.errors.taux"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ formChangement.errors.taux }}
                </p>
            </div>
            <div v-else>
                <Label
                    for="ch-montant"
                    class="mb-1.5 block text-xs font-medium"
                >
                    Nouveau montant (GNF, par mois)
                    <span class="text-destructive">*</span>
                </Label>
                <MontantInput
                    id="ch-montant"
                    v-model="formChangement.montant"
                    class="h-9"
                />
                <p
                    v-if="formChangement.errors.montant"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ formChangement.errors.montant }}
                </p>
            </div>
            <div>
                <Label
                    for="ch-commentaire"
                    class="mb-1.5 block text-xs font-medium"
                >
                    Commentaire
                </Label>
                <Input
                    id="ch-commentaire"
                    v-model="formChangement.commentaire"
                />
            </div>
            <p
                v-if="erreurChangement"
                class="rounded-md bg-destructive/10 px-3 py-2 text-xs text-destructive"
            >
                {{ erreurChangement }}
            </p>
            <div class="flex flex-wrap justify-end gap-2 pt-1">
                <Button
                    type="submit"
                    size="sm"
                    :variant="peutValider ? 'outline' : 'default'"
                    :disabled="formChangement.processing"
                >
                    <Loader2
                        v-if="
                            formChangement.processing && !formChangement.valider
                        "
                        class="mr-1.5 h-3.5 w-3.5 animate-spin"
                    />
                    Enregistrer
                </Button>
                <Button
                    v-if="peutValider"
                    type="button"
                    size="sm"
                    :disabled="formChangement.processing"
                    @click="enregistrerChangement(true)"
                >
                    <Loader2
                        v-if="
                            formChangement.processing && formChangement.valider
                        "
                        class="mr-1.5 h-3.5 w-3.5 animate-spin"
                    />
                    Enregistrer et valider
                </Button>
            </div>
        </form>
    </Dialog>

    <!-- Mettre fin -->
    <Dialog
        :visible="fin !== null"
        modal
        header="Mettre fin à l’affectation"
        :style="{ width: 'min(480px, 95vw)' }"
        :draggable="false"
        :closable="!formFin.processing"
        @update:visible="
            (v: boolean) => {
                if (!v && !formFin.processing) fin = null;
            }
        "
    >
        <form
            v-if="fin"
            class="space-y-4 pt-1"
            @submit.prevent="enregistrerFin(false)"
        >
            <p class="text-sm text-muted-foreground">
                {{ fin.employe.nom_complet }} — {{ fin.type.libelle }}. Le type
                reste attribuable aux autres salariés.
            </p>
            <div>
                <Label for="fin-date" class="mb-1.5 block text-xs font-medium">
                    Dernier jour d’effet <span class="text-destructive">*</span>
                </Label>
                <Input id="fin-date" v-model="formFin.date_fin" type="date" />
                <p
                    v-if="formFin.errors.date_fin"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ formFin.errors.date_fin }}
                </p>
            </div>
            <div>
                <Label for="fin-motif" class="mb-1.5 block text-xs font-medium">
                    Motif <span class="text-destructive">*</span>
                </Label>
                <Input
                    id="fin-motif"
                    v-model="formFin.motif"
                    placeholder="Ex : changement de poste"
                />
                <p
                    v-if="formFin.errors.motif"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ formFin.errors.motif }}
                </p>
            </div>
            <p
                v-if="erreurFin"
                class="rounded-md bg-destructive/10 px-3 py-2 text-xs text-destructive"
            >
                {{ erreurFin }}
            </p>
            <div class="flex flex-wrap justify-end gap-2 pt-1">
                <Button
                    type="submit"
                    size="sm"
                    :variant="peutValider ? 'outline' : 'default'"
                    :disabled="formFin.processing"
                >
                    <Loader2
                        v-if="formFin.processing && !formFin.valider"
                        class="mr-1.5 h-3.5 w-3.5 animate-spin"
                    />
                    Demander la fin
                </Button>
                <Button
                    v-if="peutValider"
                    type="button"
                    size="sm"
                    :disabled="formFin.processing"
                    @click="enregistrerFin(true)"
                >
                    <Loader2
                        v-if="formFin.processing && formFin.valider"
                        class="mr-1.5 h-3.5 w-3.5 animate-spin"
                    />
                    Mettre fin et valider
                </Button>
            </div>
        </form>
    </Dialog>

    <!-- Montants mensuels -->
    <Dialog
        :visible="saisiesDe !== null"
        modal
        header="Montants mensuels"
        :style="{ width: 'min(620px, 95vw)' }"
        :draggable="false"
        :closable="!formSaisie.processing"
        @update:visible="
            (v: boolean) => {
                if (!v && !formSaisie.processing) saisiesDe = null;
            }
        "
    >
        <div v-if="affectationSaisies" class="space-y-4 pt-1">
            <p class="text-sm text-muted-foreground">
                {{ affectationSaisies.employe.nom_complet }} —
                {{ affectationSaisies.type.libelle }} :
                {{ periode(affectationSaisies).toLowerCase() }}. Un mois sans
                montant validé n’est pas transmis.
            </p>

            <div class="overflow-hidden rounded-lg border">
                <p
                    v-if="affectationSaisies.saisies.length === 0"
                    class="p-4 text-center text-sm text-muted-foreground"
                >
                    Aucun montant saisi.
                </p>
                <div v-else class="divide-y">
                    <div
                        v-for="s in affectationSaisies.saisies"
                        :key="s.id"
                        class="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                    >
                        <div>
                            <p class="font-medium capitalize">
                                {{ libelleMois(s.annee, s.mois) }}
                            </p>
                            <p
                                class="text-xs text-muted-foreground tabular-nums"
                            >
                                {{ valeurSaisie(affectationSaisies, s) }}
                                <template v-if="s.commentaire">
                                    · {{ s.commentaire }}</template
                                >
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <StatusDot
                                :status="s.statut"
                                :label="s.statut_label"
                            />
                            <Button
                                v-if="s.statut === 'a_valider' || peutValider"
                                variant="ghost"
                                size="sm"
                                @click="corrigerSaisie(s)"
                                >Corriger</Button
                            >
                            <Button
                                v-if="s.statut === 'a_valider' && peutValider"
                                size="sm"
                                variant="outline"
                                :disabled="saisieEnCours === s.id"
                                @click="actionSaisie(s, 'valider')"
                            >
                                <Loader2
                                    v-if="saisieEnCours === s.id"
                                    class="mr-1 h-3 w-3 animate-spin"
                                />
                                Valider
                            </Button>
                            <Button
                                v-if="peutSupprimerSaisie(s)"
                                size="sm"
                                variant="ghost"
                                class="text-destructive"
                                :disabled="saisieEnCours === s.id"
                                @click="actionSaisie(s, 'supprimer')"
                                >Supprimer</Button
                            >
                        </div>
                    </div>
                </div>
            </div>

            <form
                class="space-y-3 rounded-lg border bg-muted/30 p-3"
                @submit.prevent="enregistrerSaisie(false)"
            >
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <Label
                            for="sa-mois"
                            class="mb-1.5 block text-xs font-medium"
                        >
                            Mois <span class="text-destructive">*</span>
                        </Label>
                        <Input
                            id="sa-mois"
                            v-model="formSaisie.mois"
                            type="month"
                        />
                        <p
                            v-if="formSaisie.errors.mois"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ formSaisie.errors.mois }}
                        </p>
                    </div>
                    <div
                        v-if="
                            affectationSaisies.type.mode_calcul ===
                            'pourcentage_salaire_base'
                        "
                    >
                        <Label
                            for="sa-taux"
                            class="mb-1.5 block text-xs font-medium"
                        >
                            Taux du mois (%)
                            <span class="text-destructive">*</span>
                        </Label>
                        <Input
                            id="sa-taux"
                            v-model.number="formSaisie.taux"
                            type="number"
                            min="0"
                            max="100"
                            step="0.01"
                        />
                        <p
                            v-if="formSaisie.errors.taux"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ formSaisie.errors.taux }}
                        </p>
                    </div>
                    <div v-else>
                        <Label
                            for="sa-montant"
                            class="mb-1.5 block text-xs font-medium"
                        >
                            Montant du mois (GNF)
                            <span class="text-destructive">*</span>
                        </Label>
                        <MontantInput
                            id="sa-montant"
                            v-model="formSaisie.montant"
                            class="h-9"
                        />
                        <p
                            v-if="formSaisie.errors.montant"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ formSaisie.errors.montant }}
                        </p>
                    </div>
                </div>
                <Input
                    v-model="formSaisie.commentaire"
                    placeholder="Commentaire (facultatif)"
                />
                <div class="flex flex-wrap justify-end gap-2">
                    <Button
                        type="submit"
                        size="sm"
                        :variant="peutValider ? 'outline' : 'default'"
                        :disabled="formSaisie.processing || !formSaisie.mois"
                    >
                        <Loader2
                            v-if="formSaisie.processing && !formSaisie.valider"
                            class="mr-1.5 h-3.5 w-3.5 animate-spin"
                        />
                        Enregistrer le montant
                    </Button>
                    <Button
                        v-if="peutValider"
                        type="button"
                        size="sm"
                        :disabled="formSaisie.processing || !formSaisie.mois"
                        @click="enregistrerSaisie(true)"
                    >
                        <Loader2
                            v-if="formSaisie.processing && formSaisie.valider"
                            class="mr-1.5 h-3.5 w-3.5 animate-spin"
                        />
                        Enregistrer et valider
                    </Button>
                </div>
            </form>
        </div>
    </Dialog>

    <!-- Confirmation -->
    <Dialog
        :visible="confirmation !== null"
        modal
        :header="titreConfirmation"
        :style="{ width: 'min(30rem, 95vw)' }"
        :draggable="false"
        :closable="!confirmationEnCours"
        @update:visible="fermerConfirmation"
    >
        <p class="text-sm text-muted-foreground">{{ texteConfirmation }}</p>
        <div v-if="confirmation?.action === 'annuler'" class="mt-3">
            <Label for="an-motif" class="mb-1.5 block text-xs font-medium"
                >Motif</Label
            >
            <Input
                id="an-motif"
                v-model="motifAnnulation"
                placeholder="Facultatif"
            />
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <Button
                    variant="outline"
                    :disabled="confirmationEnCours"
                    @click="fermerConfirmation(false)"
                    >Fermer</Button
                >
                <Button
                    :variant="
                        confirmation?.action === 'annuler'
                            ? 'destructive'
                            : 'default'
                    "
                    :disabled="confirmationEnCours"
                    data-testid="affectation-confirmer"
                    @click="confirmer"
                >
                    <Loader2
                        v-if="confirmationEnCours"
                        class="mr-1.5 h-3.5 w-3.5 animate-spin"
                    />
                    {{ titreConfirmation }}
                </Button>
            </div>
        </template>
    </Dialog>

    <AuditDrawer
        v-model:visible="historiqueVisible"
        :title="`Historique — ${historique?.type.libelle ?? ''} · ${historique?.employe.nom_complet ?? ''}`"
        auditable-type="affectation_remuneration"
        :auditable-id="historique?.id ?? ''"
        :endpoint="`${BASE}/${historique?.id ?? ''}/historique`"
    />
</template>
