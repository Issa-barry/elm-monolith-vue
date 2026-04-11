<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CheckCircle,
    ChevronRight,
    HandCoins,
    History,
    MapPin,
    Pencil,
    Truck,
    XCircle,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import { useToast } from 'primevue/usetoast';
import { computed, ref } from 'vue';

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

interface StatutOption { value: string; label: string }
interface TypeEcartOption { value: string; label: string }
interface BaseCalculOption { value: string; label: string }

// ── Props ─────────────────────────────────────────────────────────────────────

const props = defineProps<{
    transfert: Transfert;
    statuts: StatutOption[];
    types_ecart: TypeEcartOption[];
    bases_calcul: BaseCalculOption[];
    can_avancer: boolean;
    can_annuler: boolean;
    can_update: boolean;
    can_generer_commission: boolean;
    can_verser_commission: boolean;
}>();

const toast = useToast();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Logistique', href: '/logistique' },
    { title: props.transfert.reference, href: `/logistique/${props.transfert.id}` },
];

// ── Formatage ─────────────────────────────────────────────────────────────────

function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}

// ── Timeline des statuts ──────────────────────────────────────────────────────

const ETAPES = [
    { key: 'brouillon',   label: 'Brouillon',     dot: 'bg-zinc-400 dark:bg-zinc-500' },
    { key: 'preparation', label: 'Préparation',   dot: 'bg-blue-400' },
    { key: 'chargement',  label: 'Chargement',    dot: 'bg-amber-400' },
    { key: 'transit',     label: 'En transit',    dot: 'bg-blue-500' },
    { key: 'reception',   label: 'Réception',     dot: 'bg-amber-500' },
    { key: 'cloture',     label: 'Clôturé',       dot: 'bg-emerald-500' },
];

const ETAPE_INDEX: Record<string, number> = Object.fromEntries(
    ETAPES.map((e, i) => [e.key, i]),
);

const statutCourantIndex = computed(
    () => ETAPE_INDEX[props.transfert.statut] ?? -1,
);

const ETAPE_SUIVANTE_LABEL: Record<string, string> = {
    brouillon:   'Passer en préparation',
    preparation: 'Démarrer le chargement',
    chargement:  'Mettre en transit',
    transit:     'Marquer en réception',
    reception:   'Clôturer le transfert',
};

const labelEtapeSuivante = computed(
    () => ETAPE_SUIVANTE_LABEL[props.transfert.statut] ?? '',
);

// ── Avancement de statut ──────────────────────────────────────────────────────

const avancerDialogVisible = ref(false);

// Lignes éditables (chargement / réception)
const lignesForm = ref(
    props.transfert.lignes.map((l) => ({
        id: l.id,
        quantite_chargee: l.quantite_chargee ?? null as number | null,
        quantite_recue:   l.quantite_recue ?? null as number | null,
        ecart_type:       l.ecart_type ?? null as string | null,
        ecart_motif:      l.ecart_motif ?? '',
    })),
);

// Besoin de saisir des quantités chargées ?
const needsChargement = computed(() => props.transfert.statut === 'chargement');
// Besoin de saisir des réceptions ?
const needsReception = computed(() => props.transfert.statut === 'reception');
const needsLignesInput = computed(() => needsChargement.value || needsReception.value);

const avancerProcessing = ref(false);

function submitAvancer() {
    avancerProcessing.value = true;

    const payload: Record<string, unknown> = {};

    if (needsLignesInput.value) {
        payload.lignes = lignesForm.value.map((l) => ({
            id: l.id,
            ...(needsChargement.value ? { quantite_chargee: l.quantite_chargee } : {}),
            ...(needsReception.value ? {
                quantite_recue: l.quantite_recue,
                ecart_type: l.ecart_type,
                ecart_motif: l.ecart_motif,
            } : {}),
        }));
    }

    router.post(
        `/logistique/${props.transfert.id}/statut/avancer`,
        payload,
        {
            onSuccess: () => {
                avancerDialogVisible.value = false;
                toast.add({ severity: 'success', summary: 'Statut mis à jour', life: 3000 });
            },
            onError: () => {},
            onFinish: () => { avancerProcessing.value = false; },
        },
    );
}

function annuler() {
    router.post(`/logistique/${props.transfert.id}/statut/annuler`, {}, {
        onSuccess: () =>
            toast.add({ severity: 'warn', summary: 'Transfert annulé', life: 3000 }),
    });
}

// ── Commission — génération ────────────────────────────────────────────────────

const commissionDialogVisible = ref(false);

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
            commissionDialogVisible.value = false;
            toast.add({ severity: 'success', summary: 'Commission générée', life: 3000 });
        },
    });
}

// ── Commission — versement ─────────────────────────────────────────────────────

const versementDialogVisible = ref(false);
const selectedPart = ref<CommissionPart | null>(null);

const MODES_PAIEMENT = [
    { value: 'especes', label: 'Espèces' },
    { value: 'virement', label: 'Virement' },
    { value: 'cheque', label: 'Chèque' },
    { value: 'mobile_money', label: 'Mobile Money' },
];

const versementForm = useForm({
    montant:        0 as number,
    date_versement: new Date().toISOString().split('T')[0],
    mode_paiement:  'especes',
    note:           '',
});

function openVersementDialog(part: CommissionPart) {
    selectedPart.value = part;
    versementForm.reset();
    versementForm.montant = part.montant_restant;
    versementDialogVisible.value = true;
}

function submitVersement() {
    if (!selectedPart.value) return;
    versementForm.post(
        `/commissions-logistique/parts/${selectedPart.value.id}/versements`,
        {
            onSuccess: () => {
                versementDialogVisible.value = false;
                toast.add({ severity: 'success', summary: 'Versement enregistré', life: 3000 });
            },
        },
    );
}
</script>

<template>
    <Head :title="transfert.reference" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-5xl px-4 py-6 sm:px-6 space-y-6">

            <!-- ── Header ───────────────────────────────────────────────────── -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <Link
                        href="/logistique"
                        class="flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground"
                    >
                        <ArrowLeft class="h-4 w-4" />
                    </Link>
                    <div>
                        <h1 class="font-mono text-xl font-bold tracking-wide">
                            {{ transfert.reference }}
                        </h1>
                        <p class="text-xs text-muted-foreground">
                            Créé le {{ transfert.created_at }}
                            <span v-if="transfert.createur"> par {{ transfert.createur }}</span>
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <StatusDot
                        :label="transfert.statut_label"
                        :dot-class="transfert.statut_dot_class"
                        class="text-sm font-medium"
                    />
                    <Link
                        v-if="can_update"
                        :href="`/logistique/${transfert.id}/editer`"
                    >
                        <Button variant="outline" size="sm">
                            <Pencil class="mr-1.5 h-3.5 w-3.5" />
                            Modifier
                        </Button>
                    </Link>
                    <Button
                        v-if="can_avancer && !transfert.is_terminal"
                        size="sm"
                        class="bg-blue-600 hover:bg-blue-700 text-white"
                        @click="avancerDialogVisible = true"
                    >
                        <Truck class="mr-1.5 h-3.5 w-3.5" />
                        {{ labelEtapeSuivante }}
                    </Button>
                    <Button
                        v-if="can_annuler && !transfert.is_terminal"
                        variant="outline"
                        size="sm"
                        class="border-amber-300 text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-950"
                        @click="annuler"
                    >
                        <XCircle class="mr-1.5 h-3.5 w-3.5" />
                        Annuler
                    </Button>
                </div>
            </div>

            <!-- ── Grille infos + timeline ──────────────────────────────────── -->
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

                <!-- Infos transfert -->
                <div class="lg:col-span-2 rounded-xl border bg-card p-5 shadow-sm space-y-4">
                    <h2 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                        Détails du transfert
                    </h2>

                    <div class="grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <p class="text-xs text-muted-foreground">Trajet</p>
                            <p class="font-medium flex items-center gap-1 mt-0.5">
                                <MapPin class="h-3.5 w-3.5 text-muted-foreground" />
                                {{ transfert.site_source_nom ?? '—' }}
                                <ChevronRight class="h-3.5 w-3.5" />
                                {{ transfert.site_destination_nom ?? '—' }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">Véhicule</p>
                            <p class="font-medium mt-0.5">
                                {{ transfert.vehicule_nom ?? '—' }}
                                <span v-if="transfert.immatriculation" class="font-mono text-xs text-muted-foreground ml-1">
                                    ({{ transfert.immatriculation }})
                                </span>
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">Équipe</p>
                            <p class="font-medium mt-0.5">{{ transfert.equipe_nom ?? '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">Départ prévu / réel</p>
                            <p class="font-medium mt-0.5">
                                {{ transfert.date_depart_prevue ?? '—' }}
                                <span v-if="transfert.date_depart_reelle" class="text-muted-foreground">
                                    / {{ transfert.date_depart_reelle }}
                                </span>
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">Arrivée prévue / réelle</p>
                            <p class="font-medium mt-0.5">
                                {{ transfert.date_arrivee_prevue ?? '—' }}
                                <span v-if="transfert.date_arrivee_reelle" class="text-muted-foreground">
                                    / {{ transfert.date_arrivee_reelle }}
                                </span>
                            </p>
                        </div>
                    </div>

                    <div v-if="transfert.notes" class="rounded-md bg-muted/30 px-3 py-2 text-sm text-muted-foreground italic">
                        {{ transfert.notes }}
                    </div>
                </div>

                <!-- Timeline statuts -->
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <h2 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground mb-4">
                        <History class="inline h-3.5 w-3.5 mr-1" />
                        Progression
                    </h2>
                    <div v-if="!transfert.is_annule" class="space-y-3">
                        <div
                            v-for="(etape, i) in ETAPES"
                            :key="etape.key"
                            class="flex items-center gap-2.5"
                        >
                            <span
                                class="h-2.5 w-2.5 shrink-0 rounded-full"
                                :class="[
                                    i <= statutCourantIndex ? etape.dot : 'bg-muted-foreground/25',
                                ]"
                            />
                            <span
                                class="text-sm"
                                :class="[
                                    i === statutCourantIndex
                                        ? 'font-semibold text-foreground'
                                        : i < statutCourantIndex
                                        ? 'text-muted-foreground line-through'
                                        : 'text-muted-foreground',
                                ]"
                            >
                                {{ etape.label }}
                            </span>
                            <CheckCircle
                                v-if="i < statutCourantIndex"
                                class="h-3.5 w-3.5 ml-auto text-emerald-500"
                            />
                        </div>
                    </div>
                    <div v-else class="flex items-center gap-2 text-sm text-destructive">
                        <XCircle class="h-4 w-4" />
                        Transfert annulé
                    </div>
                </div>
            </div>

            <!-- ── Lignes produits ──────────────────────────────────────────── -->
            <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
                <div class="border-b bg-muted/30 px-5 py-3">
                    <h2 class="text-sm font-semibold">Lignes produits</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b bg-muted/10">
                            <tr>
                                <th class="px-4 py-2 text-left font-medium text-muted-foreground">Produit</th>
                                <th class="px-4 py-2 text-right font-medium text-muted-foreground">Demandé</th>
                                <th class="px-4 py-2 text-right font-medium text-muted-foreground">Chargé</th>
                                <th class="px-4 py-2 text-right font-medium text-muted-foreground">Reçu</th>
                                <th class="px-4 py-2 text-right font-medium text-muted-foreground">Écart</th>
                                <th class="px-4 py-2 text-left font-medium text-muted-foreground">Type écart</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="ligne in transfert.lignes"
                                :key="ligne.id"
                                class="hover:bg-muted/10"
                            >
                                <td class="px-4 py-2.5 font-medium">{{ ligne.produit_nom }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums">{{ ligne.quantite_demandee }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums">
                                    {{ ligne.quantite_chargee ?? '—' }}
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums">
                                    {{ ligne.quantite_recue ?? '—' }}
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums font-medium"
                                    :class="ligne.ecart !== null && ligne.ecart < 0 ? 'text-destructive' : ligne.ecart !== null && ligne.ecart > 0 ? 'text-amber-600 dark:text-amber-400' : ''"
                                >
                                    {{ ligne.ecart !== null ? (ligne.ecart > 0 ? '+' : '') + ligne.ecart : '—' }}
                                </td>
                                <td class="px-4 py-2.5">
                                    <StatusDot
                                        v-if="ligne.ecart_type"
                                        :label="ligne.ecart_label"
                                        :dot-class="ligne.ecart_dot_class"
                                        class="text-xs text-muted-foreground"
                                    />
                                    <span v-else class="text-muted-foreground">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ── Commission logistique ────────────────────────────────────── -->
            <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
                <div class="flex items-center justify-between border-b bg-muted/30 px-5 py-3">
                    <h2 class="text-sm font-semibold flex items-center gap-2">
                        <HandCoins class="h-4 w-4 text-muted-foreground" />
                        Commission logistique
                    </h2>
                    <Button
                        v-if="can_generer_commission && !transfert.commission"
                        size="sm"
                        variant="outline"
                        @click="commissionDialogVisible = true"
                    >
                        Générer la commission
                    </Button>
                    <Button
                        v-else-if="can_generer_commission && transfert.commission && !transfert.commission.is_versee"
                        size="sm"
                        variant="ghost"
                        @click="commissionDialogVisible = true"
                    >
                        Recalculer
                    </Button>
                </div>

                <!-- Pas encore de commission -->
                <div
                    v-if="!transfert.commission"
                    class="flex flex-col items-center gap-3 py-12 text-muted-foreground"
                >
                    <HandCoins class="h-10 w-10 opacity-30" />
                    <p class="text-sm">
                        {{ transfert.is_cloture ? 'Aucune commission générée.' : 'La commission sera générée à la clôture du transfert.' }}
                    </p>
                </div>

                <!-- Commission existante -->
                <div v-else class="p-5 space-y-5">
                    <!-- Résumé -->
                    <div class="flex flex-wrap gap-4 text-sm">
                        <div>
                            <p class="text-xs text-muted-foreground">Base de calcul</p>
                            <p class="font-medium mt-0.5">{{ transfert.commission.base_calcul_label }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">Montant total</p>
                            <p class="font-semibold tabular-nums mt-0.5">{{ formatGNF(transfert.commission.montant_total) }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">Versé</p>
                            <p class="font-semibold tabular-nums text-emerald-600 dark:text-emerald-400 mt-0.5">
                                {{ formatGNF(transfert.commission.montant_verse) }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">Restant</p>
                            <p class="font-semibold tabular-nums mt-0.5"
                               :class="transfert.commission.montant_restant > 0 ? 'text-destructive' : 'text-muted-foreground'"
                            >
                                {{ formatGNF(transfert.commission.montant_restant) }}
                            </p>
                        </div>
                        <div class="ml-auto flex items-center">
                            <StatusDot
                                :label="transfert.commission.statut_label"
                                :dot-class="transfert.commission.statut_dot_class"
                                class="text-sm font-medium"
                            />
                        </div>
                    </div>

                    <!-- Parts -->
                    <div class="overflow-x-auto rounded-lg border">
                        <table class="w-full text-sm">
                            <thead class="border-b bg-muted/10">
                                <tr>
                                    <th class="px-4 py-2 text-left font-medium text-muted-foreground">Bénéficiaire</th>
                                    <th class="px-4 py-2 text-right font-medium text-muted-foreground">Taux</th>
                                    <th class="px-4 py-2 text-right font-medium text-muted-foreground">Montant brut</th>
                                    <th class="px-4 py-2 text-right font-medium text-muted-foreground">Frais</th>
                                    <th class="px-4 py-2 text-right font-medium text-muted-foreground">Net</th>
                                    <th class="px-4 py-2 text-right font-medium text-muted-foreground">Versé</th>
                                    <th class="px-4 py-2 text-right font-medium text-muted-foreground">Restant</th>
                                    <th class="px-4 py-2 text-left font-medium text-muted-foreground">Statut</th>
                                    <th v-if="can_verser_commission" class="px-4 py-2" />
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <template
                                    v-for="part in transfert.commission.parts"
                                    :key="part.id"
                                >
                                    <!-- Ligne part -->
                                    <tr class="hover:bg-muted/10">
                                        <td class="px-4 py-2.5">
                                            <p class="font-medium">{{ part.beneficiaire_nom }}</p>
                                            <p class="text-xs text-muted-foreground capitalize">{{ part.type_beneficiaire }}</p>
                                        </td>
                                        <td class="px-4 py-2.5 text-right tabular-nums">{{ part.taux_commission }}%</td>
                                        <td class="px-4 py-2.5 text-right tabular-nums">{{ formatGNF(part.montant_brut) }}</td>
                                        <td class="px-4 py-2.5 text-right tabular-nums text-muted-foreground">
                                            {{ part.frais_supplementaires > 0 ? '-' + formatGNF(part.frais_supplementaires) : '—' }}
                                        </td>
                                        <td class="px-4 py-2.5 text-right tabular-nums font-medium">{{ formatGNF(part.montant_net) }}</td>
                                        <td class="px-4 py-2.5 text-right tabular-nums text-emerald-600 dark:text-emerald-400">
                                            {{ formatGNF(part.montant_verse) }}
                                        </td>
                                        <td class="px-4 py-2.5 text-right tabular-nums"
                                            :class="part.montant_restant > 0 ? 'text-destructive font-medium' : 'text-muted-foreground'"
                                        >
                                            {{ formatGNF(part.montant_restant) }}
                                        </td>
                                        <td class="px-4 py-2.5">
                                            <StatusDot
                                                :label="part.statut_label"
                                                :dot-class="part.statut_dot_class"
                                                class="text-xs text-muted-foreground"
                                            />
                                        </td>
                                        <td v-if="can_verser_commission" class="px-4 py-2.5 text-right">
                                            <Button
                                                v-if="!part.is_versee"
                                                size="sm"
                                                variant="outline"
                                                class="h-7 px-2 text-xs"
                                                @click="openVersementDialog(part)"
                                            >
                                                <HandCoins class="mr-1 h-3 w-3" />
                                                Verser
                                            </Button>
                                        </td>
                                    </tr>

                                    <!-- Historique versements -->
                                    <tr
                                        v-if="part.versements.length > 0"
                                        class="bg-muted/5"
                                    >
                                        <td colspan="9" class="px-8 pb-3 pt-0">
                                            <p class="text-xs font-medium text-muted-foreground mb-1.5 flex items-center gap-1">
                                                <History class="h-3 w-3" />
                                                Versements
                                            </p>
                                            <div class="space-y-1">
                                                <div
                                                    v-for="v in part.versements"
                                                    :key="v.id"
                                                    class="flex items-center gap-3 text-xs text-muted-foreground"
                                                >
                                                    <span class="tabular-nums font-medium text-foreground">
                                                        {{ formatGNF(v.montant) }}
                                                    </span>
                                                    <span>{{ v.date_versement }}</span>
                                                    <span>{{ v.mode_paiement }}</span>
                                                    <span v-if="v.note" class="italic">{{ v.note }}</span>
                                                    <span v-if="v.created_by" class="ml-auto">par {{ v.created_by }}</span>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Dialog : Avancer statut ──────────────────────────────────────── -->
        <Dialog
            v-model:visible="avancerDialogVisible"
            modal
            :header="labelEtapeSuivante"
            :style="{ width: '560px' }"
        >
            <div class="space-y-4 py-2">
                <!-- Saisie quantités chargées -->
                <div v-if="needsChargement" class="space-y-3">
                    <p class="text-sm text-muted-foreground">
                        Renseignez les quantités effectivement chargées pour chaque produit.
                    </p>
                    <div
                        v-for="(ligne, i) in lignesForm"
                        :key="ligne.id"
                        class="flex items-center justify-between gap-4 rounded-lg border bg-muted/20 px-4 py-3"
                    >
                        <span class="text-sm font-medium">{{ transfert.lignes[i].produit_nom }}</span>
                        <div class="flex items-center gap-2">
                            <span class="text-xs text-muted-foreground">Qté chargée :</span>
                            <InputNumber
                                v-model="ligne.quantite_chargee"
                                :min="0"
                                class="w-28"
                            />
                        </div>
                    </div>
                </div>

                <!-- Saisie réceptions + écarts -->
                <div v-else-if="needsReception" class="space-y-3">
                    <p class="text-sm text-muted-foreground">
                        Renseignez les quantités reçues et qualifiez les écarts.
                    </p>
                    <div
                        v-for="(ligne, i) in lignesForm"
                        :key="ligne.id"
                        class="rounded-lg border bg-muted/20 px-4 py-3 space-y-3"
                    >
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-medium">{{ transfert.lignes[i].produit_nom }}</span>
                            <span class="text-xs text-muted-foreground">
                                Chargé : {{ transfert.lignes[i].quantite_chargee ?? '—' }}
                            </span>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div class="space-y-1.5">
                                <Label class="text-xs">Qté reçue</Label>
                                <InputNumber v-model="ligne.quantite_recue" :min="0" class="w-full" />
                            </div>
                            <div class="space-y-1.5">
                                <Label class="text-xs">Type d'écart</Label>
                                <Dropdown
                                    v-model="ligne.ecart_type"
                                    :options="types_ecart"
                                    option-label="label"
                                    option-value="value"
                                    placeholder="Qualifier…"
                                    class="w-full"
                                />
                            </div>
                        </div>
                        <div v-if="ligne.ecart_type && ligne.ecart_type !== 'conforme'" class="space-y-1.5">
                            <Label class="text-xs">Motif de l'écart</Label>
                            <InputText v-model="ligne.ecart_motif" class="w-full text-sm" placeholder="Expliquer l'écart…" />
                        </div>
                    </div>
                </div>

                <!-- Confirmation simple -->
                <p v-else class="text-sm text-muted-foreground">
                    Confirmer le passage à l'étape suivante : <strong>{{ labelEtapeSuivante }}</strong> ?
                </p>
            </div>

            <template #footer>
                <div class="flex justify-end gap-2">
                    <Button variant="outline" @click="avancerDialogVisible = false">Annuler</Button>
                    <Button
                        class="bg-blue-600 hover:bg-blue-700 text-white"
                        :disabled="avancerProcessing"
                        @click="submitAvancer"
                    >
                        <Truck class="mr-2 h-4 w-4" />
                        {{ avancerProcessing ? 'Mise à jour…' : 'Confirmer' }}
                    </Button>
                </div>
            </template>
        </Dialog>

        <!-- ── Dialog : Générer commission ─────────────────────────────────── -->
        <Dialog
            v-model:visible="commissionDialogVisible"
            modal
            header="Générer la commission logistique"
            :style="{ width: '460px' }"
        >
            <div class="space-y-4 py-2">
                <div class="space-y-1.5">
                    <Label>Base de calcul <span class="text-destructive">*</span></Label>
                    <Dropdown
                        v-model="commissionForm.base_calcul"
                        :options="bases_calcul"
                        option-label="label"
                        option-value="value"
                        class="w-full"
                    />
                </div>
                <div class="space-y-1.5">
                    <Label>
                        {{ commissionForm.base_calcul === 'par_pack' ? 'Montant par pack (GNF)' : commissionForm.base_calcul === 'par_km' ? 'Montant par km (GNF)' : 'Montant forfaitaire (GNF)' }}
                        <span class="text-destructive">*</span>
                    </Label>
                    <InputNumber
                        v-model="commissionForm.valeur_base"
                        :min="0"
                        :max-fraction-digits="0"
                        class="w-full"
                    />
                    <p v-if="commissionForm.errors.valeur_base" class="text-xs text-destructive">
                        {{ commissionForm.errors.valeur_base }}
                    </p>
                </div>
                <div v-if="needsQuantite" class="space-y-1.5">
                    <Label>
                        {{ commissionForm.base_calcul === 'par_pack' ? 'Nombre de packs livrés' : 'Kilomètres parcourus' }}
                        <span class="text-destructive">*</span>
                    </Label>
                    <InputNumber
                        v-model="commissionForm.quantite_reference"
                        :min="1"
                        class="w-full"
                    />
                    <p v-if="commissionForm.errors.quantite_reference" class="text-xs text-destructive">
                        {{ commissionForm.errors.quantite_reference }}
                    </p>
                </div>
                <!-- Aperçu montant -->
                <div
                    v-if="commissionForm.valeur_base > 0"
                    class="rounded-lg bg-muted/30 px-4 py-3 text-sm"
                >
                    <span class="text-muted-foreground">Montant estimé : </span>
                    <span class="font-semibold tabular-nums">
                        {{
                            formatGNF(
                                commissionForm.base_calcul === 'forfait'
                                    ? commissionForm.valeur_base
                                    : commissionForm.valeur_base * (commissionForm.quantite_reference ?? 0),
                            )
                        }}
                    </span>
                </div>
            </div>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <Button variant="outline" @click="commissionDialogVisible = false">Annuler</Button>
                    <Button
                        :disabled="commissionForm.processing || commissionForm.valeur_base <= 0"
                        @click="submitCommission"
                    >
                        {{ commissionForm.processing ? 'Génération…' : 'Générer' }}
                    </Button>
                </div>
            </template>
        </Dialog>

        <!-- ── Dialog : Versement ───────────────────────────────────────────── -->
        <Dialog
            v-model:visible="versementDialogVisible"
            modal
            header="Enregistrer un versement"
            :style="{ width: '420px' }"
        >
            <div v-if="selectedPart" class="space-y-4 py-2">
                <div class="rounded-lg bg-muted/30 px-4 py-3 text-sm space-y-1">
                    <p class="font-medium">{{ selectedPart.beneficiaire_nom }}</p>
                    <p class="text-muted-foreground">
                        Restant à verser : <span class="font-semibold text-foreground tabular-nums">{{ formatGNF(selectedPart.montant_restant) }}</span>
                    </p>
                </div>
                <div class="space-y-1.5">
                    <Label>Montant (GNF) <span class="text-destructive">*</span></Label>
                    <InputNumber
                        v-model="versementForm.montant"
                        :min="1"
                        :max="selectedPart.montant_restant"
                        :max-fraction-digits="0"
                        class="w-full"
                        :class="{ 'p-invalid': versementForm.errors.montant }"
                    />
                    <p v-if="versementForm.errors.montant" class="text-xs text-destructive">
                        {{ versementForm.errors.montant }}
                    </p>
                </div>
                <div class="space-y-1.5">
                    <Label>Date de versement <span class="text-destructive">*</span></Label>
                    <InputText
                        v-model="versementForm.date_versement"
                        type="date"
                        class="w-full"
                        :class="{ 'p-invalid': versementForm.errors.date_versement }"
                    />
                </div>
                <div class="space-y-1.5">
                    <Label>Mode de paiement <span class="text-destructive">*</span></Label>
                    <Dropdown
                        v-model="versementForm.mode_paiement"
                        :options="MODES_PAIEMENT"
                        option-label="label"
                        option-value="value"
                        class="w-full"
                    />
                </div>
                <div class="space-y-1.5">
                    <Label>Note (optionnel)</Label>
                    <InputText v-model="versementForm.note" class="w-full text-sm" placeholder="Référence virement, etc." />
                </div>
            </div>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <Button variant="outline" @click="versementDialogVisible = false">Annuler</Button>
                    <Button
                        :disabled="versementForm.processing || versementForm.montant <= 0"
                        @click="submitVersement"
                    >
                        <HandCoins class="mr-2 h-4 w-4" />
                        {{ versementForm.processing ? 'Enregistrement…' : 'Enregistrer' }}
                    </Button>
                </div>
            </template>
        </Dialog>
    </AppLayout>
</template>
