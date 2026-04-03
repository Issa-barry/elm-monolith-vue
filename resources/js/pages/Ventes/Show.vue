<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Plus, Trash2, XCircle } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import Textarea from 'primevue/textarea';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed, ref } from 'vue';

// ── Types ─────────────────────────────────────────────────────────────────────
interface LigneCommande {
    id: number;
    produit_id: number;
    produit_nom: string | null;
    qte: number;
    prix_usine_snapshot: number;
    prix_vente_snapshot: number;
    total_ligne: number;
}

interface Encaissement {
    id: number;
    montant: number;
    date_encaissement: string;
    mode_paiement: string;
    mode_paiement_label: string;
    note: string | null;
    created_by: string | null;
    created_at: string;
}

interface FactureData {
    id: number;
    reference: string;
    montant_brut: number;
    montant_net: number;
    montant_encaisse: number;
    montant_restant: number;
    statut_facture: string;
    statut_label: string;
    is_annulee: boolean;
    is_payee: boolean;
    encaissements: Encaissement[];
}

interface CommandeData {
    id: number;
    reference: string;
    statut: string;
    statut_label: string;
    total_commande: number;
    vehicule_nom: string | null;
    client_nom: string | null;
    site_nom: string | null;
    motif_annulation: string | null;
    annulee_at: string | null;
    is_annulee: boolean;
    created_at: string;
    created_by: string | null;
    lignes: LigneCommande[];
}

interface ModePaiementOption {
    value: string;
    label: string;
}

// ── Props ─────────────────────────────────────────────────────────────────────
const props = defineProps<{
    commande: CommandeData;
    facture: FactureData | null;
    modes_paiement: ModePaiementOption[];
}>();

const { can } = usePermissions();
const confirm = useConfirm();
const toast = useToast();

// ── Breadcrumbs ───────────────────────────────────────────────────────────────
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Ventes', href: '/ventes' },
    { title: props.commande.reference, href: '#' },
];

// ── Statut couleurs ───────────────────────────────────────────────────────────
const statutCommandeColor: Record<string, string> = {
    en_cours: 'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300',
    livree: 'bg-purple-100 text-purple-700 dark:bg-purple-950 dark:text-purple-300',
    cloturee:
        'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
    annulee: 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
};

const statutFactureColor: Record<string, string> = {
    impayee:
        'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
    partiel: 'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300',
    payee: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
    annulee: 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
};

// ── Formatage ─────────────────────────────────────────────────────────────────
function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}

function formatDate(val: string | null): string {
    if (!val) return '—';
    return new Date(val).toLocaleDateString('fr-FR');
}

function formatDateTime(val: string | null): string {
    if (!val) return '—';
    return new Date(val).toLocaleString('fr-FR');
}

// ── Annulation commande ───────────────────────────────────────────────────────
const annulerDialogVisible = ref(false);
const annulerForm = useForm({
    motif_annulation: '',
});

function submitAnnuler() {
    annulerForm.patch(`/ventes/${props.commande.id}/annuler`, {
        onSuccess: () => {
            annulerDialogVisible.value = false;
            toast.add({
                severity: 'success',
                summary: 'Annulée',
                detail: 'Commande annulée avec succès.',
                life: 3000,
            });
        },
    });
}

// ── Encaissement ──────────────────────────────────────────────────────────────
const encaissementForm = useForm({
    montant: props.facture
        ? props.facture.montant_restant
        : (0 as number | null),
    date_encaissement: new Date().toISOString().slice(0, 10),
    mode_paiement: 'especes',
    note: null as string | null,
});

const canAddEncaissement = computed(
    () =>
        props.facture !== null &&
        !props.facture.is_annulee &&
        props.facture.montant_restant > 0 &&
        can('ventes.update'),
);

function submitEncaissement() {
    if (!props.facture) return;
    encaissementForm.post(`/factures/${props.facture.id}/encaissements`, {
        onSuccess: () => {
            encaissementForm.reset();
            encaissementForm.montant = props.facture
                ? props.facture.montant_restant
                : 0;
            encaissementForm.date_encaissement = new Date()
                .toISOString()
                .slice(0, 10);
            encaissementForm.mode_paiement = 'especes';
        },
    });
}

// ── Suppression encaissement ──────────────────────────────────────────────────
function confirmDeleteEncaissement(e: Encaissement) {
    confirm.require({
        message: `Supprimer cet encaissement de ${formatGNF(e.montant)} ?`,
        header: 'Confirmer la suppression',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Annuler',
        acceptLabel: 'Supprimer',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(`/encaissements/${e.id}`, {
                onSuccess: () =>
                    toast.add({
                        severity: 'success',
                        summary: 'Supprimé',
                        detail: 'Encaissement supprimé.',
                        life: 3000,
                    }),
            });
        },
    });
}

// ── Progression paiement ──────────────────────────────────────────────────────
const progressPercent = computed(() => {
    if (!props.facture || props.facture.montant_net <= 0) return 0;
    return Math.min(
        100,
        Math.round(
            (props.facture.montant_encaisse / props.facture.montant_net) * 100,
        ),
    );
});
</script>

<template>
    <Head :title="commande.reference" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- Mobile sticky header -->
        <div
            class="sticky top-0 z-20 border-b border-border/60 bg-background/95 backdrop-blur-sm sm:hidden"
        >
            <div class="relative flex items-center justify-center px-4 py-3">
                <Link
                    href="/ventes"
                    class="absolute left-4 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground transition-transform active:scale-95"
                >
                    <ArrowLeft class="h-4 w-4" />
                </Link>
                <div class="text-center">
                    <h1 class="text-[17px] leading-tight font-semibold">
                        {{ commande.reference }}
                    </h1>
                    <p class="text-[11px] text-muted-foreground">
                        {{ commande.date }}
                    </p>
                </div>
            </div>
        </div>

        <div class="mx-auto max-w-5xl space-y-6 p-4 sm:p-6">
            <!-- En-tête commande ──────────────────────────────────────────────── -->
            <div class="hidden items-start justify-between gap-4 sm:flex">
                <div class="flex items-start gap-4">
                    <Link href="/ventes">
                        <Button
                            variant="ghost"
                            size="icon"
                            class="mt-1 h-8 w-8"
                        >
                            <ArrowLeft class="h-4 w-4" />
                        </Button>
                    </Link>
                    <div>
                        <h1 class="font-mono text-2xl font-bold tracking-wide">
                            {{ commande.reference }}
                        </h1>
                        <div class="mt-1 flex items-center gap-2">
                            <span
                                class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="
                                    statutCommandeColor[commande.statut] ??
                                    'bg-muted text-muted-foreground'
                                "
                            >
                                {{ commande.statut_label }}
                            </span>
                            <span class="text-sm text-muted-foreground">{{
                                commande.created_at
                            }}</span>
                        </div>
                    </div>
                </div>

                <template v-if="!commande.is_annulee && can('ventes.update')">
                    <span
                        v-if="facture && facture.montant_encaisse > 0"
                        class="inline-flex items-center gap-1.5 rounded-md border border-zinc-200 bg-zinc-50 px-3 py-1.5 text-xs text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400"
                        title="Des encaissements ont déjà été enregistrés sur cette commande."
                    >
                        <XCircle class="h-4 w-4" />
                        Annulation impossible (encaissée)
                    </span>
                    <Button
                        v-else
                        variant="outline"
                        size="sm"
                        class="border-amber-300 text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-950"
                        @click="annulerDialogVisible = true"
                    >
                        <XCircle class="mr-2 h-4 w-4" />
                        Annuler la commande
                    </Button>
                </template>
            </div>

            <!-- Infos générales -->
            <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-5">
                <h3
                    class="mb-5 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    Informations
                </h3>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <p class="text-xs text-muted-foreground">Véhicule</p>
                        <p class="mt-0.5 font-medium">
                            {{ commande.vehicule_nom ?? '—' }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Client</p>
                        <p class="mt-0.5 font-medium">
                            {{ commande.client_nom ?? '—' }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Site</p>
                        <p class="mt-0.5 font-medium">
                            {{ commande.site_nom ?? '—' }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">
                            Total commande
                        </p>
                        <p class="mt-0.5 text-xl font-bold tabular-nums">
                            {{ formatGNF(commande.total_commande) }}
                        </p>
                    </div>
                </div>

                <!-- Motif annulation -->
                <div
                    v-if="commande.is_annulee && commande.motif_annulation"
                    class="mt-4 rounded-lg bg-zinc-100 p-4 dark:bg-zinc-800"
                >
                    <p
                        class="mb-1 text-xs font-medium tracking-wider text-zinc-600 uppercase dark:text-zinc-400"
                    >
                        Motif d'annulation
                    </p>
                    <p class="text-sm">{{ commande.motif_annulation }}</p>
                </div>
            </div>

            <!-- Lignes de commande -->
            <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-5">
                <h3
                    class="mb-5 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    Lignes de commande
                </h3>
                <div class="overflow-hidden overflow-x-auto rounded-lg border">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b bg-muted/40">
                                <th
                                    class="px-4 py-2.5 text-left font-medium text-muted-foreground"
                                >
                                    Produit
                                </th>
                                <th
                                    class="px-4 py-2.5 text-center font-medium text-muted-foreground"
                                    style="width: 80px"
                                >
                                    Qté
                                </th>
                                <th
                                    class="px-4 py-2.5 text-right font-medium text-muted-foreground"
                                    style="width: 150px"
                                >
                                    Prix unit.
                                </th>
                                <th
                                    class="px-4 py-2.5 text-right font-medium text-muted-foreground"
                                    style="width: 150px"
                                >
                                    Total
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="ligne in commande.lignes"
                                :key="ligne.id"
                                class="hover:bg-muted/10"
                            >
                                <td class="px-4 py-3 font-medium">
                                    {{ ligne.produit_nom ?? '—' }}
                                </td>
                                <td class="px-4 py-3 text-center tabular-nums">
                                    {{ ligne.qte }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right text-muted-foreground tabular-nums"
                                >
                                    {{ formatGNF(ligne.prix_vente_snapshot) }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right font-semibold tabular-nums"
                                >
                                    {{ formatGNF(ligne.total_ligne) }}
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t bg-muted/20">
                                <td
                                    colspan="3"
                                    class="px-4 py-3 text-right text-sm font-semibold text-muted-foreground"
                                >
                                    Total
                                </td>
                                <td
                                    class="px-4 py-3 text-right text-lg font-bold tabular-nums"
                                >
                                    {{ formatGNF(commande.total_commande) }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Section facture (masquée : accessible via Menu Factures) -->
            <div
                v-if="false"
                class="rounded-xl border bg-card p-4 shadow-sm sm:p-5"
            >
                <!-- En-tête facture -->
                <div class="mb-5 flex items-center justify-between">
                    <div>
                        <h3
                            class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Facture
                        </h3>
                        <div class="mt-1 flex items-center gap-3">
                            <span class="font-mono text-lg font-bold">{{
                                facture.reference
                            }}</span>
                            <span
                                class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="
                                    statutFactureColor[
                                        facture.statut_facture
                                    ] ?? 'bg-muted text-muted-foreground'
                                "
                            >
                                {{ facture.statut_label }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Résumé financier -->
                <div class="mb-6 grid gap-4 sm:grid-cols-3">
                    <div class="rounded-lg bg-muted/30 p-4">
                        <p class="text-xs text-muted-foreground">
                            Montant total
                        </p>
                        <p class="mt-0.5 text-lg font-bold tabular-nums">
                            {{ formatGNF(facture.montant_net) }}
                        </p>
                    </div>
                    <div
                        class="rounded-lg bg-emerald-50 p-4 dark:bg-emerald-950/30"
                    >
                        <p
                            class="text-xs text-emerald-600 dark:text-emerald-400"
                        >
                            Encaissé
                        </p>
                        <p
                            class="mt-0.5 text-lg font-bold text-emerald-600 tabular-nums dark:text-emerald-400"
                        >
                            {{ formatGNF(facture.montant_encaisse) }}
                        </p>
                    </div>
                    <div
                        class="rounded-lg p-4"
                        :class="
                            facture.montant_restant > 0
                                ? 'bg-amber-50 dark:bg-amber-950/30'
                                : 'bg-emerald-50 dark:bg-emerald-950/30'
                        "
                    >
                        <p
                            class="text-xs"
                            :class="
                                facture.montant_restant > 0
                                    ? 'text-amber-600 dark:text-amber-400'
                                    : 'text-emerald-600 dark:text-emerald-400'
                            "
                        >
                            Restant dû
                        </p>
                        <p
                            class="mt-0.5 text-lg font-bold tabular-nums"
                            :class="
                                facture.montant_restant > 0
                                    ? 'text-amber-600 dark:text-amber-400'
                                    : 'text-emerald-600 dark:text-emerald-400'
                            "
                        >
                            {{ formatGNF(facture.montant_restant) }}
                        </p>
                    </div>
                </div>

                <!-- Barre progression -->
                <div class="mb-6 space-y-1">
                    <div
                        class="flex items-center justify-between text-xs text-muted-foreground"
                    >
                        <span>Progression du paiement</span>
                        <span class="font-semibold"
                            >{{ progressPercent }}%</span
                        >
                    </div>
                    <div class="h-2 rounded-full bg-muted">
                        <div
                            class="h-2 rounded-full bg-emerald-500 transition-all"
                            :style="{ width: progressPercent + '%' }"
                        />
                    </div>
                </div>

                <!-- Tableau des encaissements -->
                <div
                    v-if="facture.encaissements.length > 0"
                    class="mb-6 overflow-hidden overflow-x-auto rounded-lg border"
                >
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b bg-muted/40">
                                <th
                                    class="px-4 py-2.5 text-left font-medium text-muted-foreground"
                                >
                                    Date
                                </th>
                                <th
                                    class="px-4 py-2.5 text-left font-medium text-muted-foreground"
                                >
                                    Montant
                                </th>
                                <th
                                    class="px-4 py-2.5 text-left font-medium text-muted-foreground"
                                >
                                    Mode
                                </th>
                                <th
                                    class="px-4 py-2.5 text-left font-medium text-muted-foreground"
                                >
                                    Note
                                </th>
                                <th
                                    class="px-4 py-2.5 text-left font-medium text-muted-foreground"
                                >
                                    Enregistré par
                                </th>
                                <th
                                    v-if="can('ventes.update')"
                                    class="px-4 py-2.5"
                                    style="width: 48px"
                                ></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="e in facture.encaissements"
                                :key="e.id"
                                class="hover:bg-muted/10"
                            >
                                <td class="px-4 py-3 tabular-nums">
                                    {{ formatDate(e.date_encaissement) }}
                                </td>
                                <td
                                    class="px-4 py-3 font-medium text-emerald-600 tabular-nums dark:text-emerald-400"
                                >
                                    {{ formatGNF(e.montant) }}
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ e.mode_paiement_label }}
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ e.note ?? '—' }}
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    <div>{{ e.created_by ?? '—' }}</div>
                                    <div
                                        class="text-xs text-muted-foreground/60"
                                    >
                                        {{ formatDateTime(e.created_at) }}
                                    </div>
                                </td>
                                <td
                                    v-if="can('ventes.update')"
                                    class="px-4 py-3 text-center"
                                >
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="h-7 w-7 text-destructive hover:text-destructive"
                                        type="button"
                                        @click="confirmDeleteEncaissement(e)"
                                    >
                                        <Trash2 class="h-4 w-4" />
                                    </Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    v-else-if="!canAddEncaissement"
                    class="mb-6 rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground"
                >
                    Aucun encaissement enregistré.
                </div>

                <!-- Formulaire ajout encaissement -->
                <div
                    v-if="canAddEncaissement"
                    class="rounded-lg border bg-muted/20 p-4"
                >
                    <p class="mb-4 text-sm font-medium">
                        Ajouter un encaissement
                    </p>
                    <form
                        class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4"
                        @submit.prevent="submitEncaissement"
                    >
                        <!-- Montant -->
                        <div>
                            <Label class="mb-1.5 block text-xs"
                                >Montant
                                <span class="text-destructive">*</span></Label
                            >
                            <InputNumber
                                :model-value="encaissementForm.montant"
                                @update:model-value="
                                    encaissementForm.montant = $event
                                "
                                :min="0.01"
                                :max="facture.montant_restant"
                                :use-grouping="true"
                                locale="fr-FR"
                                :min-fraction-digits="0"
                                :max-fraction-digits="2"
                                suffix=" GNF"
                                class="w-full"
                                input-class="w-full"
                                :class="{
                                    'p-invalid':
                                        encaissementForm.errors.montant,
                                }"
                            />
                            <p
                                v-if="encaissementForm.errors.montant"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ encaissementForm.errors.montant }}
                            </p>
                        </div>

                        <!-- Date -->
                        <div>
                            <Label class="mb-1.5 block text-xs"
                                >Date
                                <span class="text-destructive">*</span></Label
                            >
                            <InputText
                                v-model="encaissementForm.date_encaissement"
                                type="date"
                                class="w-full"
                                :class="{
                                    'p-invalid':
                                        encaissementForm.errors
                                            .date_encaissement,
                                }"
                            />
                            <p
                                v-if="encaissementForm.errors.date_encaissement"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ encaissementForm.errors.date_encaissement }}
                            </p>
                        </div>

                        <!-- Mode paiement -->
                        <div>
                            <Label class="mb-1.5 block text-xs"
                                >Mode de paiement
                                <span class="text-destructive">*</span></Label
                            >
                            <Dropdown
                                v-model="encaissementForm.mode_paiement"
                                :options="modes_paiement"
                                option-label="label"
                                option-value="value"
                                class="w-full"
                                :class="{
                                    'p-invalid':
                                        encaissementForm.errors.mode_paiement,
                                }"
                            />
                            <p
                                v-if="encaissementForm.errors.mode_paiement"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ encaissementForm.errors.mode_paiement }}
                            </p>
                        </div>

                        <!-- Note -->
                        <div>
                            <Label class="mb-1.5 block text-xs">Note</Label>
                            <InputText
                                v-model="encaissementForm.note as string"
                                class="w-full"
                                placeholder="Optionnel..."
                            />
                        </div>

                        <!-- Bouton submit -->
                        <div
                            class="flex justify-end sm:col-span-2 lg:col-span-4"
                        >
                            <Button
                                type="submit"
                                size="sm"
                                :disabled="encaissementForm.processing"
                            >
                                <Plus class="mr-2 h-4 w-4" />
                                {{
                                    encaissementForm.processing
                                        ? 'Enregistrement…'
                                        : "Ajouter l'encaissement"
                                }}
                            </Button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Dialog Annulation -->
        <Dialog
            v-model:visible="annulerDialogVisible"
            modal
            header="Annuler la commande"
            :style="{ width: '480px' }"
        >
            <div class="space-y-4">
                <p class="text-sm text-muted-foreground">
                    Vous êtes sur le point d'annuler la commande
                    <span class="font-mono font-semibold">{{
                        commande.reference
                    }}</span
                    >. Cette action est irréversible et annulera également la
                    facture associée.
                </p>
                <div>
                    <Label class="mb-1.5 block text-sm">
                        Motif d'annulation
                        <span class="text-destructive">*</span>
                    </Label>
                    <Textarea
                        v-model="annulerForm.motif_annulation"
                        rows="4"
                        class="w-full"
                        placeholder="Indiquez la raison de l'annulation..."
                        :class="{
                            'p-invalid': annulerForm.errors.motif_annulation,
                        }"
                    />
                    <p
                        v-if="annulerForm.errors.motif_annulation"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ annulerForm.errors.motif_annulation }}
                    </p>
                </div>
            </div>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <Button
                        variant="outline"
                        @click="annulerDialogVisible = false"
                        >Retour</Button
                    >
                    <Button
                        variant="destructive"
                        :disabled="
                            annulerForm.processing ||
                            !annulerForm.motif_annulation.trim()
                        "
                        @click="submitAnnuler"
                    >
                        <XCircle class="mr-2 h-4 w-4" />
                        {{
                            annulerForm.processing
                                ? 'Annulation…'
                                : "Confirmer l'annulation"
                        }}
                    </Button>
                </div>
            </template>
        </Dialog>
    </AppLayout>
</template>
