<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import TicketCommandeVente from '@/components/print/TicketCommandeVente.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import { useTicketPrint } from '@/composables/useTicketPrint';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CheckCircle,
    CheckCircle2,
    ExternalLink,
    FileText,
    HandCoins,
    MoreVertical,
    Package,
    PackageOpen,
    Pencil,
    Printer,
    Receipt,
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

interface VehiculeDetail {
    nom: string;
    immatriculation: string | null;
    type: string | null;
    capacite_packs: number | null;
    proprietaire_nom: string | null;
    proprietaire_telephone: string | null;
    proprietaire_code_phone_pays: string | null;
}

interface ClientDetail {
    nom: string;
    telephone: string | null;
    code_phone_pays: string | null;
    ville: string | null;
    adresse: string | null;
    cashback_eligible: boolean;
}

interface MembreEquipe {
    nom: string;
    telephone: string | null;
}

interface EquipeDetail {
    nom: string;
    taux_commission_proprietaire: number | null;
    chauffeur: MembreEquipe | null;
    convoyeurs: MembreEquipe[];
}

interface CommandeData {
    id: string;
    reference: string;
    statut: string;
    statut_label: string;
    statut_color: string;
    total_commande: number;
    vehicule_nom: string | null;
    vehicule_detail: VehiculeDetail | null;
    livreur_nom: string | null;
    livreur_telephone: string | null;
    equipe_detail: EquipeDetail | null;
    client_nom: string | null;
    client_detail: ClientDetail | null;
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
    is_facturation: boolean;
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

interface CommissionStatut {
    value: 'creee' | 'paye' | 'partiel' | 'impaye';
    label: string;
}

// ── Props ─────────────────────────────────────────────────────────────────────
const props = defineProps<{
    commande: CommandeData;
    facture: FactureData | null;
    commission_statut: CommissionStatut | null;
    historiques: AuditEntry[];
    activites: ActiviteEntry[];
}>();

const toast = useToast();

// ── Breadcrumbs ───────────────────────────────────────────────────────────────
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Ventes', href: '/backoffice/ventes' },
    { title: props.commande.reference, href: '#' },
];

// ── Popups véhicule / équipe ──────────────────────────────────────────────────
const vehiculeDialogVisible = ref(false);
const equipeDialogVisible = ref(false);
const clientDialogVisible = ref(false);

// ── Formatage ─────────────────────────────────────────────────────────────────
function formatPhone(
    tel: string | null | undefined,
    dialCode?: string | null,
): string {
    if (!tel) return '—';
    const digits = tel.replace(/\D/g, '');
    if (!digits) return '—';

    let local: string | null = null;
    let resolvedDial = dialCode?.replace(/\D/g, '') ?? null;

    // Préfixe 00224 (ex: 0022462200...)
    if (digits.startsWith('00224') && digits.length >= 14) {
        local = digits.slice(5);
        resolvedDial = '224';
        // Préfixe 224 (ex: 22462200...)
    } else if (digits.startsWith('224') && digits.length >= 12) {
        local = digits.slice(3);
        resolvedDial = '224';
        // Numéro local 9 chiffres → Guinea par défaut
    } else if (digits.length === 9) {
        local = digits;
        resolvedDial = resolvedDial ?? '224';
        // Préfixe connu passé en paramètre
    } else if (resolvedDial && digits.startsWith(resolvedDial)) {
        local = digits.slice(resolvedDial.length);
        // Heuristique : 11 chiffres → 2 chiffres indicatif + 9 local
    } else if (digits.length === 11) {
        resolvedDial = digits.slice(0, 2);
        local = digits.slice(2);
        // Heuristique : 12 chiffres non-Guinea → 3 chiffres indicatif + 9 local
    } else if (digits.length === 12) {
        resolvedDial = digits.slice(0, 3);
        local = digits.slice(3);
    }

    if (!local) return tel;

    if (resolvedDial === '224') {
        return `+224 ${local.slice(0, 3)} ${local.slice(3, 5)} ${local.slice(5, 7)} ${local.slice(7, 9)}`;
    }

    const grouped = local.match(/.{1,2}/g)?.join(' ') ?? local;
    return `+${resolvedDial} ${grouped}`;
}

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
        `/backoffice/ventes/${props.commande.id}/valider`,
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
        `/backoffice/ventes/${props.commande.id}/statut/avancer`,
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
    annulerForm.patch(`/backoffice/ventes/${props.commande.id}/annuler`, {
        onSuccess: () => {
            annulerDialogVisible.value = false;
            const flashError = (usePage().props as Record<string, any>).flash
                ?.error;
            if (flashError) {
                toast.add({
                    severity: 'error',
                    summary: 'Annulation impossible',
                    detail: flashError,
                    life: 7000,
                });
                return;
            }
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

// ── Tabs ──────────────────────────────────────────────────────────────────────
const activeTab = ref<
    'informations' | 'produits' | 'facturation' | 'journal' | 'historique'
>('informations');

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
    encaisserForm.post(
        `/backoffice/factures/${props.facture.id}/encaissements`,
        {
            onSuccess: () => {
                encaisserDialogVisible.value = false;
                toast.add({
                    severity: 'success',
                    summary: 'Encaissement enregistré',
                    detail: `${formatGNF(encaisserForm.montant ?? 0)} enregistré avec succès.`,
                    life: 3000,
                });
            },
        },
    );
}

// ── Colonnes lignes conditionnelles ───────────────────────────────────────────
const showChargeeCol = computed(
    () => !props.commande.is_brouillon && !props.commande.is_a_charger,
);

// ── Ticket impression ─────────────────────────────────────────────────────────
const page = usePage();
const orgNom = computed(
    () =>
        (
            page.props as Record<string, unknown> & {
                auth?: { user?: { organization?: { nom?: string } } };
            }
        ).auth?.user?.organization?.nom ?? 'Eau la maman',
);
const currentUserName = computed(
    () =>
        (
            page.props as Record<string, unknown> & {
                auth?: { user?: { name?: string } };
            }
        ).auth?.user?.name ?? '',
);
const ticketDialogVisible = ref(false);
const { printFromElement } = useTicketPrint();

function printTicketCommande(): void {
    printFromElement('ticket-commande-print');
}

// ── Timeline de progression ────────────────────────────────────────────────────
const STEPS = [
    { key: 'creee', shortLabel: 'Créée', icon: FileText },
    { key: 'a_charger', shortLabel: 'À charger', icon: Package },
    { key: 'chargement', shortLabel: 'Chargement en cours', icon: PackageOpen },
    { key: 'livraison', shortLabel: 'Livraison en cours', icon: Truck },
    { key: 'facturation', shortLabel: 'Facturation', icon: Receipt },
    { key: 'commissions', shortLabel: 'Commissions', icon: HandCoins },
    { key: 'cloturee', shortLabel: 'Clôturée', icon: CheckCircle2 },
];

const isCommandeDirecte = computed(() => !props.commande.vehicule_nom);

const currentStepIdx = computed(() => {
    if (props.commande.is_annulee) return -1;
    if (isCommandeDirecte.value) {
        if (props.commande.is_cloturee) return 6;
        if (props.facture?.statut === 'payee') return 5;
        return 4;
    }
    if (props.commande.is_livree) {
        return props.facture?.statut === 'payee' ? 5 : 4;
    }
    const map: Record<string, number> = {
        brouillon: 0,
        a_charger: 1,
        chargement_en_cours: 2,
        livraison_en_cours: 3,
        facturation: 4,
        cloturee: 6,
    };
    return map[props.commande.statut] ?? 0;
});

function stepState(idx: number): 'done' | 'current' | 'future' {
    const cur = currentStepIdx.value;
    if (cur === -1) return 'future';
    if (isCommandeDirecte.value && idx >= 1 && idx <= 3) return 'future';
    if (idx < cur) return 'done';
    if (idx === cur) return 'current';
    return 'future';
}

function connectorIsActive(idx: number): boolean {
    if (currentStepIdx.value === -1) return false;
    if (isCommandeDirecte.value && idx < 4) return false;
    return idx < currentStepIdx.value;
}
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
                    href="/backoffice/ventes"
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
                        commande.can_annuler
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
                                class="cursor-pointer"
                                @click="ticketDialogVisible = true"
                            >
                                <Printer class="h-4 w-4" />
                                Imprimer ticket
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                v-if="commande.can_modifier"
                                as-child
                            >
                                <Link
                                    :href="`/backoffice/ventes/${commande.id}/edit`"
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
                                        commande.can_valider_chargement)
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

        <div class="space-y-5 px-4 py-6 sm:px-6">
            <!-- En-tête commande ─────────────────────────────────────────────── -->
            <div class="hidden items-start justify-between gap-4 sm:flex">
                <div class="flex items-start gap-4">
                    <Link href="/backoffice/ventes">
                        <Button
                            variant="ghost"
                            size="icon"
                            class="mt-1 h-8 w-8"
                        >
                            <ArrowLeft class="h-4 w-4" />
                        </Button>
                    </Link>
                    <div>
                        <p
                            class="text-xs font-semibold tracking-widest text-muted-foreground uppercase"
                        >
                            Détail commande
                        </p>
                        <h1 class="font-mono text-2xl font-bold tracking-wide">
                            {{ commande.reference }}
                        </h1>
                        <div class="mt-1 flex items-center gap-2">
                            <StatusDot
                                :status="commande.statut"
                                :label="commande.statut_label"
                            />
                            <span class="text-sm text-muted-foreground">{{
                                commande.created_at
                            }}</span>
                        </div>
                    </div>
                </div>

                <!-- Boutons d'action selon statut -->
                <div class="flex items-center gap-2">
                    <!-- Imprimer ticket -->
                    <Button
                        variant="outline"
                        size="sm"
                        @click="ticketDialogVisible = true"
                    >
                        <Printer class="mr-2 h-4 w-4" />
                        Ticket
                    </Button>

                    <!-- Modifier (brouillon) -->
                    <Link
                        v-if="commande.can_modifier"
                        :href="`/backoffice/ventes/${commande.id}/edit`"
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

                    <!-- Annuler -->
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

            <!-- Timeline de progression ─────────────────────────────────────── -->
            <div class="rounded-xl border bg-card px-6 py-4 shadow-sm">
                <!-- Annulée -->
                <div
                    v-if="commande.is_annulee"
                    class="flex items-center gap-2 text-red-600 dark:text-red-400"
                >
                    <XCircle class="h-5 w-5" />
                    <span class="font-semibold"
                        >Cette commande a été annulée.</span
                    >
                </div>

                <!-- Progression normale -->
                <div v-else class="flex items-center">
                    <template v-for="(step, idx) in STEPS" :key="step.key">
                        <!-- Étape -->
                        <div
                            class="flex flex-col items-center"
                            style="min-width: 80px"
                        >
                            <div
                                :class="[
                                    'flex h-9 w-9 items-center justify-center rounded-full transition-all',
                                    stepState(idx) === 'done'
                                        ? 'bg-emerald-500 text-white shadow-sm'
                                        : '',
                                    stepState(idx) === 'current'
                                        ? 'bg-blue-600 text-white shadow-md ring-4 ring-blue-100 dark:ring-blue-900/50'
                                        : '',
                                    stepState(idx) === 'future'
                                        ? 'bg-muted text-muted-foreground'
                                        : '',
                                ]"
                            >
                                <component :is="step.icon" class="h-4 w-4" />
                            </div>
                            <span
                                :class="[
                                    'mt-1.5 text-center text-[11px] leading-tight font-medium',
                                    stepState(idx) === 'current'
                                        ? 'text-blue-600 dark:text-blue-400'
                                        : '',
                                    stepState(idx) === 'done'
                                        ? 'text-emerald-600 dark:text-emerald-400'
                                        : '',
                                    stepState(idx) === 'future'
                                        ? 'text-muted-foreground'
                                        : '',
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
                                connectorIsActive(idx)
                                    ? 'bg-emerald-400'
                                    : 'bg-border',
                            ]"
                        />
                    </template>
                </div>
            </div>

            <!-- Navigation par onglets ──────────────────────────────────────── -->
            <div class="flex border-b">
                <button
                    class="px-4 py-2 text-sm font-medium transition-colors"
                    :class="
                        activeTab === 'informations'
                            ? 'border-b-2 border-primary text-primary'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    @click="activeTab = 'informations'"
                >
                    Informations
                </button>
                <button
                    class="px-4 py-2 text-sm font-medium transition-colors"
                    :class="
                        activeTab === 'produits'
                            ? 'border-b-2 border-primary text-primary'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    @click="activeTab = 'produits'"
                >
                    Produits
                </button>
                <button
                    class="px-4 py-2 text-sm font-medium transition-colors"
                    :class="
                        activeTab === 'facturation'
                            ? 'border-b-2 border-primary text-primary'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    @click="activeTab = 'facturation'"
                >
                    Facturation
                </button>
                <button
                    class="px-4 py-2 text-sm font-medium transition-colors"
                    :class="
                        activeTab === 'journal'
                            ? 'border-b-2 border-primary text-primary'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    @click="activeTab = 'journal'"
                >
                    Journal d'activité
                </button>
                <button
                    class="px-4 py-2 text-sm font-medium transition-colors"
                    :class="
                        activeTab === 'historique'
                            ? 'border-b-2 border-primary text-primary'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    @click="activeTab = 'historique'"
                >
                    Historique
                </button>
            </div>

            <!-- Onglet : Informations ───────────────────────────────────────── -->
            <div v-if="activeTab === 'informations'">
                <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-5">
                    <div class="mb-5 flex items-center justify-between">
                        <h3
                            class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Informations
                        </h3>
                        <div class="flex items-center gap-2">
                            <StatusDot
                                :status="commande.statut"
                                :label="commande.statut_label"
                            />
                            <button
                                v-if="facture"
                                type="button"
                                class="inline-flex items-center transition-opacity"
                                :class="
                                    facture.statut !== 'payee'
                                        ? 'cursor-pointer hover:opacity-80'
                                        : 'cursor-default'
                                "
                                @click="
                                    facture.statut !== 'payee' &&
                                    (activeTab = 'facturation')
                                "
                            >
                                <StatusDot
                                    :status="facture.statut"
                                    :label="`Facture : ${facture.statut_label}`"
                                />
                            </button>
                            <StatusDot
                                v-if="commission_statut"
                                :status="commission_statut.value"
                                :label="`Commission : ${commission_statut.label}`"
                            />
                        </div>
                    </div>
                    <div
                        :class="
                            commande.livreur_nom
                                ? 'grid gap-4 sm:grid-cols-2 lg:grid-cols-5'
                                : 'grid gap-4 sm:grid-cols-2 lg:grid-cols-4'
                        "
                    >
                        <div>
                            <p class="text-xs text-muted-foreground">
                                Véhicule
                            </p>
                            <button
                                v-if="commande.vehicule_detail"
                                class="mt-0.5 flex items-center gap-1 font-medium text-primary hover:underline focus:outline-none"
                                @click="vehiculeDialogVisible = true"
                            >
                                {{ commande.vehicule_nom }}
                                <ExternalLink class="h-3 w-3 shrink-0" />
                            </button>
                            <p v-else class="mt-0.5 font-medium">—</p>
                            <p
                                v-if="commande.vehicule_detail?.immatriculation"
                                class="mt-0.5 text-xs text-muted-foreground"
                            >
                                {{ commande.vehicule_detail.immatriculation }}
                            </p>
                        </div>
                        <div v-if="commande.livreur_nom">
                            <p class="text-xs text-muted-foreground">Livreur</p>
                            <button
                                class="mt-0.5 flex items-center gap-1 font-medium text-primary hover:underline focus:outline-none"
                                @click="equipeDialogVisible = true"
                            >
                                {{ commande.livreur_nom }}
                                <ExternalLink class="h-3 w-3 shrink-0" />
                            </button>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                {{ formatPhone(commande.livreur_telephone) }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">Client</p>
                            <button
                                v-if="commande.client_detail"
                                class="mt-0.5 flex items-center gap-1 font-medium text-primary hover:underline focus:outline-none"
                                @click="clientDialogVisible = true"
                            >
                                {{ commande.client_nom }}
                                <ExternalLink class="h-3 w-3 shrink-0" />
                            </button>
                            <p v-else class="mt-0.5 font-medium">—</p>
                            <p
                                v-if="commande.client_detail?.telephone"
                                class="mt-0.5 text-xs text-muted-foreground"
                            >
                                {{
                                    formatPhone(
                                        commande.client_detail.telephone,
                                        commande.client_detail.code_phone_pays,
                                    )
                                }}
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
            </div>

            <!-- Onglet : Produits ───────────────────────────────────────────── -->
            <div v-else-if="activeTab === 'produits'">
                <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-5">
                    <h3
                        class="mb-5 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Lignes de commande
                    </h3>
                    <div
                        class="overflow-hidden overflow-x-auto rounded-lg border"
                    >
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
                                        v-if="showChargeeCol"
                                        class="px-4 py-2.5 text-left font-medium text-muted-foreground"
                                    >
                                        Motif d'écart
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
                                    <td
                                        class="px-4 py-3 text-center tabular-nums"
                                    >
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
                                        :class="
                                            ecartClass(ligne.ecart_chargement)
                                        "
                                    >
                                        {{ ecartLabel(ligne.ecart_chargement) }}
                                    </td>
                                    <td
                                        v-if="showChargeeCol"
                                        class="px-4 py-3 text-sm"
                                    >
                                        <span
                                            v-if="ligne.type_ecart_label"
                                            class="text-foreground"
                                            >{{ ligne.type_ecart_label }}</span
                                        >
                                        <span
                                            v-else
                                            class="text-muted-foreground"
                                            >—</span
                                        >
                                        <p
                                            v-if="ligne.commentaire_ecart"
                                            class="mt-0.5 text-xs text-muted-foreground"
                                        >
                                            {{ ligne.commentaire_ecart }}
                                        </p>
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right text-muted-foreground tabular-nums"
                                    >
                                        {{
                                            formatGNF(ligne.prix_vente_snapshot)
                                        }}
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
                                        :colspan="showChargeeCol ? 6 : 3"
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
            </div>

            <!-- Onglet : Facturation ────────────────────────────────────────── -->
            <div v-else-if="activeTab === 'facturation'">
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
                            <span
                                v-if="facture && facture.montant_restant > 0"
                                :title="
                                    !commande.can_encaisser
                                        ? 'L\'encaissement est possible uniquement après validation du chargement.'
                                        : ''
                                "
                            >
                                <Button
                                    size="sm"
                                    :disabled="!commande.can_encaisser"
                                    @click="openEncaisserDialog"
                                >
                                    <HandCoins class="mr-2 h-4 w-4" />
                                    Encaisser
                                    {{ formatGNF(facture.montant_restant) }}
                                </Button>
                            </span>
                            <StatusDot
                                :status="facture.statut"
                                :label="facture.statut_label"
                            />
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
                            <p class="text-xs text-muted-foreground">
                                Restant dû
                            </p>
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
                                        <td
                                            class="px-4 py-3 text-muted-foreground"
                                        >
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
                <div
                    v-else
                    class="rounded-xl border bg-card p-8 text-center text-sm text-muted-foreground shadow-sm"
                >
                    Aucune facture associée à cette commande.
                </div>
            </div>

            <!-- Onglet : Journal d'activité ─────────────────────────────────── -->
            <div v-else-if="activeTab === 'journal'">
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
                <div
                    v-else
                    class="rounded-xl border bg-card p-8 text-center text-sm text-muted-foreground shadow-sm"
                >
                    Aucune activité enregistrée.
                </div>
            </div>

            <!-- Onglet : Historique ─────────────────────────────────────────── -->
            <div v-else-if="activeTab === 'historique'">
                <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-5">
                    <h3
                        class="mb-5 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Historique des modifications
                    </h3>
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
                            <div
                                class="flex flex-wrap items-baseline gap-1 text-xs"
                            >
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
                                        Object.keys(entry.old_values).length >
                                            0) ||
                                    (entry.new_values &&
                                        Object.keys(entry.new_values).length >
                                            0)
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
                </div>
            </div>
        </div>

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

        <!-- Dialog Ticket -->
        <Dialog
            v-model:visible="ticketDialogVisible"
            modal
            header="Ticket commande"
            :style="{ width: '380px' }"
        >
            <div class="flex justify-center py-2">
                <div id="ticket-commande-print">
                    <TicketCommandeVente
                        :commande="commande"
                        :org-nom="orgNom"
                        :current-user="currentUserName"
                    />
                </div>
            </div>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <Button
                        variant="outline"
                        @click="ticketDialogVisible = false"
                        >Fermer</Button
                    >
                    <Button @click="printTicketCommande">
                        <Printer class="mr-2 h-4 w-4" />
                        Imprimer
                    </Button>
                </div>
            </template>
        </Dialog>

        <!-- Dialog Détail véhicule -->
        <Dialog
            v-model:visible="vehiculeDialogVisible"
            modal
            header="Détail véhicule"
            :style="{ width: '28rem' }"
        >
            <div class="space-y-3 px-1 py-2">
                <div class="flex justify-between">
                    <span class="text-sm text-muted-foreground">Nom</span>
                    <span class="text-sm font-medium">{{
                        commande.vehicule_detail?.nom ?? '—'
                    }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-sm text-muted-foreground"
                        >Immatriculation</span
                    >
                    <span class="text-sm font-medium">{{
                        commande.vehicule_detail?.immatriculation ?? '—'
                    }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-sm text-muted-foreground">Type</span>
                    <span class="text-sm font-medium">{{
                        commande.vehicule_detail?.type ?? '—'
                    }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-sm text-muted-foreground">Capacité</span>
                    <span class="text-sm font-medium">
                        {{
                            commande.vehicule_detail?.capacite_packs != null
                                ? commande.vehicule_detail.capacite_packs +
                                  ' packs'
                                : '—'
                        }}
                    </span>
                </div>
                <div class="border-t pt-3">
                    <p
                        class="mb-2 text-xs font-medium tracking-wider text-muted-foreground uppercase"
                    >
                        Propriétaire
                    </p>
                    <div class="flex justify-between">
                        <span class="text-sm text-muted-foreground">Nom</span>
                        <span class="text-sm font-medium">{{
                            commande.vehicule_detail?.proprietaire_nom ?? '—'
                        }}</span>
                    </div>
                    <div
                        v-if="commande.vehicule_detail?.proprietaire_telephone"
                        class="mt-2 flex justify-between"
                    >
                        <span class="text-sm text-muted-foreground"
                            >Téléphone</span
                        >
                        <span class="text-sm font-medium">
                            {{
                                formatPhone(
                                    commande.vehicule_detail
                                        .proprietaire_telephone,
                                    commande.vehicule_detail
                                        .proprietaire_code_phone_pays,
                                )
                            }}
                        </span>
                    </div>
                </div>
            </div>
            <template #footer>
                <Button
                    variant="outline"
                    size="sm"
                    @click="vehiculeDialogVisible = false"
                    >Fermer</Button
                >
            </template>
        </Dialog>

        <!-- Dialog Équipe de livraison -->
        <Dialog
            v-model:visible="equipeDialogVisible"
            modal
            header="Équipe de livraison"
            :style="{ width: '30rem' }"
        >
            <div class="space-y-4 px-1 py-2">
                <div class="flex justify-between">
                    <span class="text-sm text-muted-foreground">Équipe</span>
                    <span class="text-sm font-medium">{{
                        commande.equipe_detail?.nom ?? '—'
                    }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-sm text-muted-foreground">Véhicule</span>
                    <span class="text-sm font-medium">{{
                        commande.vehicule_nom ?? '—'
                    }}</span>
                </div>
                <div
                    v-if="
                        commande.equipe_detail?.taux_commission_proprietaire !=
                        null
                    "
                    class="flex justify-between"
                >
                    <span class="text-sm text-muted-foreground"
                        >Taux propriétaire</span
                    >
                    <span class="text-sm font-medium"
                        >{{
                            commande.equipe_detail.taux_commission_proprietaire
                        }}
                        %</span
                    >
                </div>
                <div
                    v-if="commande.equipe_detail?.chauffeur"
                    class="border-t pt-3"
                >
                    <p
                        class="mb-2 text-xs font-medium tracking-wider text-muted-foreground uppercase"
                    >
                        Chauffeur principal
                    </p>
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium">{{
                            commande.equipe_detail.chauffeur.nom
                        }}</span>
                        <span class="text-sm text-muted-foreground">
                            {{
                                formatPhone(
                                    commande.equipe_detail.chauffeur.telephone,
                                )
                            }}
                        </span>
                    </div>
                </div>
                <div
                    v-if="commande.equipe_detail?.convoyeurs?.length"
                    class="border-t pt-3"
                >
                    <p
                        class="mb-2 text-xs font-medium tracking-wider text-muted-foreground uppercase"
                    >
                        Convoyeurs
                    </p>
                    <div
                        v-for="conv in commande.equipe_detail.convoyeurs"
                        :key="conv.nom"
                        class="flex items-center justify-between py-1"
                    >
                        <span class="text-sm font-medium">{{ conv.nom }}</span>
                        <span class="text-sm text-muted-foreground">{{
                            formatPhone(conv.telephone)
                        }}</span>
                    </div>
                </div>
            </div>
            <template #footer>
                <Button
                    variant="outline"
                    size="sm"
                    @click="equipeDialogVisible = false"
                    >Fermer</Button
                >
            </template>
        </Dialog>

        <!-- Dialog Client -->
        <Dialog
            v-model:visible="clientDialogVisible"
            modal
            header="Détail client"
            :style="{ width: '28rem' }"
        >
            <div class="space-y-3 px-1 py-2">
                <div class="flex justify-between">
                    <span class="text-sm text-muted-foreground">Nom</span>
                    <span class="text-sm font-medium">{{
                        commande.client_detail?.nom ?? '—'
                    }}</span>
                </div>
                <div
                    v-if="commande.client_detail?.telephone"
                    class="flex justify-between"
                >
                    <span class="text-sm text-muted-foreground">Téléphone</span>
                    <span class="text-sm font-medium">
                        {{
                            formatPhone(
                                commande.client_detail.telephone,
                                commande.client_detail.code_phone_pays,
                            )
                        }}
                    </span>
                </div>
                <div class="border-t pt-3">
                    <p
                        class="mb-2 text-xs font-medium tracking-wider text-muted-foreground uppercase"
                    >
                        Localisation
                    </p>
                    <div class="flex justify-between">
                        <span class="text-sm text-muted-foreground">Ville</span>
                        <span class="text-sm font-medium">{{
                            commande.client_detail?.ville ?? '—'
                        }}</span>
                    </div>
                    <div
                        v-if="commande.client_detail?.adresse"
                        class="mt-2 flex justify-between"
                    >
                        <span class="text-sm text-muted-foreground"
                            >Adresse</span
                        >
                        <span
                            class="max-w-[60%] text-right text-sm font-medium"
                            >{{ commande.client_detail.adresse }}</span
                        >
                    </div>
                </div>
                <div class="flex justify-between border-t pt-3">
                    <span class="text-sm text-muted-foreground">Cashback</span>
                    <span
                        class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                        :class="
                            commande.client_detail?.cashback_eligible
                                ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
                                : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400'
                        "
                    >
                        {{
                            commande.client_detail?.cashback_eligible
                                ? 'Éligible'
                                : 'Non éligible'
                        }}
                    </span>
                </div>
            </div>
            <template #footer>
                <Button
                    variant="outline"
                    size="sm"
                    @click="clientDialogVisible = false"
                    >Fermer</Button
                >
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
