<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CheckCircle,
    HandCoins,
    History,
    MoreVertical,
    Pencil,
    Truck,
    XCircle,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import InputNumber from 'primevue/inputnumber';
import Select from 'primevue/select';
import Textarea from 'primevue/textarea';
import { useToast } from 'primevue/usetoast';
import { computed, ref } from 'vue';
import ChargementDialog from './partials/ChargementDialog.vue';

// ── Types ─────────────────────────────────────────────────────────────────────
interface AuditEntry {
    id: number;
    event_code: string;
    event_label: string;
    actor_name: string;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    created_at: string;
}

interface ActiviteEntry {
    id: string;
    action: string;
    action_label: string;
    user_name: string;
    created_at: string;
    details: Record<string, unknown> | null;
}

interface Encaissement {
    id: number;
    montant: number;
    date_encaissement: string;
    heure: string | null;
    mode_paiement: string;
    mode_paiement_label: string;
    note: string | null;
    created_by: string | null;
}

interface FactureData {
    id: number;
    reference: string;
    montant_net: number;
    montant_encaisse: number;
    montant_restant: number;
    statut: string;
    statut_label: string;
    encaissements: Encaissement[];
}

interface LigneCommande {
    id: string;
    produit_id: string;
    produit_nom: string | null;
    quantite_demandee: number;
    quantite_chargee: number | null;
    quantite_livree: number | null;
    type_ecart: string | null;
    type_ecart_label: string | null;
    commentaire_ecart: string | null;
    ecart_chargement: number | null;
    prix_usine_snapshot: number;
    prix_vente_snapshot: number;
    total_ligne: number;
}

interface CommandeData {
    id: string;
    reference: string;
    statut: string;
    statut_label: string;
    statut_color: string;
    total_commande: number;
    vehicule_nom: string | null;
    client_nom: string | null;
    site_nom: string | null;
    motif_annulation: string | null;
    annulee_at: string | null;
    a_charger_at: string | null;
    chargement_demarre_at: string | null;
    chargement_valide_at: string | null;
    livree_at: string | null;
    closed_at: string | null;
    is_brouillon: boolean;
    is_a_charger: boolean;
    is_chargement_en_cours: boolean;
    is_livraison_en_cours: boolean;
    is_livree: boolean;
    is_cloturee: boolean;
    is_annulee: boolean;
    can_modifier: boolean;
    can_confirmer: boolean;
    can_demarrer_chargement: boolean;
    can_valider_chargement: boolean;
    can_annuler: boolean;
    can_encaisser: boolean;
    created_at: string;
    created_by: string | null;
    lignes: LigneCommande[];
}

// ── Props ─────────────────────────────────────────────────────────────────────
const props = defineProps<{
    commande: CommandeData;
    facture: FactureData | null;
    historiques: AuditEntry[];
    activites: ActiviteEntry[];
}>();

const toast = useToast();

// ── Breadcrumbs ───────────────────────────────────────────────────────────────
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Ventes', href: '/ventes' },
    { title: props.commande.reference, href: '#' },
];

// ── Statut couleurs ───────────────────────────────────────────────────────────
const statutCommandeColor: Record<string, string> = {
    brouillon: 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
    a_charger:
        'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
    chargement_en_cours:
        'bg-orange-100 text-orange-700 dark:bg-orange-950 dark:text-orange-300',
    livraison_en_cours:
        'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300',
    livree: 'bg-teal-100 text-teal-700 dark:bg-teal-950 dark:text-teal-300',
    cloturee:
        'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
    annulee: 'bg-red-100 text-red-600 dark:bg-red-950 dark:text-red-400',
};

// ── Formatage ─────────────────────────────────────────────────────────────────
function formatGNF(val: number | string | null | undefined): string {
    const n = Math.round(Number(val ?? 0));
    const s = n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    return s + ' GNF';
}

function ecartClass(ecart: number | null): string {
    if (ecart === null || ecart === 0) return 'text-muted-foreground';
    return ecart < 0 ? 'text-red-600' : 'text-amber-600';
}

function ecartLabel(ecart: number | null): string {
    if (ecart === null) return '—';
    if (ecart === 0) return '0';
    return (ecart > 0 ? '+' : '') + ecart;
}

// ── Audit helpers ─────────────────────────────────────────────────────────────
const auditEventTextColor: Record<string, string> = {
    created: 'text-blue-600 dark:text-blue-400',
    updated: 'text-amber-600 dark:text-amber-400',
    validated: 'text-emerald-600 dark:text-emerald-400',
    cancelled: 'text-red-600 dark:text-red-400',
    encaissement_added: 'text-violet-600 dark:text-violet-400',
    encaissement_deleted: 'text-orange-600 dark:text-orange-400',
    deleted: 'text-red-600 dark:text-red-400',
};

const auditEventVerb: Record<string, string> = {
    created: 'Créée par',
    updated: 'Modifiée par',
    validated: 'Confirmée par',
    cancelled: 'Annulée par',
    encaissement_added: 'Encaissement ajouté par',
    encaissement_deleted: 'Encaissement supprimé par',
    deleted: 'Supprimée par',
};

const AUDIT_HIDDEN_FIELDS = new Set([
    'vehicule_id',
    'client_id',
    'statut',
    'client_nom',
]);

const AUDIT_FIELD_LABELS: Record<string, string> = {
    vehicule_nom: 'Véhicule',
    total_commande: 'Total',
    montant: 'Montant',
    mode_paiement: 'Mode paiement',
    date_encaissement: 'Date encaissement',
    lignes: 'Produits',
};

interface AuditLigne {
    produit_nom?: string;
    quantite_demandee?: number;
    prix_vente_snapshot?: number;
    total_ligne?: number;
}

function formatLignes(val: unknown): string {
    if (!Array.isArray(val) || val.length === 0) return '—';
    return (val as AuditLigne[])
        .map((l) => {
            const nom = l.produit_nom ?? '?';
            const qte = l.quantite_demandee ?? 0;
            const montant = Number(
                l.total_ligne ?? qte * Number(l.prix_vente_snapshot ?? 0),
            );
            return `${nom} × ${qte} — ${formatGNF(montant)}`;
        })
        .join('\n');
}

function formatAuditValue(key: string, val: unknown): string {
    if (val === null || val === undefined) return '—';
    if (key === 'total_commande' || key === 'montant')
        return formatGNF(Number(val));
    if (key === 'lignes') return formatLignes(val);
    return String(val);
}

function auditDiffRows(
    entry: AuditEntry,
): { field: string; label: string; old: string; new: string }[] {
    const old = entry.old_values ?? {};
    const next = entry.new_values ?? {};
    const keys = new Set([...Object.keys(old), ...Object.keys(next)]);
    const rows: { field: string; label: string; old: string; new: string }[] =
        [];
    keys.forEach((k) => {
        if (AUDIT_HIDDEN_FIELDS.has(k)) return;
        rows.push({
            field: k,
            label: AUDIT_FIELD_LABELS[k] ?? k,
            old: formatAuditValue(k, old[k]),
            new: formatAuditValue(k, next[k]),
        });
    });
    return rows;
}

// ── Types écart (statiques, miroir de TypeEcartLogistique) ────────────────────
const TYPES_ECART = [
    { value: 'conforme', label: 'Conforme' },
    { value: 'casse', label: 'Casse' },
    { value: 'perte', label: 'Perte' },
    { value: 'surplus', label: 'Surplus' },
    { value: 'manquant', label: 'Manquant' },
];

// ── Actions de transition ─────────────────────────────────────────────────────
const actionProcessing = ref(false);

function confirmer() {
    if (actionProcessing.value) return;
    actionProcessing.value = true;
    router.patch(
        `/ventes/${props.commande.id}/valider`,
        {},
        {
            onSuccess: () =>
                toast.add({
                    severity: 'success',
                    summary: 'Confirmée',
                    detail: 'Commande confirmée. En attente de chargement.',
                    life: 3000,
                }),
            onFinish: () => (actionProcessing.value = false),
        },
    );
}

function demarrerChargement() {
    if (actionProcessing.value) return;
    actionProcessing.value = true;
    router.post(
        `/ventes/${props.commande.id}/statut/avancer`,
        {},
        {
            onSuccess: () =>
                toast.add({
                    severity: 'success',
                    summary: 'Chargement démarré',
                    detail: 'La facture a été créée. Le chargement peut commencer.',
                    life: 3000,
                }),
            onFinish: () => (actionProcessing.value = false),
        },
    );
}

// ── Chargement dialog ─────────────────────────────────────────────────────────
const chargementDialogVisible = ref(false);

// ── Annulation commande ───────────────────────────────────────────────────────
const MOTIFS_ANNULATION = [
    { value: 'erreur_saisie', label: 'Erreur de saisie' },
    { value: 'doublon', label: 'Doublon' },
    { value: 'rupture_stock', label: 'Rupture de stock' },
    { value: 'autre', label: 'Autre' },
] as const;

const annulerDialogVisible = ref(false);
const annulerForm = useForm({
    motif_annulation_code: '' as string,
    motif_annulation_detail: '',
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

const annulerDisabled = computed(
    () =>
        annulerForm.processing ||
        !annulerForm.motif_annulation_code ||
        (annulerForm.motif_annulation_code === 'autre' &&
            !annulerForm.motif_annulation_detail.trim()),
);

// ── Historique dialog ─────────────────────────────────────────────────────────
const historiquesDialogVisible = ref(false);

// ── Encaissement ──────────────────────────────────────────────────────────────
const modesPaiement = [
    { value: 'especes', label: 'Espèces' },
    { value: 'mobile_money', label: 'Mobile Money' },
    { value: 'virement', label: 'Virement' },
    { value: 'cheque', label: 'Chèque' },
];

const encaisserDialogVisible = ref(false);
const encaisserForm = useForm({
    montant: null as number | null,
    mode_paiement: 'especes' as string | null,
    note: '',
    date_encaissement: new Date().toISOString().slice(0, 10),
});

function openEncaisserDialog() {
    encaisserForm.reset();
    encaisserForm.montant = props.facture?.montant_restant ?? null;
    encaisserForm.date_encaissement = new Date().toISOString().slice(0, 10);
    encaisserDialogVisible.value = true;
}

function submitEncaisser() {
    if (!props.facture) return;
    encaisserForm.post(`/factures/${props.facture.id}/encaissements`, {
        onSuccess: () => {
            encaisserDialogVisible.value = false;
            toast.add({
                severity: 'success',
                summary: 'Encaissement enregistré',
                detail: `${formatGNF(encaisserForm.montant ?? 0)} enregistré avec succès.`,
                life: 3000,
            });
        },
    });
}

// ── Colonnes lignes conditionnelles ───────────────────────────────────────────
const showChargeeCol = computed(
    () => !props.commande.is_brouillon && !props.commande.is_a_charger,
);
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
                        {{ commande.created_at }}
                    </p>
                </div>
                <!-- Actions mobile -->
                <div
                    v-if="
                        commande.can_modifier ||
                        commande.can_confirmer ||
                        commande.can_demarrer_chargement ||
                        commande.can_valider_chargement ||
                        commande.can_annuler ||
                        commande.can_encaisser
                    "
                    class="absolute right-4"
                >
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button variant="ghost" size="icon" class="h-9 w-9">
                                <MoreVertical class="h-5 w-5" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-52">
                            <DropdownMenuItem
                                v-if="commande.can_modifier"
                                as-child
                            >
                                <Link
                                    :href="`/ventes/${commande.id}/edit`"
                                    class="flex w-full cursor-pointer items-center gap-2"
                                >
                                    <Pencil class="h-4 w-4" />
                                    Modifier
                                </Link>
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="commande.can_confirmer"
                                class="cursor-pointer text-blue-600 focus:text-blue-600"
                                :disabled="actionProcessing"
                                @click="confirmer"
                            >
                                <CheckCircle class="h-4 w-4" />
                                Confirmer la commande
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="commande.can_demarrer_chargement"
                                class="cursor-pointer text-orange-600 focus:text-orange-600"
                                :disabled="actionProcessing"
                                @click="demarrerChargement"
                            >
                                <Truck class="h-4 w-4" />
                                Démarrer le chargement
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="commande.can_valider_chargement"
                                class="cursor-pointer text-blue-600 focus:text-blue-600"
                                @click="chargementDialogVisible = true"
                            >
                                <CheckCircle class="h-4 w-4" />
                                Valider le chargement
                            </DropdownMenuItem>
                            <DropdownMenuSeparator
                                v-if="
                                    commande.can_annuler &&
                                    (commande.can_modifier ||
                                        commande.can_confirmer ||
                                        commande.can_demarrer_chargement ||
                                        commande.can_valider_chargement ||
                                        commande.can_encaisser)
                                "
                            />
                            <DropdownMenuItem
                                v-if="commande.can_annuler"
                                class="cursor-pointer text-amber-600 focus:text-amber-600"
                                @click="annulerDialogVisible = true"
                            >
                                <XCircle class="h-4 w-4" />
                                Annuler la commande
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>
        </div>

        <div class="mx-auto w-full max-w-5xl space-y-6 p-4 sm:p-6">
            <!-- En-tête commande ─────────────────────────────────────────────── -->
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

                <!-- Boutons d'action selon statut -->
                <div class="flex items-center gap-2">
                    <!-- Historique -->
                    <Button
                        variant="ghost"
                        size="icon"
                        class="h-8 w-8 text-muted-foreground"
                        title="Historique"
                        @click="historiquesDialogVisible = true"
                    >
                        <History class="h-4 w-4" />
                    </Button>

                    <!-- Modifier (brouillon) -->
                    <Link
                        v-if="commande.can_modifier"
                        :href="`/ventes/${commande.id}/edit`"
                    >
                        <Button variant="outline" size="sm">
                            <Pencil class="mr-2 h-4 w-4" />
                            Modifier
                        </Button>
                    </Link>

                    <!-- Confirmer (brouillon) -->
                    <Button
                        v-if="commande.can_confirmer"
                        size="sm"
                        :disabled="actionProcessing"
                        @click="confirmer"
                    >
                        <CheckCircle class="mr-2 h-4 w-4" />
                        Confirmer
                    </Button>

                    <!-- Démarrer le chargement (a_charger) -->
                    <Button
                        v-if="commande.can_demarrer_chargement"
                        size="sm"
                        class="bg-orange-600 text-white hover:bg-orange-700"
                        :disabled="actionProcessing"
                        @click="demarrerChargement"
                    >
                        <Truck class="mr-2 h-4 w-4" />
                        Démarrer le chargement
                    </Button>

                    <!-- Valider le chargement (chargement_en_cours) -->
                    <Button
                        v-if="commande.can_valider_chargement"
                        size="sm"
                        @click="chargementDialogVisible = true"
                    >
                        <CheckCircle class="mr-2 h-4 w-4" />
                        Valider le chargement
                    </Button>

                    <!-- Encaisser -->
                    <Button
                        v-if="commande.can_encaisser"
                        variant="outline"
                        size="sm"
                        @click="openEncaisserDialog"
                    >
                        <HandCoins class="mr-2 h-4 w-4" />
                        Encaisser
                    </Button>

                    <!-- Annuler (brouillon ou a_charger, admin) -->
                    <template v-if="commande.can_annuler">
                        <Button
                            variant="outline"
                            size="sm"
                            class="border-amber-300 text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-950"
                            @click="annulerDialogVisible = true"
                        >
                            <XCircle class="mr-2 h-4 w-4" />
                            Annuler
                        </Button>
                    </template>
                </div>
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

                <!-- Timeline statuts -->
                <div
                    class="mt-4 flex flex-wrap gap-4 border-t pt-4 text-xs text-muted-foreground"
                >
                    <span v-if="commande.a_charger_at">
                        Confirmée le
                        <strong>{{ commande.a_charger_at }}</strong>
                    </span>
                    <span v-if="commande.chargement_demarre_at">
                        Chargement démarré le
                        <strong>{{ commande.chargement_demarre_at }}</strong>
                    </span>
                    <span v-if="commande.chargement_valide_at">
                        Chargement validé le
                        <strong>{{ commande.chargement_valide_at }}</strong>
                    </span>
                    <span v-if="commande.livree_at">
                        Livrée le <strong>{{ commande.livree_at }}</strong>
                    </span>
                    <span v-if="commande.closed_at">
                        Clôturée le <strong>{{ commande.closed_at }}</strong>
                    </span>
                    <span v-if="commande.created_by">
                        par <strong>{{ commande.created_by }}</strong>
                    </span>
                </div>

                <!-- Motif annulation -->
                <div
                    v-if="commande.is_annulee && commande.motif_annulation"
                    class="mt-4 rounded-lg bg-red-50 p-4 dark:bg-red-950/30"
                >
                    <p
                        class="mb-1 text-xs font-medium tracking-wider text-red-600 uppercase dark:text-red-400"
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
                                    Demandée
                                </th>
                                <th
                                    v-if="showChargeeCol"
                                    class="px-4 py-2.5 text-center font-medium text-muted-foreground"
                                    style="width: 80px"
                                >
                                    Chargée
                                </th>
                                <th
                                    v-if="showChargeeCol"
                                    class="px-4 py-2.5 text-center font-medium text-muted-foreground"
                                    style="width: 70px"
                                >
                                    Écart
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
                                    {{ ligne.quantite_demandee }}
                                </td>
                                <td
                                    v-if="showChargeeCol"
                                    class="px-4 py-3 text-center tabular-nums"
                                >
                                    {{ ligne.quantite_chargee ?? '—' }}
                                </td>
                                <td
                                    v-if="showChargeeCol"
                                    class="px-4 py-3 text-center font-semibold tabular-nums"
                                    :class="ecartClass(ligne.ecart_chargement)"
                                >
                                    {{ ecartLabel(ligne.ecart_chargement) }}
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
                                    :colspan="showChargeeCol ? 5 : 3"
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

            <!-- Facturation -->
            <div
                v-if="facture"
                class="rounded-xl border bg-card p-4 shadow-sm sm:p-5"
            >
                <div class="mb-5 flex items-center justify-between">
                    <h3
                        class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Facturation
                    </h3>
                    <div class="flex items-center gap-2">
                        <Button
                            v-if="commande.can_encaisser"
                            size="sm"
                            @click="openEncaisserDialog"
                        >
                            <HandCoins class="mr-2 h-4 w-4" />
                            Encaisser
                        </Button>
                        <span
                            class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                            :class="{
                                'bg-red-100 text-red-600 dark:bg-red-950 dark:text-red-400':
                                    facture.statut === 'impayee',
                                'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300':
                                    facture.statut === 'partiel',
                                'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300':
                                    facture.statut === 'payee',
                                'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400':
                                    facture.statut === 'annulee',
                            }"
                        >
                            {{ facture.statut_label }}
                        </span>
                    </div>
                </div>

                <!-- KPIs -->
                <div class="mb-6 grid grid-cols-3 gap-3">
                    <div class="rounded-lg border bg-muted/30 px-4 py-3">
                        <p class="text-xs text-muted-foreground">
                            Total facturé
                        </p>
                        <p class="mt-0.5 text-lg font-bold tabular-nums">
                            {{ formatGNF(facture.montant_net) }}
                        </p>
                    </div>
                    <div class="rounded-lg border bg-muted/30 px-4 py-3">
                        <p class="text-xs text-muted-foreground">
                            Déjà encaissé
                        </p>
                        <p class="mt-0.5 text-lg font-bold tabular-nums">
                            {{ formatGNF(facture.montant_encaisse) }}
                        </p>
                    </div>
                    <div class="rounded-lg border bg-muted/30 px-4 py-3">
                        <p class="text-xs text-muted-foreground">Restant dû</p>
                        <p class="mt-0.5 text-lg font-bold tabular-nums">
                            {{ formatGNF(facture.montant_restant) }}
                        </p>
                    </div>
                </div>

                <!-- Historique encaissements -->
                <div v-if="facture.encaissements.length > 0">
                    <p
                        class="mb-3 text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Historique des encaissements
                    </p>
                    <div class="overflow-hidden rounded-lg border">
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
                                        Heure
                                    </th>
                                    <th
                                        class="px-4 py-2.5 text-left font-medium text-muted-foreground"
                                    >
                                        Mode
                                    </th>
                                    <th
                                        class="px-4 py-2.5 text-right font-medium text-muted-foreground"
                                    >
                                        Montant
                                    </th>
                                    <th
                                        class="hidden px-4 py-2.5 text-left font-medium text-muted-foreground sm:table-cell"
                                    >
                                        Par
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr
                                    v-for="enc in facture.encaissements"
                                    :key="enc.id"
                                    class="hover:bg-muted/10"
                                >
                                    <td class="px-4 py-3 tabular-nums">
                                        {{ enc.date_encaissement }}
                                    </td>
                                    <td
                                        class="px-4 py-3 text-muted-foreground tabular-nums"
                                    >
                                        {{ enc.heure ?? '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-muted-foreground">
                                        {{ enc.mode_paiement_label }}
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right font-semibold tabular-nums"
                                    >
                                        {{ formatGNF(enc.montant) }}
                                    </td>
                                    <td
                                        class="hidden px-4 py-3 text-muted-foreground sm:table-cell"
                                    >
                                        {{ enc.created_by ?? '—' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <p v-else class="text-sm text-muted-foreground">
                    Aucun encaissement enregistré.
                </p>
            </div>

            <!-- Journal d'activité -->
            <div
                v-if="activites.length > 0"
                class="rounded-xl border bg-card p-4 shadow-sm sm:p-5"
            >
                <h3
                    class="mb-5 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    Journal d'activité
                </h3>
                <ol class="relative border-l border-border">
                    <li
                        v-for="act in activites"
                        :key="act.id"
                        class="mb-5 ml-4 last:mb-0"
                    >
                        <span
                            class="absolute -left-1.5 mt-1.5 h-3 w-3 rounded-full border border-background bg-primary"
                        />
                        <div
                            class="flex flex-wrap items-baseline gap-1 text-xs"
                        >
                            <strong class="text-foreground">{{
                                act.user_name
                            }}</strong>
                            <span class="text-muted-foreground">{{
                                act.action_label
                            }}</span>
                            <span class="text-muted-foreground"
                                >— {{ act.created_at }}</span
                            >
                        </div>
                        <p
                            v-if="act.details?.motif"
                            class="mt-1 text-xs text-muted-foreground"
                        >
                            Motif : {{ act.details.motif }}
                        </p>
                    </li>
                </ol>
            </div>
        </div>

        <!-- Dialog Historique audit -->
        <Dialog
            v-model:visible="historiquesDialogVisible"
            modal
            header="Historique"
            :style="{ width: '860px' }"
        >
            <div
                v-if="historiques.length === 0"
                class="py-6 text-center text-sm text-muted-foreground"
            >
                Aucun historique disponible.
            </div>

            <ol v-else class="relative border-l border-border">
                <li
                    v-for="entry in historiques"
                    :key="entry.id"
                    class="mb-6 ml-4 last:mb-0"
                >
                    <span
                        class="absolute -left-1.5 mt-1.5 h-3 w-3 rounded-full border border-background bg-border"
                    />
                    <div class="flex flex-wrap items-baseline gap-1 text-xs">
                        <span
                            class="font-semibold"
                            :class="
                                auditEventTextColor[entry.event_code] ??
                                'text-muted-foreground'
                            "
                        >
                            {{
                                auditEventVerb[entry.event_code] ??
                                entry.event_label
                            }}
                        </span>
                        <strong>{{ entry.actor_name }}</strong>
                        <span class="text-muted-foreground"
                            >— {{ entry.created_at }}</span
                        >
                    </div>

                    <div
                        v-if="
                            (entry.old_values &&
                                Object.keys(entry.old_values).length > 0) ||
                            (entry.new_values &&
                                Object.keys(entry.new_values).length > 0)
                        "
                        class="mt-2 overflow-hidden rounded-lg border text-xs"
                    >
                        <table class="w-full">
                            <thead>
                                <tr class="border-b bg-muted/40">
                                    <th
                                        class="px-3 py-1.5 text-left font-medium text-muted-foreground"
                                    >
                                        Champ
                                    </th>
                                    <th
                                        v-if="entry.old_values"
                                        class="px-3 py-1.5 text-left font-medium text-muted-foreground"
                                    >
                                        Avant
                                    </th>
                                    <th
                                        v-if="entry.new_values"
                                        class="px-3 py-1.5 text-left font-medium text-muted-foreground"
                                    >
                                        Après
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr
                                    v-for="row in auditDiffRows(entry)"
                                    :key="row.field"
                                    class="hover:bg-muted/10"
                                >
                                    <td
                                        class="px-3 py-1.5 font-medium text-muted-foreground"
                                    >
                                        {{ row.label }}
                                    </td>
                                    <td
                                        v-if="entry.old_values"
                                        class="px-3 py-1.5 whitespace-pre-line"
                                    >
                                        {{ row.old }}
                                    </td>
                                    <td
                                        v-if="entry.new_values"
                                        class="px-3 py-1.5 whitespace-pre-line"
                                    >
                                        {{ row.new }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </li>
            </ol>
        </Dialog>

        <!-- Dialog Encaissement -->
        <Dialog
            v-model:visible="encaisserDialogVisible"
            modal
            header="Encaisser un paiement"
            :style="{ width: '440px' }"
        >
            <div class="space-y-4">
                <div v-if="facture" class="rounded-lg bg-primary/10 px-4 py-3">
                    <p class="text-xs text-primary">Restant dû</p>
                    <p class="text-xl font-bold text-primary tabular-nums">
                        {{ formatGNF(facture.montant_restant) }}
                    </p>
                </div>
                <div>
                    <Label for="enc-montant" class="mb-1.5 block text-sm">
                        Montant <span class="text-destructive">*</span>
                    </Label>
                    <InputNumber
                        id="enc-montant"
                        v-model="encaisserForm.montant"
                        :max="facture?.montant_restant"
                        :min="1"
                        :use-grouping="true"
                        locale="fr-FR"
                        suffix=" GNF"
                        class="w-full"
                        fluid
                        :class="{ 'p-invalid': encaisserForm.errors.montant }"
                    />
                    <p
                        v-if="encaisserForm.errors.montant"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ encaisserForm.errors.montant }}
                    </p>
                </div>
                <div>
                    <Label for="enc-mode" class="mb-1.5 block text-sm">
                        Mode de paiement <span class="text-destructive">*</span>
                    </Label>
                    <Select
                        id="enc-mode"
                        v-model="encaisserForm.mode_paiement"
                        :options="modesPaiement"
                        option-label="label"
                        option-value="value"
                        placeholder="Sélectionner"
                        class="w-full"
                        fluid
                        :class="{
                            'p-invalid': encaisserForm.errors.mode_paiement,
                        }"
                    />
                    <p
                        v-if="encaisserForm.errors.mode_paiement"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ encaisserForm.errors.mode_paiement }}
                    </p>
                </div>
            </div>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <Button
                        variant="outline"
                        @click="encaisserDialogVisible = false"
                        >Annuler</Button
                    >
                    <Button
                        :disabled="
                            encaisserForm.processing ||
                            !encaisserForm.montant ||
                            !encaisserForm.mode_paiement
                        "
                        @click="submitEncaisser"
                    >
                        <HandCoins class="mr-2 h-4 w-4" />
                        {{
                            encaisserForm.processing
                                ? 'Enregistrement…'
                                : 'Confirmer'
                        }}
                    </Button>
                </div>
            </template>
        </Dialog>

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
                    >. Cette action est irréversible.
                </p>
                <div>
                    <Label
                        for="annulation-motif-code"
                        class="mb-1.5 block text-sm"
                    >
                        Motif <span class="text-destructive">*</span>
                    </Label>
                    <Select
                        id="annulation-motif-code"
                        v-model="annulerForm.motif_annulation_code"
                        :options="MOTIFS_ANNULATION"
                        option-label="label"
                        option-value="value"
                        placeholder="Sélectionner un motif"
                        class="w-full"
                        fluid
                        :class="{
                            'p-invalid':
                                annulerForm.errors.motif_annulation_code,
                        }"
                    />
                    <p
                        v-if="annulerForm.errors.motif_annulation_code"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ annulerForm.errors.motif_annulation_code }}
                    </p>
                </div>
                <div v-if="annulerForm.motif_annulation_code === 'autre'">
                    <Label
                        for="annulation-motif-detail"
                        class="mb-1.5 block text-sm"
                    >
                        Précision <span class="text-destructive">*</span>
                    </Label>
                    <Textarea
                        id="annulation-motif-detail"
                        v-model="annulerForm.motif_annulation_detail"
                        rows="3"
                        class="w-full"
                        placeholder="Indiquez la raison de l'annulation..."
                        :class="{
                            'p-invalid':
                                annulerForm.errors.motif_annulation_detail,
                        }"
                    />
                    <p
                        v-if="annulerForm.errors.motif_annulation_detail"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ annulerForm.errors.motif_annulation_detail }}
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
                        :disabled="annulerDisabled"
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

        <!-- Dialog Chargement -->
        <ChargementDialog
            v-model:visible="chargementDialogVisible"
            :commande-id="commande.id"
            :lignes="commande.lignes"
            :types-ecart="TYPES_ECART"
        />
    </AppLayout>
</template>
