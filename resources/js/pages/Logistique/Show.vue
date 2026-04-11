<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowRight,
    CheckCircle2,
    ChevronRight,
    FileEdit,
    HandCoins,
    History,
    MapPin,
    Package,
    PackageCheck,
    Pencil,
    Truck,
    User,
    XCircle,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import { useToast } from 'primevue/usetoast';
import { computed, ref, watch } from 'vue';

// ── Types ─────────────────────────────────────────────────────────────────────

interface Versement {
    id: number;
    montant: number;
    date_versement: string;
    enregistre_le: string;
    mode_paiement: string;
    note: string | null;
    created_by: string | null;
}

interface CommissionPart {
    id: number;
    type_beneficiaire: 'livreur' | 'proprietaire';
    beneficiaire_nom: string;
    taux_commission: number;
    montant_brut: number;
    frais_supplementaires: number;
    montant_net: number;
    montant_verse: number;
    montant_restant: number;
    statut: string;
    statut_label: string;
    statut_dot_class: string;
    is_versee: boolean;
    versements: Versement[];
}

interface Commission {
    id: number;
    base_calcul: string;
    base_calcul_label: string;
    valeur_base: number;
    quantite_reference: number | null;
    montant_total: number;
    montant_verse: number;
    montant_restant: number;
    statut: string;
    statut_label: string;
    statut_dot_class: string;
    is_versee: boolean;
    parts: CommissionPart[];
}

interface Ligne {
    id: number;
    produit_id: number;
    produit_nom: string;
    quantite_demandee: number;
    quantite_chargee: number | null;
    quantite_recue: number | null;
    ecart: number | null;
    ecart_type: string | null;
    ecart_label: string;
    ecart_dot_class: string;
    ecart_motif: string | null;
    notes: string | null;
    est_reception_complete: boolean;
}

interface Transfert {
    id: number;
    reference: string;
    site_source_nom: string | null;
    site_source_id: number | null;
    site_destination_nom: string | null;
    site_destination_id: number | null;
    vehicule_id: number | null;
    vehicule_nom: string | null;
    immatriculation: string | null;
    equipe_livraison_id: number | null;
    equipe_nom: string | null;
    statut: string;
    statut_label: string;
    statut_dot_class: string;
    date_depart_prevue: string | null;
    date_depart_reelle: string | null;
    date_arrivee_prevue: string | null;
    date_arrivee_reelle: string | null;
    notes: string | null;
    createur: string | null;
    lignes: Ligne[];
    commission: Commission | null;
    is_brouillon: boolean;
    is_cloture: boolean;
    is_terminal: boolean;
    is_annule: boolean;
    is_editable: boolean;
    created_at: string;
}

interface BaseCalculOption { value: string; label: string }
interface TypeEcartOption  { value: string; label: string }

// ── Props ─────────────────────────────────────────────────────────────────────

interface Activite {
    id: number;
    action: string;
    action_label: string;
    user_nom: string;
    details: Record<string, any> | null;
    created_at: string;
}

const props = defineProps<{
    transfert: Transfert;
    contexte: 'transferts' | 'receptions';
    types_ecart: TypeEcartOption[];
    bases_calcul: BaseCalculOption[];
    can_avancer: boolean;
    can_valider_reception: boolean;
    can_annuler: boolean;
    can_update: boolean;
    can_generer_commission: boolean;
    can_verser_commission: boolean;
    activites: Activite[];
}>();

const toast = useToast();

const contexteLabel = props.contexte === 'receptions' ? 'Réceptions' : 'Transferts';
const contexteHref  = `/logistique/${props.contexte}`;

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: contexteLabel, href: contexteHref },
    { title: props.transfert.reference, href: `/logistique/${props.transfert.id}` },
];

// ── Progression ───────────────────────────────────────────────────────────────

const STEPS = [
    { key: 'brouillon',  label: 'Brouillon',      shortLabel: 'Brouillon',    icon: FileEdit    },
    { key: 'chargement', label: 'En chargement',   shortLabel: 'Chargement',   icon: Package     },
    { key: 'transit',    label: 'Livraison',        shortLabel: 'Livraison',    icon: Truck       },
    { key: 'reception',  label: 'Réceptionné',      shortLabel: 'Réceptionné',  icon: PackageCheck },
    { key: 'cloture',    label: 'Clôturé',          shortLabel: 'Clôturé',      icon: CheckCircle2 },
];

const currentStepIdx = computed(() => STEPS.findIndex((s) => s.key === props.transfert.statut));

function stepState(idx: number): 'done' | 'current' | 'future' {
    const cur = currentStepIdx.value;
    if (cur === -1) return 'future'; // annulé
    if (idx < cur)  return 'done';
    if (idx === cur) return 'current';
    return 'future';
}

// ── Action principale par état ────────────────────────────────────────────────

const mainActionLabel = computed(() => {
    if (props.transfert.is_terminal) return null;
    const statut = props.transfert.statut;

    // TRANSIT → RECEPTION : uniquement si l'utilisateur est du site d'arrivée
    if (statut === 'transit') {
        return props.can_valider_reception ? 'Valider la réception' : null;
    }

    // Autres transitions (brouillon, chargement)
    if (!props.can_avancer) return null;
    const labels: Record<string, string> = {
        brouillon:  'Démarrer le chargement',
        chargement: 'Valider le chargement',
    };
    return labels[statut] ?? null;
});

// ── Dialogs visibilité ────────────────────────────────────────────────────────

const showChargementDialog = ref(false);
const showReceptionDialog  = ref(false);
const showCommissionDialog = ref(false);
const showVersementDialog  = ref(false);
const processing    = ref(false);
const dialogErrors  = ref<string[]>([]);

// ── Données formulaires dialogs (réinitialisées à chaque ouverture) ───────────

interface ChargementLigne { id: number; produit_nom: string; quantite_demandee: number; quantite_chargee: number }
interface ReceptionLigne  { id: number; produit_nom: string; quantite_chargee: number; quantite_recue: number; ecart_type: string; ecart_motif: string }

const chargementLignes = ref<ChargementLigne[]>([]);
const receptionLignes  = ref<ReceptionLigne[]>([]);

// Réinitialiser depuis les props courantes à chaque ouverture du dialog
watch(showChargementDialog, (open) => {
    if (open) {
        chargementLignes.value = props.transfert.lignes.map((l) => ({
            id:                l.id,
            produit_nom:       l.produit_nom,
            quantite_demandee: l.quantite_demandee,
            quantite_chargee:  l.quantite_chargee ?? l.quantite_demandee,
        }));
    }
});

watch(showReceptionDialog, (open) => {
    if (open) {
        receptionLignes.value = props.transfert.lignes.map((l) => ({
            id:               l.id,
            produit_nom:      l.produit_nom,
            quantite_chargee: l.quantite_chargee ?? 0,
            quantite_recue:   l.quantite_recue ?? l.quantite_chargee ?? 0,
            ecart_type:       l.ecart_type ?? 'conforme',
            ecart_motif:      l.ecart_motif ?? '',
        }));
    }
});

// ── Dispatch action principale ────────────────────────────────────────────────

function onMainAction() {
    const statut = props.transfert.statut;
    if (statut === 'brouillon')        avancerDirect();
    else if (statut === 'chargement')  showChargementDialog.value = true;
    else if (statut === 'transit')     showReceptionDialog.value  = true;
    // reception → clôture automatique, pas d'action manuelle
}

function avancerDirect() {
    processing.value = true;
    dialogErrors.value = [];
    router.post(`/logistique/${props.transfert.id}/statut/avancer`, {}, {
        onSuccess: () => {
            toast.add({ severity: 'success', summary: 'Statut mis à jour', life: 3000 });
        },
        onError: (errors) => {
            dialogErrors.value = Object.values(errors).flat() as string[];
        },
        onFinish: () => { processing.value = false; },
    });
}

function submitChargement() {
    processing.value = true;
    dialogErrors.value = [];
    router.post(
        `/logistique/${props.transfert.id}/statut/avancer`,
        { lignes: chargementLignes.value.map((l) => ({ id: l.id, quantite_chargee: l.quantite_chargee })) },
        {
            onSuccess: () => {
                showChargementDialog.value = false;
                toast.add({ severity: 'success', summary: 'Chargement validé', detail: 'Le transfert est en cours de livraison.', life: 4000 });
            },
            onError: (errors) => {
                dialogErrors.value = Object.values(errors).flat() as string[];
            },
            onFinish: () => { processing.value = false; },
        },
    );
}

function submitReception() {
    processing.value = true;
    dialogErrors.value = [];
    router.post(
        `/logistique/${props.transfert.id}/statut/avancer`,
        {
            lignes: receptionLignes.value.map((l) => ({
                id:             l.id,
                quantite_recue: l.quantite_recue,
                ecart_type:     l.ecart_type,
                ecart_motif:    l.ecart_motif,
            })),
        },
        {
            onSuccess: () => {
                showReceptionDialog.value = false;
                toast.add({ severity: 'success', summary: 'Réception validée', detail: 'Le transfert est maintenant réceptionné.', life: 4000 });
            },
            onError: (errors) => {
                dialogErrors.value = Object.values(errors).flat() as string[];
            },
            onFinish: () => { processing.value = false; },
        },
    );
}

function annulerTransfert() {
    processing.value = true;
    router.post(`/logistique/${props.transfert.id}/statut/annuler`, {}, {
        onFinish: () => { processing.value = false; },
    });
}

// ── Commission ────────────────────────────────────────────────────────────────

const commissionForm = useForm({
    base_calcul:        'forfait',
    valeur_base:        0 as number,
    quantite_reference: null as number | null,
});

const needsQuantite = computed(() =>
    ['par_pack', 'par_km'].includes(commissionForm.base_calcul),
);

function submitCommission() {
    commissionForm.post(`/logistique/${props.transfert.id}/commission`, {
        onSuccess: () => {
            showCommissionDialog.value = false;
            toast.add({ severity: 'success', summary: 'Commission générée', life: 3000 });
        },
        onError: (errors) => {
            const firstError = Object.values(errors)[0];
            if (firstError && !errors.base_calcul && !errors.valeur_base && !errors.quantite_reference) {
                toast.add({ severity: 'error', summary: 'Erreur', detail: String(firstError), life: 5000 });
            }
        },
    });
}

// ── Versement ─────────────────────────────────────────────────────────────────

const MODES_PAIEMENT = [
    { value: 'especes',      label: 'Espèces' },
    { value: 'virement',     label: 'Virement' },
    { value: 'cheque',       label: 'Chèque' },
    { value: 'mobile_money', label: 'Mobile Money' },
];

const selectedPart = ref<CommissionPart | null>(null);

const versementForm = useForm({
    montant:       0 as number,
    mode_paiement: 'especes',
    note:          '',
});

function openVersementDialog(part: CommissionPart) {
    selectedPart.value = part;
    versementForm.reset();
    versementForm.montant = part.montant_restant;
    showVersementDialog.value = true;
}

function submitVersement() {
    if (!selectedPart.value) return;
    versementForm.post(`/commissions-logistique/parts/${selectedPart.value.id}/versements`, {
        onSuccess: () => {
            showVersementDialog.value = false;
            toast.add({ severity: 'success', summary: 'Versement enregistré', life: 3000 });
        },
        onError: (errors) => {
            const firstError = Object.values(errors)[0];
            if (firstError && !errors.montant && !errors.mode_paiement) {
                toast.add({ severity: 'error', summary: 'Erreur', detail: String(firstError), life: 5000 });
            }
        },
    });
}

// ── Formatage ─────────────────────────────────────────────────────────────────

function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}

function formatModePaiement(mode: string): string {
    const option = MODES_PAIEMENT.find((item) => item.value === mode);
    if (option) return option.label;
    return mode.replaceAll('_', ' ');
}

// Calcul local écart dans le dialog réception
function ecartReception(idx: number): number {
    const l = receptionLignes.value[idx];
    return (l.quantite_recue ?? 0) - (l.quantite_chargee ?? 0);
}

// Badge couleur statut
const statutBadgeClass = computed(() => {
    const map: Record<string, string> = {
        brouillon:  'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',
        chargement: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
        transit:    'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
        reception:  'bg-teal-100 text-teal-700 dark:bg-teal-900/40 dark:text-teal-300',
        cloture:    'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
        annule:     'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
    };
    return map[props.transfert.statut] ?? 'bg-zinc-100 text-zinc-700';
});

// Afficher la section commission ?
const showCommissionSection = computed(() =>
    props.transfert.statut === 'reception' ||
    props.transfert.statut === 'cloture' ||
    !!props.transfert.commission,
);

const livreurParts = computed(() =>
    props.transfert.commission?.parts.filter((part) => part.type_beneficiaire === 'livreur') ?? [],
);

const proprietaireParts = computed(() =>
    props.transfert.commission?.parts.filter((part) => part.type_beneficiaire === 'proprietaire') ?? [],
);

const activeCommissionTab = ref<'livreurs' | 'proprietaires'>('livreurs');

watch([livreurParts, proprietaireParts], ([livreurs, proprietaires]) => {
    if (activeCommissionTab.value === 'livreurs' && livreurs.length === 0 && proprietaires.length > 0) {
        activeCommissionTab.value = 'proprietaires';
    }

    if (activeCommissionTab.value === 'proprietaires' && proprietaires.length === 0 && livreurs.length > 0) {
        activeCommissionTab.value = 'livreurs';
    }
}, { immediate: true });

function aggregateParts(parts: CommissionPart[]) {
    return parts.reduce((acc, part) => {
        acc.brut += part.montant_brut;
        acc.frais += part.frais_supplementaires;
        acc.net += part.montant_net;
        acc.verse += part.montant_verse;
        acc.restant += part.montant_restant;
        return acc;
    }, {
        brut: 0,
        frais: 0,
        net: 0,
        verse: 0,
        restant: 0,
    });
}

const livreurTotals = computed(() => aggregateParts(livreurParts.value));
const proprietaireTotals = computed(() => aggregateParts(proprietaireParts.value));

const partLivreurTotal = computed(() => livreurTotals.value.net);
const partProprietaireTotal = computed(() => proprietaireTotals.value.net);

const showHistoriqueDialog = ref(false);
const historiquePart = ref<CommissionPart | null>(null);

function openHistoriqueDialog(part: CommissionPart) {
    historiquePart.value = part;
    showHistoriqueDialog.value = true;
}

const showActivitesDialog = ref(false);
const activitesTriees = computed(() => [...props.activites].reverse());

function activiteDotClass(action: string): string {
    if (action === 'annule') return 'bg-amber-500';
    if (action === 'cloture') return 'bg-emerald-500';
    if (action === 'creation') return 'bg-blue-500';
    if (action === 'versement_effectue') return 'bg-purple-500';
    return 'bg-primary';
}
</script>

<template>
    <Head :title="transfert.reference" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6 sm:px-6 space-y-5">

            <!-- ══ Header ══════════════════════════════════════════════════════ -->
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex items-center gap-3">
                    <Link
                        :href="contexteHref"
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground hover:bg-muted/80"
                    >
                        <ArrowLeft class="h-4 w-4" />
                    </Link>
                    <div>
                        <div class="flex items-center gap-2">
                            <h1 class="font-mono text-xl font-bold tracking-wide">
                                {{ transfert.reference }}
                            </h1>
                            <span :class="['rounded-full px-2.5 py-0.5 text-xs font-semibold', statutBadgeClass]">
                                {{ transfert.statut_label }}
                            </span>
                            <span
                                :class="[
                                    'rounded-full border px-2 py-0.5 text-[11px] font-medium',
                                    contexte === 'receptions'
                                        ? 'border-teal-200 bg-teal-50 text-teal-700 dark:border-teal-800 dark:bg-teal-950 dark:text-teal-300'
                                        : 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-300',
                                ]"
                            >
                                {{ contexteLabel }}
                            </span>
                        </div>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            Créé le {{ transfert.created_at }}
                            <span v-if="transfert.createur"> · {{ transfert.createur }}</span>
                        </p>
                    </div>
                </div>

                <!-- Boutons d'action -->
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        @click="showActivitesDialog = true"
                    >
                        <History class="mr-1.5 h-3.5 w-3.5" />
                        Historique
                        <span class="ml-1 rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-medium tabular-nums">
                            {{ activites.length }}
                        </span>
                    </Button>

                    <Link
                        v-if="can_update && transfert.is_editable"
                        :href="`/logistique/${transfert.id}/editer`"
                    >
                        <Button variant="outline" size="sm">
                            <Pencil class="mr-1.5 h-3.5 w-3.5" />
                            Modifier
                        </Button>
                    </Link>

                    <Button
                        v-if="can_annuler"
                        variant="outline"
                        size="sm"
                        class="border-red-200 text-red-600 hover:bg-red-50 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-950"
                        :disabled="processing"
                        @click="annulerTransfert"
                    >
                        <XCircle class="mr-1.5 h-3.5 w-3.5" />
                        Annuler
                    </Button>

                    <Button
                        v-if="mainActionLabel"
                        size="sm"
                        :disabled="processing"
                        @click="onMainAction"
                    >
                        <ArrowRight class="mr-1.5 h-3.5 w-3.5" />
                        {{ mainActionLabel }}
                    </Button>
                </div>
            </div>

            <!-- ══ Progression horizontale ════════════════════════════════════ -->
            <div class="rounded-xl border bg-card px-6 py-4 shadow-sm">
                <!-- Annulé -->
                <div v-if="transfert.is_annule" class="flex items-center gap-2 text-red-600 dark:text-red-400">
                    <XCircle class="h-5 w-5" />
                    <span class="font-semibold">Ce transfert a été annulé.</span>
                </div>

                <!-- Progression normale -->
                <div v-else class="flex items-center">
                    <template v-for="(step, idx) in STEPS" :key="step.key">
                        <!-- Étape -->
                        <div class="flex flex-col items-center" style="min-width:80px">
                            <div
                                :class="[
                                    'flex h-9 w-9 items-center justify-center rounded-full transition-all',
                                    stepState(idx) === 'done'    ? 'bg-emerald-500 text-white shadow-sm' : '',
                                    stepState(idx) === 'current' ? 'bg-blue-600 text-white shadow-md ring-4 ring-blue-100 dark:ring-blue-900/50' : '',
                                    stepState(idx) === 'future'  ? 'bg-muted text-muted-foreground' : '',
                                ]"
                            >
                                <component :is="step.icon" class="h-4 w-4" />
                            </div>
                            <span
                                :class="[
                                    'mt-1.5 text-center text-[11px] font-medium leading-tight',
                                    stepState(idx) === 'current' ? 'text-blue-600 dark:text-blue-400' : '',
                                    stepState(idx) === 'done'    ? 'text-emerald-600 dark:text-emerald-400' : '',
                                    stepState(idx) === 'future'  ? 'text-muted-foreground' : '',
                                ]"
                            >
                                {{ step.shortLabel }}
                            </span>
                        </div>
                        <!-- Connecteur -->
                        <div
                            v-if="idx < STEPS.length - 1"
                            :class="[
                                'mb-5 h-0.5 flex-1 transition-all',
                                idx < currentStepIdx ? 'bg-emerald-400' : 'bg-border',
                            ]"
                        />
                    </template>
                </div>
            </div>

            <!-- ══ Contenu principal (2 colonnes) ═════════════════════════════ -->
            <div class="grid grid-cols-1 gap-5 lg:grid-cols-5">

                <!-- Colonne gauche : Informations ────────────────────────────── -->
                <div class="lg:col-span-2 rounded-xl border bg-card p-5 shadow-sm space-y-4">
                    <h2 class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                        Informations
                    </h2>

                    <!-- Trajet -->
                    <div class="flex items-center gap-2 rounded-lg bg-muted/40 px-3 py-2.5">
                        <MapPin class="h-4 w-4 shrink-0 text-muted-foreground" />
                        <span class="font-medium text-sm">{{ transfert.site_source_nom ?? '—' }}</span>
                        <ChevronRight class="h-4 w-4 shrink-0 text-muted-foreground" />
                        <span class="font-medium text-sm">{{ transfert.site_destination_nom ?? '—' }}</span>
                    </div>

                    <div class="grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <p class="text-xs text-muted-foreground">Véhicule</p>
                            <p class="mt-0.5 font-medium">{{ transfert.vehicule_nom ?? '—' }}</p>
                            <p v-if="transfert.immatriculation" class="font-mono text-xs text-muted-foreground">
                                {{ transfert.immatriculation }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">Équipe</p>
                            <p class="mt-0.5 font-medium">{{ transfert.equipe_nom ?? '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">Départ prévu</p>
                            <p class="mt-0.5 font-medium">{{ transfert.date_depart_prevue ?? '—' }}</p>
                            <p v-if="transfert.date_depart_reelle" class="text-xs text-emerald-600 dark:text-emerald-400">
                                Réel : {{ transfert.date_depart_reelle }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">Arrivée prévue</p>
                            <p class="mt-0.5 font-medium">{{ transfert.date_arrivee_prevue ?? '—' }}</p>
                            <p v-if="transfert.date_arrivee_reelle" class="text-xs text-emerald-600 dark:text-emerald-400">
                                Réelle : {{ transfert.date_arrivee_reelle }}
                            </p>
                        </div>
                    </div>

                    <div v-if="transfert.notes" class="rounded-lg bg-muted/30 px-3 py-2 text-sm text-muted-foreground">
                        {{ transfert.notes }}
                    </div>
                </div>

                <!-- Colonne droite : Lignes produits ─────────────────────────── -->
                <div class="lg:col-span-3">
                    <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
                        <div class="flex items-center justify-between px-5 py-3.5 border-b">
                            <h2 class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                Lignes produits
                            </h2>
                            <span class="text-xs text-muted-foreground">{{ transfert.lignes.length }} article(s)</span>
                        </div>

                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b bg-muted/30 text-xs text-muted-foreground">
                                    <th class="px-4 py-2.5 text-left font-medium">Produit</th>
                                    <th class="px-4 py-2.5 text-center font-medium">Demandé</th>
                                    <th v-if="['transit','reception','cloture'].includes(transfert.statut)" class="px-4 py-2.5 text-center font-medium">Chargé</th>
                                    <th v-if="['reception','cloture'].includes(transfert.statut)" class="px-4 py-2.5 text-center font-medium">Reçu</th>
                                    <th v-if="['reception','cloture'].includes(transfert.statut)" class="px-4 py-2.5 text-left font-medium">Écart</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr
                                    v-for="ligne in transfert.lignes"
                                    :key="ligne.id"
                                    class="hover:bg-muted/10"
                                >
                                    <td class="px-4 py-3 font-medium">{{ ligne.produit_nom }}</td>
                                    <td class="px-4 py-3 text-center tabular-nums">{{ ligne.quantite_demandee }}</td>
                                    <td v-if="['transit','reception','cloture'].includes(transfert.statut)" class="px-4 py-3 text-center tabular-nums">
                                        {{ ligne.quantite_chargee ?? '—' }}
                                    </td>
                                    <td v-if="['reception','cloture'].includes(transfert.statut)" class="px-4 py-3 text-center tabular-nums">
                                        {{ ligne.quantite_recue ?? '—' }}
                                    </td>
                                    <td v-if="['reception','cloture'].includes(transfert.statut)" class="px-4 py-3">
                                        <div v-if="ligne.ecart_type" class="flex items-center gap-1.5">
                                            <span :class="['inline-block h-2 w-2 rounded-full', ligne.ecart_dot_class]" />
                                            <span class="text-xs">{{ ligne.ecart_label }}</span>
                                            <span v-if="ligne.ecart && ligne.ecart !== 0" class="tabular-nums text-xs text-muted-foreground">
                                                ({{ ligne.ecart > 0 ? '+' : '' }}{{ ligne.ecart }})
                                            </span>
                                        </div>
                                        <span v-else class="text-muted-foreground">—</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ══ Commission logistique ═══════════════════════════════════════ -->
            <div v-if="showCommissionSection" class="rounded-xl border bg-card shadow-sm overflow-hidden">
                <div class="flex items-center justify-between px-5 py-3.5 border-b">
                    <h2 class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                        Commission logistique
                    </h2>
                    <Button
                        v-if="can_generer_commission && transfert.statut === 'reception' && !(transfert.commission && transfert.commission.montant_verse > 0)"
                        variant="outline"
                        size="sm"
                        @click="showCommissionDialog = true"
                    >
                        <HandCoins class="mr-1.5 h-3.5 w-3.5" />
                        {{ transfert.commission ? 'Recalculer' : 'Générer' }}
                    </Button>
                </div>

                <!-- Pas encore de commission -->
                <div v-if="!transfert.commission" class="px-5 py-8 text-center text-sm text-muted-foreground">
                    Aucune commission générée. Cliquez sur "Générer" pour calculer les commissions livreur/propriétaire.
                </div>

                <!-- Commission existante -->
                <div v-else class="space-y-4 px-5 py-4">
                    <!-- Synthese style commission vente -->
                    <div class="rounded-xl border bg-card p-5 shadow-sm">
                        <div class="flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <p class="text-xs font-semibold tracking-wider text-muted-foreground uppercase">Total commission</p>
                                <p class="mt-1 text-2xl font-bold tabular-nums">{{ formatGNF(transfert.commission.montant_total) }}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-xs font-semibold tracking-wider text-muted-foreground uppercase">Part Livreur (Total)</p>
                                <p class="mt-1 text-2xl font-bold tabular-nums">{{ formatGNF(partLivreurTotal) }}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-xs font-semibold tracking-wider text-muted-foreground uppercase">Part Proprietaire (Total)</p>
                                <p class="mt-1 text-2xl font-bold tabular-nums">{{ formatGNF(partProprietaireTotal) }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-center">
                        <div class="inline-flex items-center gap-1 rounded-xl border bg-card p-1 shadow-sm">
                            <button
                                class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors"
                                :class="activeCommissionTab === 'livreurs'
                                    ? 'bg-primary/10 text-primary'
                                    : 'text-muted-foreground hover:bg-muted hover:text-foreground'"
                                :disabled="livreurParts.length === 0"
                                @click="activeCommissionTab = 'livreurs'"
                            >
                                <Truck class="h-3.5 w-3.5" />
                                Livreurs
                                <span class="rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-medium tabular-nums">
                                    {{ livreurParts.length }}
                                </span>
                            </button>
                            <button
                                class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors"
                                :class="activeCommissionTab === 'proprietaires'
                                    ? 'bg-primary/10 text-primary'
                                    : 'text-muted-foreground hover:bg-muted hover:text-foreground'"
                                :disabled="proprietaireParts.length === 0"
                                @click="activeCommissionTab = 'proprietaires'"
                            >
                                <User class="h-3.5 w-3.5" />
                                Proprietaire
                                <span class="rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-medium tabular-nums">
                                    {{ proprietaireParts.length }}
                                </span>
                            </button>
                        </div>
                    </div>

                    <div
                        v-if="activeCommissionTab === 'livreurs'"
                        class="overflow-hidden rounded-xl border bg-card shadow-sm"
                    >
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b bg-muted/40">
                                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Livreur</th>
                                        <th class="px-4 py-3 text-right font-medium text-muted-foreground">Taux</th>
                                        <th class="px-4 py-3 text-right font-medium text-muted-foreground">Montant</th>
                                        <th class="px-4 py-3 text-right font-medium text-muted-foreground">Verse</th>
                                        <th class="px-4 py-3 text-right font-medium text-muted-foreground">Restant</th>
                                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Statut</th>
                                        <th class="px-4 py-3 text-center font-medium text-muted-foreground">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y">
                                    <tr
                                        v-for="part in livreurParts"
                                        :key="part.id"
                                        class="transition-colors hover:bg-muted/10"
                                    >
                                        <td class="px-4 py-3 font-medium">{{ part.beneficiaire_nom }}</td>
                                        <td class="px-4 py-3 text-right text-muted-foreground tabular-nums">{{ part.taux_commission }}%</td>
                                        <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ formatGNF(part.montant_net) }}</td>
                                        <td class="px-4 py-3 text-right text-foreground tabular-nums">{{ formatGNF(part.montant_verse) }}</td>
                                        <td
                                            class="px-4 py-3 text-right font-semibold tabular-nums"
                                            :class="part.montant_restant > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-foreground'"
                                        >
                                            {{ formatGNF(part.montant_restant) }}
                                        </td>
                                        <td class="px-4 py-3">
                                            <StatusDot
                                                :label="part.statut_label"
                                                :dot-class="part.statut_dot_class"
                                                class="text-xs text-muted-foreground"
                                            />
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <div class="flex items-center justify-center gap-2">
                                                <Button
                                                    v-if="part.versements.length > 0"
                                                    variant="ghost"
                                                    size="sm"
                                                    class="h-8 px-2.5"
                                                    @click="openHistoriqueDialog(part)"
                                                >
                                                    <History class="mr-1.5 h-3.5 w-3.5" />
                                                    Hist. ({{ part.versements.length }})
                                                </Button>
                                                <Button
                                                    v-if="can_verser_commission && !part.is_versee && transfert.statut === 'reception'"
                                                    size="sm"
                                                    @click="openVersementDialog(part)"
                                                >
                                                    Verser
                                                </Button>
                                                <span v-else-if="part.is_versee" class="text-xs font-medium text-emerald-600">Verse ✓</span>
                                                <span v-else class="text-xs text-muted-foreground">—</span>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr class="border-t-2 bg-muted/20 text-sm font-semibold">
                                        <td colspan="2" class="px-4 py-2.5 text-xs font-bold uppercase text-muted-foreground">Total</td>
                                        <td class="px-4 py-2.5 text-right tabular-nums">{{ formatGNF(livreurTotals.net) }}</td>
                                        <td class="px-4 py-2.5 text-right tabular-nums text-emerald-600 dark:text-emerald-400">{{ formatGNF(livreurTotals.verse) }}</td>
                                        <td
                                            class="px-4 py-2.5 text-right tabular-nums"
                                            :class="livreurTotals.restant > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-muted-foreground'"
                                        >
                                            {{ formatGNF(livreurTotals.restant) }}
                                        </td>
                                        <td colspan="2" />
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <div
                        v-if="activeCommissionTab === 'proprietaires'"
                        class="overflow-hidden rounded-xl border bg-card shadow-sm"
                    >
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b bg-muted/40">
                                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Proprietaire</th>
                                        <th class="px-4 py-3 text-right font-medium text-muted-foreground">Taux</th>
                                        <th class="px-4 py-3 text-right font-medium text-muted-foreground">Brut</th>
                                        <th class="px-4 py-3 text-right font-medium text-muted-foreground">Frais</th>
                                        <th class="px-4 py-3 text-right font-medium text-muted-foreground">Net</th>
                                        <th class="px-4 py-3 text-right font-medium text-muted-foreground">Verse</th>
                                        <th class="px-4 py-3 text-right font-medium text-muted-foreground">Restant</th>
                                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Statut</th>
                                        <th class="px-4 py-3 text-center font-medium text-muted-foreground">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y">
                                    <tr
                                        v-for="part in proprietaireParts"
                                        :key="part.id"
                                        class="transition-colors hover:bg-muted/10"
                                    >
                                        <td class="px-4 py-3 font-medium">{{ part.beneficiaire_nom }}</td>
                                        <td class="px-4 py-3 text-right text-muted-foreground tabular-nums">{{ part.taux_commission }}%</td>
                                        <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ formatGNF(part.montant_brut) }}</td>
                                        <td class="px-4 py-3 text-right tabular-nums">
                                            <span v-if="part.frais_supplementaires > 0" class="font-semibold text-destructive">
                                                - {{ formatGNF(part.frais_supplementaires) }}
                                            </span>
                                            <span v-else class="text-muted-foreground">{{ formatGNF(0) }}</span>
                                        </td>
                                        <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ formatGNF(part.montant_net) }}</td>
                                        <td class="px-4 py-3 text-right text-foreground tabular-nums">{{ formatGNF(part.montant_verse) }}</td>
                                        <td
                                            class="px-4 py-3 text-right font-semibold tabular-nums"
                                            :class="part.montant_restant > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-foreground'"
                                        >
                                            {{ formatGNF(part.montant_restant) }}
                                        </td>
                                        <td class="px-4 py-3">
                                            <StatusDot
                                                :label="part.statut_label"
                                                :dot-class="part.statut_dot_class"
                                                class="text-xs text-muted-foreground"
                                            />
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <div class="flex items-center justify-center gap-2">
                                                <Button
                                                    v-if="part.versements.length > 0"
                                                    variant="ghost"
                                                    size="sm"
                                                    class="h-8 px-2.5"
                                                    @click="openHistoriqueDialog(part)"
                                                >
                                                    <History class="mr-1.5 h-3.5 w-3.5" />
                                                    Hist. ({{ part.versements.length }})
                                                </Button>
                                                <Button
                                                    v-if="can_verser_commission && !part.is_versee && transfert.statut === 'reception'"
                                                    size="sm"
                                                    @click="openVersementDialog(part)"
                                                >
                                                    Verser
                                                </Button>
                                                <span v-else-if="part.is_versee" class="text-xs font-medium text-emerald-600">Verse ✓</span>
                                                <span v-else class="text-xs text-muted-foreground">—</span>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr class="border-t-2 bg-muted/20 text-sm font-semibold">
                                        <td colspan="2" class="px-4 py-2.5 text-xs font-bold uppercase text-muted-foreground">Total</td>
                                        <td class="px-4 py-2.5 text-right tabular-nums">{{ formatGNF(proprietaireTotals.brut) }}</td>
                                        <td class="px-4 py-2.5 text-right tabular-nums text-destructive">- {{ formatGNF(proprietaireTotals.frais) }}</td>
                                        <td class="px-4 py-2.5 text-right tabular-nums">{{ formatGNF(proprietaireTotals.net) }}</td>
                                        <td class="px-4 py-2.5 text-right tabular-nums text-emerald-600 dark:text-emerald-400">{{ formatGNF(proprietaireTotals.verse) }}</td>
                                        <td
                                            class="px-4 py-2.5 text-right tabular-nums"
                                            :class="proprietaireTotals.restant > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-muted-foreground'"
                                        >
                                            {{ formatGNF(proprietaireTotals.restant) }}
                                        </td>
                                        <td colspan="2" />
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- ══ Dialog : Valider le chargement ═════════════════════════════════ -->
        <Dialog
            v-model:visible="showHistoriqueDialog"
            modal
            :dismissable-mask="true"
            :header="`Historique — ${historiquePart?.beneficiaire_nom ?? ''}`"
            :style="{ width: 'min(760px, 96vw)' }"
        >
            <div v-if="historiquePart?.versements.length" class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b bg-muted/40">
                            <th class="px-3 py-2.5 text-left font-medium text-muted-foreground">Date versement</th>
                            <th class="px-3 py-2.5 text-left font-medium text-muted-foreground">Mode</th>
                            <th class="px-3 py-2.5 text-right font-medium text-muted-foreground">Montant</th>
                            <th class="px-3 py-2.5 text-left font-medium text-muted-foreground">Note</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="versement in historiquePart.versements"
                            :key="versement.id"
                        >
                            <td class="px-3 py-2.5 tabular-nums">{{ versement.date_versement }}</td>
                            <td class="px-3 py-2.5 text-muted-foreground">{{ formatModePaiement(versement.mode_paiement) }}</td>
                            <td class="px-3 py-2.5 text-right font-semibold tabular-nums">{{ formatGNF(versement.montant) }}</td>
                            <td class="px-3 py-2.5 text-muted-foreground">{{ versement.note || '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="py-3 text-sm text-muted-foreground">
                Aucun versement enregistre pour ce beneficiaire.
            </p>
        </Dialog>

        <Dialog
            v-model:visible="showChargementDialog"
            modal
            header="Valider le chargement"
            :style="{ width: 'min(820px, 92vw)' }"
            :draggable="true"
            :resizable="false"
            @hide="dialogErrors = []"
        >
            <p class="mb-4 text-sm text-muted-foreground">
                Renseignez les quantités réellement chargées dans le véhicule.
            </p>
            <div v-if="dialogErrors.length" class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 dark:border-red-800 dark:bg-red-950">
                <p v-for="err in dialogErrors" :key="err" class="text-sm text-red-700 dark:text-red-400">{{ err }}</p>
            </div>
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-xs text-muted-foreground">
                        <th class="pb-2 text-left font-medium">Produit</th>
                        <th class="pb-2 text-center font-medium">Qté demandée</th>
                        <th class="pb-2 text-center font-medium">Qté chargée</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="(l, idx) in chargementLignes" :key="l.id">
                        <td class="py-2.5 pr-4 font-medium">{{ l.produit_nom }}</td>
                        <td class="py-2.5 text-center tabular-nums text-muted-foreground">{{ l.quantite_demandee }}</td>
                        <td class="py-2.5">
                            <InputNumber
                                v-model="chargementLignes[idx].quantite_chargee"
                                :min="0"
                                :use-grouping="false"
                                class="w-full"
                                input-class="w-full text-center"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
            <template #footer>
                <Button variant="outline" :disabled="processing" @click="showChargementDialog = false">Annuler</Button>
                <Button :disabled="processing" @click="submitChargement">
                    <Truck v-if="!processing" class="mr-2 h-4 w-4" />
                    <span v-if="processing" class="mr-2 h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent inline-block" />
                    {{ processing ? 'Enregistrement…' : 'Valider et partir en livraison' }}
                </Button>
            </template>
        </Dialog>

        <!-- ══ Dialog : Valider la réception ══════════════════════════════════ -->
        <Dialog
            v-model:visible="showReceptionDialog"
            modal
            header="Valider la réception"
            :style="{ width: 'min(1050px, 94vw)' }"
            :draggable="true"
            :resizable="false"
            @hide="dialogErrors = []"
        >
            <p class="mb-4 text-sm text-muted-foreground">
                Renseignez les quantités reçues et les écarts constatés à destination.
            </p>
            <div v-if="dialogErrors.length" class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 dark:border-red-800 dark:bg-red-950">
                <p v-for="err in dialogErrors" :key="err" class="text-sm text-red-700 dark:text-red-400">{{ err }}</p>
            </div>
            <table class="w-full text-sm">
                <colgroup>
                    <col />                         <!-- Produit : flexible -->
                    <col style="width: 90px" />     <!-- Chargé -->
                    <col style="width: 130px" />    <!-- Reçu -->
                    <col style="width: 70px" />     <!-- Écart -->
                    <col style="width: 180px" />    <!-- Type -->
                    <col style="width: 220px" />    <!-- Motif -->
                </colgroup>
                <thead>
                    <tr class="border-b text-xs text-muted-foreground">
                        <th class="pb-3 text-left font-medium">Produit</th>
                        <th class="pb-3 text-center font-medium">Chargé</th>
                        <th class="pb-3 text-center font-medium">Reçu</th>
                        <th class="pb-3 text-center font-medium">Écart</th>
                        <th class="pb-3 text-left font-medium px-2">Type</th>
                        <th class="pb-3 text-left font-medium px-2">Motif</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="(l, idx) in receptionLignes" :key="l.id" class="align-middle">
                        <td class="py-3 pr-4 font-medium">{{ l.produit_nom }}</td>
                        <td class="py-3 text-center tabular-nums text-muted-foreground">{{ l.quantite_chargee }}</td>
                        <td class="py-3 px-2">
                            <InputNumber
                                v-model="receptionLignes[idx].quantite_recue"
                                :min="0"
                                :use-grouping="false"
                                class="w-full"
                                input-class="w-full text-center"
                                @update:model-value="() => {
                                    if (receptionLignes[idx].quantite_recue === l.quantite_chargee) receptionLignes[idx].ecart_type = 'conforme';
                                    else if ((receptionLignes[idx].quantite_recue ?? 0) < (l.quantite_chargee ?? 0)) receptionLignes[idx].ecart_type = 'manquant';
                                    else receptionLignes[idx].ecart_type = 'surplus';
                                }"
                            />
                        </td>
                        <td class="py-3 text-center tabular-nums font-semibold" :class="ecartReception(idx) === 0 ? 'text-muted-foreground' : ecartReception(idx) < 0 ? 'text-red-600' : 'text-amber-600'">
                            {{ ecartReception(idx) > 0 ? '+' : '' }}{{ ecartReception(idx) }}
                        </td>
                        <td class="py-3 px-2">
                            <Dropdown
                                v-model="receptionLignes[idx].ecart_type"
                                :options="types_ecart"
                                option-label="label"
                                option-value="value"
                                class="w-full"
                            />
                        </td>
                        <td class="py-3 px-2">
                            <InputText
                                v-model="receptionLignes[idx].ecart_motif"
                                placeholder="Motif (optionnel)…"
                                class="w-full"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
            <template #footer>
                <Button variant="outline" :disabled="processing" @click="showReceptionDialog = false">Annuler</Button>
                <Button :disabled="processing" @click="submitReception">
                    <PackageCheck v-if="!processing" class="mr-2 h-4 w-4" />
                    <span v-if="processing" class="mr-2 h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent inline-block" />
                    {{ processing ? 'Validation…' : 'Valider la réception' }}
                </Button>
            </template>
        </Dialog>

        <!-- ══ Dialog : Générer la commission ════════════════════════════════ -->
        <Dialog
            v-model:visible="showCommissionDialog"
            modal
            header="Générer la commission logistique"
            :style="{ width: '480px' }"
            :draggable="false"
        >
            <div class="space-y-4 py-2">
                <div>
                    <Label class="mb-1.5 block text-sm">Base de calcul</Label>
                    <Dropdown
                        v-model="commissionForm.base_calcul"
                        :options="bases_calcul"
                        option-label="label"
                        option-value="value"
                        class="w-full"
                    />
                    <p v-if="commissionForm.errors.base_calcul" class="mt-1 text-xs text-destructive">
                        {{ commissionForm.errors.base_calcul }}
                    </p>
                </div>
                <div>
                    <Label class="mb-1.5 block text-sm">Valeur de base (GNF)</Label>
                    <InputNumber
                        v-model="commissionForm.valeur_base"
                        :min="0"
                        class="w-full"
                        input-class="w-full"
                    />
                    <p v-if="commissionForm.errors.valeur_base" class="mt-1 text-xs text-destructive">
                        {{ commissionForm.errors.valeur_base }}
                    </p>
                </div>
                <div v-if="needsQuantite">
                    <Label class="mb-1.5 block text-sm">Quantité de référence</Label>
                    <InputNumber
                        v-model="commissionForm.quantite_reference"
                        :min="1"
                        :use-grouping="false"
                        class="w-full"
                        input-class="w-full"
                    />
                    <p v-if="commissionForm.errors.quantite_reference" class="mt-1 text-xs text-destructive">
                        {{ commissionForm.errors.quantite_reference }}
                    </p>
                </div>
                <p v-if="commissionForm.errors.commission" class="text-xs text-destructive">
                    {{ commissionForm.errors.commission }}
                </p>
            </div>
            <template #footer>
                <Button variant="outline" @click="showCommissionDialog = false">Annuler</Button>
                <Button :disabled="commissionForm.processing" @click="submitCommission">
                    {{ commissionForm.processing ? 'Calcul…' : 'Générer' }}
                </Button>
            </template>
        </Dialog>

        <!-- ══ Dialog : Enregistrer un versement ═════════════════════════════ -->
        <Dialog
            v-model:visible="showVersementDialog"
            modal
            :header="`Versement — ${selectedPart?.beneficiaire_nom}`"
            :style="{ width: '420px' }"
            :draggable="false"
        >
            <div class="space-y-4 py-2">
                <div>
                    <Label class="mb-1.5 block text-sm">Montant (GNF)</Label>
                    <InputNumber
                        v-model="versementForm.montant"
                        :min="0"
                        class="w-full"
                        input-class="w-full"
                    />
                    <p v-if="versementForm.errors.montant" class="mt-1 text-xs text-destructive">
                        {{ versementForm.errors.montant }}
                    </p>
                </div>
                <div>
                    <Label class="mb-1.5 block text-sm">Mode de paiement</Label>
                    <Dropdown
                        v-model="versementForm.mode_paiement"
                        :options="MODES_PAIEMENT"
                        option-label="label"
                        option-value="value"
                        class="w-full"
                    />
                </div>
                <div>
                    <Label class="mb-1.5 block text-sm">Note (optionnel)</Label>
                    <InputText v-model="versementForm.note" class="w-full" placeholder="Remarque…" />
                </div>
            </div>
            <template #footer>
                <Button variant="outline" @click="showVersementDialog = false">Annuler</Button>
                <Button :disabled="versementForm.processing" @click="submitVersement">
                    {{ versementForm.processing ? 'Enregistrement…' : 'Enregistrer' }}
                </Button>
            </template>
        </Dialog>


        <Dialog
            v-model:visible="showActivitesDialog"
            modal
            :dismissable-mask="true"
            :style="{ width: 'min(760px, 96vw)' }"
            :draggable="false"
        >
            <template #header>
                <div>
                    <p class="font-semibold">Historique du transfert</p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ transfert.reference }} · {{ activites.length }} activité(s)
                    </p>
                </div>
            </template>

            <div class="max-h-[70vh] overflow-y-auto pr-1">
                <div v-if="activitesTriees.length === 0" class="py-2 text-sm italic text-muted-foreground">
                    Aucune activité enregistrée.
                </div>

                <ol v-else>
                    <li
                        v-for="(activite, index) in activitesTriees"
                        :key="activite.id"
                        class="flex gap-3"
                    >
                        <!-- Colonne timeline : dot + connecteur vertical -->
                        <div class="flex w-5 flex-col items-center">
                            <span class="mt-1.5 flex h-3.5 w-3.5 shrink-0 items-center justify-center rounded-full border border-border bg-background">
                                <span :class="['h-1.5 w-1.5 rounded-full', activiteDotClass(activite.action)]" />
                            </span>
                            <span
                                v-if="index < activitesTriees.length - 1"
                                class="mt-1 w-px flex-1 bg-border"
                            />
                        </div>

                        <!-- Contenu -->
                        <div
                            class="min-w-0 flex-1 rounded-lg border border-border/70 bg-muted/20 px-3 py-2.5"
                            :class="index < activitesTriees.length - 1 ? 'mb-3' : ''"
                        >
                            <div class="flex flex-wrap items-baseline gap-x-1.5">
                                <span class="text-sm font-semibold">{{ activite.user_nom || 'Système' }}</span>
                                <span class="text-sm text-muted-foreground">{{ activite.action_label }}</span>
                                <span
                                    v-if="activite.action === 'versement_effectue' && activite.details"
                                    class="text-sm text-muted-foreground"
                                >
                                    — {{ Number(activite.details.montant).toLocaleString('fr-FR') }} GNF
                                    <span v-if="activite.details.beneficiaire">({{ activite.details.beneficiaire }})</span>
                                </span>
                            </div>
                            <time class="mt-1 block text-xs text-muted-foreground/80">{{ activite.created_at }}</time>
                        </div>
                    </li>
                </ol>
            </div>
        </Dialog>

    </AppLayout>
</template>
