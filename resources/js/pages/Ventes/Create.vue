<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/composables/usePermissions';
import { useVehiculeCommandeTarification } from '@/composables/useVehiculeCommandeTarification';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPhoneDisplay } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ExternalLink,
    Info,
    Lock,
    Phone,
    Plus,
    Save,
    Trash2,
} from 'lucide-vue-next';
import AutoComplete from 'primevue/autocomplete';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import Tooltip from 'primevue/tooltip';
import { computed, onMounted, ref } from 'vue';

const vTooltip = Tooltip;

// ── Types ─────────────────────────────────────────────────────────────────────
interface FactureDetail {
    commande_id: string;
    reference: string;
    date: string | null;
    montant: number;
    encaisse: number;
    restant: number;
    statut: string;
    statut_label: string;
}

interface SolvabiliteResult {
    // Cible réellement contrôlée par le backend (SolvabiliteService) — 'vehicule' dès qu'un
    // véhicule est sélectionné, 'client' seulement en repli (aucun véhicule), cf.
    // commandeBloquee ci-dessous qui doit refléter exactement cette même priorité.
    cible: 'vehicule' | 'client' | 'aucun';
    has_debt: boolean;
    status: 'aucun' | 'partiel' | 'impaye';
    unpaid_invoices_count: number;
    total_remaining: number;
    total_encaisse: number;
    last_invoice_reference: string | null;
    last_invoice_date: string | null;
    controle_actif: boolean;
    seuil_impayes: number;
    montant_disponible: number;
    blocked: boolean;
    depassement: number;
    // Verrou « première régularisation » (cf. SolvabiliteService) : distinct du contrôle de
    // seuil ci-dessus — se déclenche dès qu'une facture n'a reçu AUCUN encaissement, quel que
    // soit le seuil ou le paramètre de contrôle des impayés. Ne concerne que la cible véhicule.
    blocage_premiere_facture: boolean;
    facture_bloquante_reference: string | null;
    facture_bloquante_commande_id: string | null;
    factures: FactureDetail[];
}

interface ProduitOption {
    id: number;
    nom: string;
    categorie_id: number | null;
    prix_vente: number;
    prix_usine: number;
}

interface CapaciteCategorie {
    categorie_id: number;
    categorie_nom: string;
    capacite_max: number;
}

interface VehiculeOption {
    id: number;
    nom_vehicule: string;
    immatriculation: string;
    capacites: CapaciteCategorie[];
    livreur_nom: string | null;
    livreur_telephone: string | null;
}

interface ClientVehiculeOption {
    id: number;
    libelle_affiche: string;
}

interface ClientOption {
    id: number;
    nom_complet: string;
    telephone: string | null;
    type: 'standard' | 'partenaire';
    vehicules: ClientVehiculeOption[];
}

interface UserSite {
    id: number;
    nom: string;
    label: string;
}

interface LigneForm {
    produit_id: number | null;
    qte: number;
    prix_vente: number;
    total: number;
}

// ── Props ─────────────────────────────────────────────────────────────────────
const props = defineProps<{
    produits: ProduitOption[];
    vehicules: VehiculeOption[];
    clients: ClientOption[];
    user_site: UserSite;
    can_modifier_qte: boolean;
    autoriser_saisie_dessous_qte_max: boolean;
}>();

const { can } = usePermissions();
const canUpdateUnitPrice = computed(() => can('ventes.prix.update'));

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Ventes', href: '/backoffice/ventes' },
    { title: 'Nouvelle commande', href: '/backoffice/ventes/create' },
];

// ── Form ──────────────────────────────────────────────────────────────────────
const form = useForm({
    vehicule_id: null as number | null,
    client_id: null as number | null,
    // Véhicule partenaire — toujours facultatif, jamais un substitut au véhicule de
    // flotte (cf. ClientVehicle). Ne s'affiche que pour un client type=partenaire.
    client_vehicule_id: null as number | null,
    lignes: [
        { produit_id: null, qte: 1, prix_vente: 0, total: 0 },
    ] as LigneForm[],
});

// ── AutoComplete : Véhicule ───────────────────────────────────────────────────
const vehiculeSelected = ref<VehiculeOption | null>(null);
const vehiculeSuggests = ref<VehiculeOption[]>([]);
const vehiculeSolvabilite = ref<SolvabiliteResult | null>(null);
const vehiculeSolvabiliteLoading = ref(false);

function searchVehicule(event: { query: string }) {
    const q = event.query.toLowerCase().trim();
    vehiculeSuggests.value = q
        ? props.vehicules.filter(
              (v) =>
                  v.nom_vehicule.toLowerCase().includes(q) ||
                  v.immatriculation.toLowerCase().includes(q) ||
                  (v.livreur_nom && v.livreur_nom.toLowerCase().includes(q)),
          )
        : [...props.vehicules];
}

async function onVehiculeSelect(v: VehiculeOption | null) {
    form.vehicule_id = v?.id ?? null;
    applyVehiculeCapacityOnSingleLine(v);
    recomputeAllTotals();
    if (v) {
        vehiculeSolvabiliteLoading.value = true;
        vehiculeSolvabilite.value = null;
        try {
            const res = await fetch(
                `/backoffice/ventes/check-solvabilite?vehicule_id=${v.id}`,
            );
            vehiculeSolvabilite.value = await res.json();
        } finally {
            vehiculeSolvabiliteLoading.value = false;
        }
    }
}

function onVehiculeClear() {
    form.vehicule_id = null;
    vehiculeSelected.value = null;
    vehiculeSolvabilite.value = null;
    recomputeAllTotals();
}

// Pré-remplit la quantité de l'unique ligne à la capacité du véhicule POUR LA CATÉGORIE DU
// PRODUIT déjà choisi sur cette ligne — seulement s'il n'y a qu'une seule ligne avec un produit
// sélectionné (sinon ambigu : quelle ligne recevrait le plafond ?). Cible la capacité de la
// catégorie du produit, pas "la seule capacité du véhicule au total" (ancienne condition, trop
// fragile dès qu'un véhicule a plusieurs catégories plafonnées, ex: Sachet ET Bouteille — le
// produit de cette ligne n'appartenant qu'à l'une des deux, l'autre ne doit pas empêcher le
// pré-remplissage).
function applyVehiculeCapacityOnSingleLine(vehicule: VehiculeOption | null) {
    if (!vehicule || form.lignes.length !== 1) {
        return;
    }

    const ligne = form.lignes[0];
    if (ligne.produit_id === null) {
        return;
    }

    const categorieId = props.produits.find(
        (p) => p.id === ligne.produit_id,
    )?.categorie_id;
    if (categorieId === null || categorieId === undefined) {
        return;
    }

    const capacite = vehicule.capacites.find(
        (c) => c.categorie_id === categorieId,
    );
    if (!capacite) {
        return;
    }

    ligne.qte = capacite.capacite_max;
    ligne.total = computeLigneTotal(ligne);
}

// ── Mode de tarification (montant à encaisser par l'usine) & éligibilité aux
// commissions — deux notions indépendantes, jamais recalculées l'une à
// partir de l'autre (cf. useVehiculeCommandeTarification). Source de vérité
// côté serveur : VehiculeCommandeContextResolver — ce composable n'est qu'un
// miroir d'affichage.
const { modeTarification, commissionEligible } =
    useVehiculeCommandeTarification(
        () => props.vehicules,
        () => form.vehicule_id,
        () => props.clients,
        () => form.client_id,
    );

function produitPrixUsine(produitId: number | null): number {
    if (produitId === null) return 0;
    return props.produits.find((p) => p.id === produitId)?.prix_usine ?? 0;
}

function computeLigneTotal(ligne: LigneForm): number {
    return modeTarification.value === 'prix_usine'
        ? produitPrixUsine(ligne.produit_id) * ligne.qte
        : ligne.prix_vente * ligne.qte;
}

function recomputeAllTotals() {
    form.lignes.forEach((l) => {
        l.total = computeLigneTotal(l);
    });
}

function vehiculeLabel(v: VehiculeOption): string {
    return `${v.nom_vehicule} — ${v.immatriculation}`;
}

// ── Prix affiché — la règle (prix vente vs prix usine) est expliquée une
// seule fois via le bandeau "Prix appliqué", donc les libellés de colonne
// restent génériques. La valeur AFFICHÉE reste celle réellement utilisée
// dans le calcul du total, sinon valeur affichée et total se contredisent.
const prixUnitLabel = 'Prix unit.';
const totalColumnLabel = 'Total';
const totalCommandeLabel = 'Total commande';
const unitPriceEditable = computed(
    () => canUpdateUnitPrice.value && modeTarification.value !== 'prix_usine',
);
function ligneUnitPrice(ligne: LigneForm): number {
    return modeTarification.value === 'prix_usine'
        ? produitPrixUsine(ligne.produit_id)
        : ligne.prix_vente;
}

// ── AutoComplete : Client ─────────────────────────────────────────────────────
const clientSelected = ref<ClientOption | null>(null);
const clientSuggests = ref<ClientOption[]>([]);
const clientSolvabilite = ref<SolvabiliteResult | null>(null);
const clientSolvabiliteLoading = ref(false);

function searchClient(event: { query: string }) {
    const q = event.query.toLowerCase().trim();
    clientSuggests.value = q
        ? props.clients.filter(
              (c) =>
                  c.nom_complet.toLowerCase().includes(q) ||
                  (c.telephone && c.telephone.includes(q)),
          )
        : [...props.clients];
}

// Véhicule partenaire mémorisé — visible seulement pour un client type=partenaire
// qui en a au moins un ; toujours facultatif (cf. form.client_vehicule_id).
const selectedClientVehicules = computed(
    () => clientSelected.value?.vehicules ?? [],
);

async function onClientSelect(c: ClientOption | null) {
    form.client_id = c?.id ?? null;
    form.client_vehicule_id = null;
    // Le type de client (partenaire) peut à lui seul faire basculer modeTarification
    // vers "prix_usine" (cf. useVehiculeCommandeTarification) — sans ce recalcul, le
    // total des lignes déjà saisies reste figé sur l'ancien mode (prix_vente) alors
    // que le prix unitaire affiché, lui, se met à jour immédiatement.
    recomputeAllTotals();
    if (c) {
        clientSolvabiliteLoading.value = true;
        clientSolvabilite.value = null;
        try {
            const res = await fetch(
                `/backoffice/ventes/check-solvabilite?client_id=${c.id}`,
            );
            clientSolvabilite.value = await res.json();
        } finally {
            clientSolvabiliteLoading.value = false;
        }
    }
}

function onClientClear() {
    form.client_id = null;
    form.client_vehicule_id = null;
    clientSelected.value = null;
    clientSolvabilite.value = null;
    recomputeAllTotals();
}

function clientLabel(c: ClientOption): string {
    return c.nom_complet;
}

// ── Solvabilité — dialog ──────────────────────────────────────────────────────
interface DialogContext {
    type: 'vehicule' | 'client';
    titre: string;
    sousTitre?: string;
    chauffeur?: string;
}

const showFacturesDialog = ref(false);
const dialogSolvabilite = ref<SolvabiliteResult | null>(null);
const dialogContext = ref<DialogContext | null>(null);

function ouvrirDialogFactures(solv: SolvabiliteResult, ctx: DialogContext) {
    dialogSolvabilite.value = solv;
    dialogContext.value = ctx;
    showFacturesDialog.value = true;
}

// ── Solvabilité helpers ───────────────────────────────────────────────────────
function formatDate(dateStr: string | null): string {
    if (!dateStr) return '—';
    const [y, m, d] = dateStr.split('-');
    return `${d}/${m}/${y}`;
}

// ── Dropdown : Produit ────────────────────────────────────────────────────────
const produitOptions = computed(() =>
    props.produits.map((p) => ({
        value: p.id,
        label: p.nom,
    })),
);

// ── Formatage ─────────────────────────────────────────────────────────────────
function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}

// ── Gestion des lignes ────────────────────────────────────────────────────────
function onProduitChange(index: number, produitId: number | null) {
    if (produitId === null) {
        form.lignes[index].produit_id = null;
        form.lignes[index].prix_vente = 0;
        form.lignes[index].total = 0;
        return;
    }

    // Produit déjà présent → supprimer la ligne courante et incrémenter de +1
    const existingIndex = form.lignes.findIndex(
        (l, i) => i !== index && l.produit_id === produitId,
    );
    if (existingIndex !== -1) {
        form.lignes[existingIndex].qte += 1;
        form.lignes[existingIndex].total = computeLigneTotal(
            form.lignes[existingIndex],
        );
        form.lignes.splice(index, 1);
        return;
    }

    // Nouveau produit → capacité par défaut uniquement sur la 1re ligne
    const ligne = form.lignes[index];
    ligne.produit_id = produitId;
    const produit = props.produits.find((p) => p.id === produitId);
    ligne.prix_vente = produit ? produit.prix_vente : 0;
    const qteParDefaut =
        index === 0 ? (maxPourLigne(produitId) ?? ligne.qte) : ligne.qte;
    ligne.qte = Math.max(1, qteParDefaut);
    ligne.total = computeLigneTotal(ligne);
}

function onQteChange(index: number, qte: number | null) {
    const ligne = form.lignes[index];
    ligne.qte = Math.max(1, qte ?? 1);
    ligne.total = computeLigneTotal(ligne);
}

function onPrixChange(index: number, prix: number | null) {
    if (!canUpdateUnitPrice.value) {
        return;
    }

    const ligne = form.lignes[index];
    ligne.prix_vente = prix ?? 0;
    ligne.total = computeLigneTotal(ligne);
}

function addLigne() {
    form.lignes.push({ produit_id: null, qte: 1, prix_vente: 0, total: 0 });
}

function removeLigne(index: number) {
    if (form.lignes.length > 1) {
        form.lignes.splice(index, 1);
    }
}

// ── Total général ─────────────────────────────────────────────────────────────
const totalGeneral = computed(() =>
    form.lignes.reduce((sum, l) => sum + l.total, 0),
);

const quantiteTotale = computed(() =>
    form.lignes.reduce((sum, l) => sum + (l.qte ?? 0), 0),
);

const vehiculeSelectionne = computed(() => {
    if (form.vehicule_id === null) {
        return null;
    }

    return props.vehicules.find((v) => v.id === form.vehicule_id) ?? null;
});

// Plafonds par groupe de capacité du véhicule sélectionné (Sachets, Bouteilles, ...) — vide si
// aucune capacité n'est configurée pour ce véhicule (non plafonné), exactement comme
// VehiculeCapaciteService côté serveur (plus aucun héritage depuis le type).
const capacitesSelectionnees = computed(
    () => vehiculeSelectionne.value?.capacites ?? [],
);

// Quantité commandée par catégorie effectivement vendue (les catégories absentes de la
// commande ne sont pas remontées ici — même logique que le regroupement backend).
const usageParCategorie = computed(() => {
    const capacites = capacitesSelectionnees.value;
    if (capacites.length === 0) return [];

    const qteParCategorie = new Map<number, number>();
    for (const ligne of form.lignes) {
        if (ligne.produit_id === null) continue;
        const categorieId =
            props.produits.find((p) => p.id === ligne.produit_id)
                ?.categorie_id ?? null;
        if (categorieId === null) continue;
        qteParCategorie.set(
            categorieId,
            (qteParCategorie.get(categorieId) ?? 0) + (ligne.qte ?? 0),
        );
    }

    return [...qteParCategorie.entries()]
        .map(([categorieId, qte]) => {
            const cap = capacites.find((c) => c.categorie_id === categorieId);
            return cap ? { ...cap, qte } : null;
        })
        .filter((c): c is CapaciteCategorie & { qte: number } => c !== null);
});

// Aucun dépassement n'est jamais toléré, pour aucun rôle (décision produit du 15/08/2026) —
// can_modifier_qte n'autorise que la saisie manuelle du champ quantité, plus aucun bypass de
// capacité. Seul autoriser_saisie_dessous_qte_max reste un levier, et uniquement à la baisse.
// Véhicule sans aucune capacité configurée = non plafonné, toujours conforme.
const capaciteVehiculeConforme = computed(() => {
    if (form.vehicule_id === null) return true;
    if (capacitesSelectionnees.value.length === 0) return true;

    return usageParCategorie.value.every((c) => {
        if (c.qte > c.capacite_max) return false;
        if (c.qte < c.capacite_max)
            return props.autoriser_saisie_dessous_qte_max;
        return true;
    });
});

// Plafond "doux" affiché sur le champ quantité d'une ligne : celui de la catégorie du
// produit sélectionné sur cette ligne (undefined si aucune catégorie ou produit non choisi).
// Ne tient pas compte des autres lignes de la même catégorie — le vrai contrôle cumulé reste
// capaciteVehiculeConforme (et, en dernier ressort, le backend).
function maxPourLigne(produitId: number | null): number | undefined {
    if (produitId === null) return undefined;
    const categorieId = props.produits.find(
        (p) => p.id === produitId,
    )?.categorie_id;
    if (categorieId === null || categorieId === undefined) return undefined;
    return capacitesSelectionnees.value.find(
        (c) => c.categorie_id === categorieId,
    )?.capacite_max;
}

function capaciteLigneClass(qte: number, max: number): string {
    if (qte > max) return 'text-destructive';
    if (qte < max) return 'text-amber-600 dark:text-amber-400';
    return 'text-emerald-600 dark:text-emerald-400';
}

// ── Reset au montage (évite la persistance SPA entre navigations) ─────────────
onMounted(() => {
    form.reset();
    vehiculeSelected.value = null;
    clientSelected.value = null;

    // Pré-sélectionner le premier produit sur la première ligne
    if (props.produits.length > 0) {
        const first = props.produits[0];
        form.lignes[0].produit_id = first.id;
        form.lignes[0].prix_vente = first.prix_vente;
        form.lignes[0].total = computeLigneTotal(form.lignes[0]);
    }
});

// ── Type de commande ──────────────────────────────────────────────────────────
const isCommandeLogistique = computed(() => form.vehicule_id !== null);

// ── Blocage impayés ───────────────────────────────────────────────────────────
// Même règle que SolvabiliteService côté backend (véhicule prioritaire, client en repli
// uniquement si aucun véhicule n'est sélectionné) — jamais un OU des deux indépendamment, sinon
// le frontend bloquerait sur une dette client que le backend ignore complètement dès qu'un
// véhicule est choisi (incohérence corrigée le 18/08/2026).
const commandeBloquee = computed(() =>
    form.vehicule_id
        ? (vehiculeSolvabilite.value?.blocked ?? false)
        : (clientSolvabilite.value?.blocked ?? false),
);

// ── Validation locale ────────────────────────────────────────────────────────
const canSubmit = computed(
    () =>
        (form.vehicule_id !== null || form.client_id !== null) &&
        totalGeneral.value > 0 &&
        capaciteVehiculeConforme.value &&
        !commandeBloquee.value &&
        !form.processing,
);

// ── Soumission ────────────────────────────────────────────────────────────────
const showConfirmDialog = ref(false);

const lignesVisibles = computed(() =>
    form.lignes.filter((l) => l.produit_id !== null),
);

function nomProduit(produitId: number | null): string {
    if (!produitId) return '—';
    return props.produits.find((p) => p.id === produitId)?.nom ?? '—';
}

function submit() {
    showConfirmDialog.value = true;
}

function confirmerEtCreer() {
    form.post('/backoffice/ventes');
}
</script>

<template>
    <Head title="Nouvelle commande" />

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
                        Nouvelle vente
                    </h1>
                </div>
            </div>
        </div>

        <div class="mx-auto max-w-5xl p-4 sm:p-6">
            <div class="mb-6 hidden sm:block">
                <h1 class="text-2xl font-semibold tracking-tight">
                    Nouvelle commande de vente
                </h1>
                <!-- <p class="mt-1 text-sm text-muted-foreground">
                    Créez une commande et sa facture sera générée
                    automatiquement.
                </p> -->
            </div>

            <form id="vente-form" class="space-y-6" @submit.prevent="submit">
                <!-- En-tête commande -->
                <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
                    <h2
                        class="mb-5 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Informations générales
                    </h2>
                    <!-- Site rattaché (lecture seule) -->
                    <div
                        class="mb-4 flex items-center gap-2 rounded-lg border bg-muted/30 px-3 py-2.5"
                    >
                        <span class="text-xs text-muted-foreground"
                            >Site :</span
                        >
                        <span class="text-sm font-medium">{{
                            user_site.label
                        }}</span>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <!-- Véhicule -->
                        <div>
                            <Label class="mb-1.5 block text-sm">
                                Véhicule
                            </Label>
                            <AutoComplete
                                v-model="vehiculeSelected"
                                :suggestions="vehiculeSuggests"
                                :option-label="vehiculeLabel"
                                @complete="searchVehicule"
                                @item-select="
                                    onVehiculeSelect(vehiculeSelected)
                                "
                                @clear="onVehiculeClear"
                                placeholder="Nom, immatriculation, livreur…"
                                class="w-full"
                                input-class="w-full"
                                :class="{
                                    'p-invalid': form.errors.vehicule_id,
                                }"
                                dropdown
                                force-selection
                            >
                                <template #option="{ option }">
                                    <div class="py-0.5">
                                        <div class="leading-tight font-medium">
                                            {{ option.nom_vehicule }}
                                        </div>
                                        <div
                                            class="mt-0.5 flex items-center gap-2 text-xs text-muted-foreground"
                                        >
                                            <span class="font-mono">{{
                                                option.immatriculation
                                            }}</span>
                                            <span
                                                v-if="
                                                    option.capacites.length > 0
                                                "
                                                class="before:mr-2 before:content-['·']"
                                            >
                                                <template
                                                    v-for="(
                                                        c, i
                                                    ) in option.capacites"
                                                    :key="c.categorie_id"
                                                >
                                                    {{ c.categorie_nom }}
                                                    {{ c.capacite_max
                                                    }}<template
                                                        v-if="
                                                            i <
                                                            option.capacites
                                                                .length -
                                                                1
                                                        "
                                                        >,
                                                    </template>
                                                </template>
                                            </span>
                                            <span
                                                v-if="option.livreur_nom"
                                                class="before:mr-2 before:content-['·']"
                                                >{{ option.livreur_nom }}</span
                                            >
                                        </div>
                                    </div>
                                </template>
                                <template #empty>
                                    <span class="text-sm text-muted-foreground"
                                        >Aucun véhicule trouvé.</span
                                    >
                                </template>
                            </AutoComplete>
                            <p
                                v-if="form.errors.vehicule_id"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.vehicule_id }}
                            </p>

                            <!-- Solvabilité véhicule -->
                            <div
                                v-if="vehiculeSolvabiliteLoading"
                                class="mt-3 flex items-center gap-2 text-xs text-muted-foreground"
                            >
                                <svg
                                    class="h-3.5 w-3.5 animate-spin"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                >
                                    <circle
                                        class="opacity-25"
                                        cx="12"
                                        cy="12"
                                        r="10"
                                        stroke="currentColor"
                                        stroke-width="4"
                                    />
                                    <path
                                        class="opacity-75"
                                        fill="currentColor"
                                        d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"
                                    />
                                </svg>
                                Vérification en cours…
                            </div>

                            <!-- 🚫 Commande bloquée — verrou « première régularisation » : une facture
                                 précédente n'a reçu AUCUN encaissement, indépendamment du seuil
                                 d'impayés et de has_debt/blocked ci-dessous (cf. SolvabiliteService —
                                 une facture encore CREEE n'entre pas dans has_debt mais déclenche
                                 quand même ce verrou). Vérifié en priorité, avant tout le reste de
                                 la chaîne, pour ne jamais laisser passer « ✓ Véhicule à jour ». -->
                            <div
                                v-else-if="
                                    vehiculeSolvabilite?.blocage_premiere_facture
                                "
                                class="mt-3 rounded-xl border border-red-300 bg-red-100 p-3 dark:border-red-700 dark:bg-red-950/50"
                            >
                                <div
                                    class="mb-2 flex items-center justify-between gap-3"
                                >
                                    <p
                                        class="text-xs font-bold tracking-wide text-red-800 uppercase dark:text-red-300"
                                    >
                                        Commande bloquée — première facture non
                                        réglée
                                    </p>
                                    <button
                                        type="button"
                                        class="shrink-0 rounded-lg border border-red-300 bg-white px-3 py-1.5 text-xs font-medium text-red-700 transition-colors hover:bg-red-100 dark:border-red-700 dark:bg-red-950/60 dark:text-red-300 dark:hover:bg-red-900/60"
                                        @click="
                                            ouvrirDialogFactures(
                                                vehiculeSolvabilite,
                                                {
                                                    type: 'vehicule',
                                                    titre: vehiculeSelected
                                                        ? vehiculeLabel(
                                                              vehiculeSelected,
                                                          )
                                                        : 'Véhicule',
                                                    chauffeur:
                                                        vehiculeSelected?.livreur_nom
                                                            ? vehiculeSelected.livreur_nom +
                                                              (vehiculeSelected.livreur_telephone
                                                                  ? ' — ' +
                                                                    formatPhoneDisplay(
                                                                        vehiculeSelected.livreur_telephone,
                                                                    )
                                                                  : '')
                                                            : undefined,
                                                },
                                            )
                                        "
                                    >
                                        Voir les factures
                                    </button>
                                </div>
                                <p
                                    class="text-sm text-red-900 dark:text-red-200"
                                >
                                    Ce véhicule possède déjà une
                                    commande<template
                                        v-if="
                                            vehiculeSolvabilite.facture_bloquante_reference
                                        "
                                    >
                                        ({{
                                            vehiculeSolvabilite.facture_bloquante_reference
                                        }})</template
                                    >
                                    dont la facture n'a encore reçu aucun
                                    paiement. Enregistrez d'abord un
                                    encaissement avant de créer une nouvelle
                                    commande.
                                </p>
                            </div>

                            <!-- ✅ Aucun impayé -->
                            <p
                                v-else-if="
                                    vehiculeSolvabilite &&
                                    !vehiculeSolvabilite.has_debt
                                "
                                class="mt-2 flex items-center gap-1 text-xs font-medium text-emerald-600 dark:text-emerald-400"
                            >
                                <span>✓</span>
                                Véhicule à jour
                            </p>

                            <!-- ⚠ Dettes (dans les limites) -->
                            <div
                                v-else-if="
                                    vehiculeSolvabilite &&
                                    vehiculeSolvabilite.has_debt &&
                                    !vehiculeSolvabilite.blocked
                                "
                                class="mt-3 rounded-xl border p-3"
                                :class="
                                    vehiculeSolvabilite.status === 'impaye'
                                        ? 'border-red-200 bg-red-50 dark:border-red-800 dark:bg-red-950/30'
                                        : 'border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/30'
                                "
                            >
                                <div
                                    class="flex items-start justify-between gap-3"
                                >
                                    <div class="flex items-start gap-2.5">
                                        <span
                                            class="mt-0.5 text-base"
                                            :class="
                                                vehiculeSolvabilite.status ===
                                                'impaye'
                                                    ? 'text-red-500'
                                                    : 'text-amber-500'
                                            "
                                            >⚠</span
                                        >
                                        <div>
                                            <p
                                                class="text-sm font-semibold"
                                                :class="
                                                    vehiculeSolvabilite.status ===
                                                    'impaye'
                                                        ? 'text-red-800 dark:text-red-300'
                                                        : 'text-amber-800 dark:text-amber-300'
                                                "
                                            >
                                                {{
                                                    vehiculeSolvabilite.status ===
                                                    'impaye'
                                                        ? 'Factures impayées détectées'
                                                        : 'Paiement partiel'
                                                }}
                                            </p>
                                            <p
                                                class="mt-1.5 text-xs font-medium opacity-70"
                                                :class="
                                                    vehiculeSolvabilite.status ===
                                                    'impaye'
                                                        ? 'text-red-800 dark:text-red-300'
                                                        : 'text-amber-800 dark:text-amber-300'
                                                "
                                            >
                                                Montant total impayé
                                            </p>
                                            <p
                                                class="text-xl font-bold"
                                                :class="
                                                    vehiculeSolvabilite.status ===
                                                    'impaye'
                                                        ? 'text-red-800 dark:text-red-300'
                                                        : 'text-amber-800 dark:text-amber-300'
                                                "
                                            >
                                                {{
                                                    formatGNF(
                                                        vehiculeSolvabilite.total_remaining,
                                                    )
                                                }}
                                            </p>
                                            <p
                                                class="mt-1 text-xs opacity-70"
                                                :class="
                                                    vehiculeSolvabilite.status ===
                                                    'impaye'
                                                        ? 'text-red-800 dark:text-red-300'
                                                        : 'text-amber-800 dark:text-amber-300'
                                                "
                                            >
                                                Nombre de factures :
                                                {{
                                                    vehiculeSolvabilite.unpaid_invoices_count
                                                }}
                                            </p>
                                            <p
                                                v-if="
                                                    vehiculeSolvabilite.last_invoice_reference
                                                "
                                                class="mt-1 text-xs opacity-60"
                                                :class="
                                                    vehiculeSolvabilite.status ===
                                                    'impaye'
                                                        ? 'text-red-800 dark:text-red-300'
                                                        : 'text-amber-800 dark:text-amber-300'
                                                "
                                            >
                                                Dernière :
                                                {{
                                                    vehiculeSolvabilite.last_invoice_reference
                                                }}
                                                ·
                                                {{
                                                    formatDate(
                                                        vehiculeSolvabilite.last_invoice_date,
                                                    )
                                                }}
                                            </p>
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        class="shrink-0 rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors"
                                        :class="
                                            vehiculeSolvabilite.status ===
                                            'impaye'
                                                ? 'border-red-300 bg-white text-red-700 hover:bg-red-100 dark:border-red-700 dark:bg-red-950/60 dark:text-red-300 dark:hover:bg-red-900/60'
                                                : 'border-amber-300 bg-white text-amber-700 hover:bg-amber-100 dark:border-amber-700 dark:bg-amber-950/60 dark:text-amber-300 dark:hover:bg-amber-900/60'
                                        "
                                        @click="
                                            ouvrirDialogFactures(
                                                vehiculeSolvabilite,
                                                {
                                                    type: 'vehicule',
                                                    titre: vehiculeSelected
                                                        ? vehiculeLabel(
                                                              vehiculeSelected,
                                                          )
                                                        : 'Véhicule',
                                                    chauffeur:
                                                        vehiculeSelected?.livreur_nom
                                                            ? vehiculeSelected.livreur_nom +
                                                              (vehiculeSelected.livreur_telephone
                                                                  ? ' — ' +
                                                                    formatPhoneDisplay(
                                                                        vehiculeSelected.livreur_telephone,
                                                                    )
                                                                  : '')
                                                            : undefined,
                                                },
                                            )
                                        "
                                    >
                                        Voir les factures
                                    </button>
                                </div>
                            </div>

                            <!-- 🚫 Commande bloquée — seuil d'impayés dépassé -->
                            <div
                                v-else-if="vehiculeSolvabilite?.blocked"
                                class="mt-3 rounded-xl border border-red-300 bg-red-100 p-3 dark:border-red-700 dark:bg-red-950/50"
                            >
                                <div
                                    class="mb-3 flex items-center justify-between gap-3"
                                >
                                    <p
                                        class="text-xs font-bold tracking-wide text-red-800 uppercase dark:text-red-300"
                                    >
                                        Commande bloquée — facture impayée
                                    </p>
                                    <button
                                        type="button"
                                        class="shrink-0 rounded-lg border border-red-300 bg-white px-3 py-1.5 text-xs font-medium text-red-700 transition-colors hover:bg-red-100 dark:border-red-700 dark:bg-red-950/60 dark:text-red-300 dark:hover:bg-red-900/60"
                                        @click="
                                            ouvrirDialogFactures(
                                                vehiculeSolvabilite,
                                                {
                                                    type: 'vehicule',
                                                    titre: vehiculeSelected
                                                        ? vehiculeLabel(
                                                              vehiculeSelected,
                                                          )
                                                        : 'Véhicule',
                                                    chauffeur:
                                                        vehiculeSelected?.livreur_nom
                                                            ? vehiculeSelected.livreur_nom +
                                                              (vehiculeSelected.livreur_telephone
                                                                  ? ' — ' +
                                                                    formatPhoneDisplay(
                                                                        vehiculeSelected.livreur_telephone,
                                                                    )
                                                                  : '')
                                                            : undefined,
                                                },
                                            )
                                        "
                                    >
                                        Voir les factures
                                    </button>
                                </div>
                                <div class="grid grid-cols-3 gap-2 text-center">
                                    <div>
                                        <p
                                            class="text-xs text-red-700 dark:text-red-400"
                                        >
                                            Dette actuelle
                                        </p>
                                        <p
                                            class="font-bold text-red-900 tabular-nums dark:text-red-200"
                                        >
                                            {{
                                                formatGNF(
                                                    vehiculeSolvabilite.total_remaining,
                                                )
                                            }}
                                        </p>
                                    </div>
                                    <div>
                                        <p
                                            class="text-xs text-red-700 dark:text-red-400"
                                        >
                                            Limite autorisé
                                        </p>
                                        <p
                                            class="font-bold text-red-900 tabular-nums dark:text-red-200"
                                        >
                                            {{
                                                formatGNF(
                                                    vehiculeSolvabilite.seuil_impayes,
                                                )
                                            }}
                                        </p>
                                    </div>
                                    <div>
                                        <p
                                            class="text-xs text-red-700 dark:text-red-400"
                                        >
                                            Dépassement
                                        </p>
                                        <p
                                            class="font-bold text-red-900 tabular-nums dark:text-red-200"
                                        >
                                            +{{
                                                formatGNF(
                                                    vehiculeSolvabilite.depassement,
                                                )
                                            }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Client -->
                        <div>
                            <Label class="mb-1.5 block text-sm"> Client </Label>
                            <AutoComplete
                                v-model="clientSelected"
                                :suggestions="clientSuggests"
                                :option-label="clientLabel"
                                @complete="searchClient"
                                @item-select="onClientSelect(clientSelected)"
                                @clear="onClientClear"
                                placeholder="Nom, prénom, téléphone…"
                                class="w-full"
                                input-class="w-full"
                                :class="{ 'p-invalid': form.errors.client_id }"
                                dropdown
                                force-selection
                            >
                                <template #option="{ option }">
                                    <div class="py-0.5">
                                        <div class="leading-tight font-medium">
                                            {{ option.nom_complet }}
                                        </div>
                                        <div
                                            v-if="option.telephone"
                                            class="mt-0.5 text-xs text-muted-foreground"
                                        >
                                            {{
                                                formatPhoneDisplay(
                                                    option.telephone,
                                                )
                                            }}
                                        </div>
                                    </div>
                                </template>
                                <template #empty>
                                    <span class="text-sm text-muted-foreground"
                                        >Aucun client trouvé.</span
                                    >
                                </template>
                            </AutoComplete>
                            <p
                                v-if="form.errors.client_id"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.client_id }}
                            </p>

                            <!-- Véhicule partenaire : facultatif, jamais requis pour vendre -->
                            <div
                                v-if="
                                    clientSelected?.type === 'partenaire' &&
                                    selectedClientVehicules.length > 0
                                "
                                class="mt-3"
                            >
                                <Label class="mb-1.5 block text-sm">
                                    Véhicule partenaire (facultatif)
                                </Label>
                                <Dropdown
                                    v-model="form.client_vehicule_id"
                                    :options="selectedClientVehicules"
                                    option-label="libelle_affiche"
                                    option-value="id"
                                    placeholder="Non renseigné"
                                    class="w-full"
                                    show-clear
                                />
                            </div>

                            <!-- Solvabilité client — n'est le facteur de blocage QUE si aucun
                            véhicule n'est sélectionné (le véhicule est alors prioritaire, cf.
                            SolvabiliteService et commandeBloquee ci-dessus) : ces 4 panneaux
                            restent tous masqués dès qu'un véhicule est choisi, même si un client
                            est également renseigné, pour ne jamais laisser croire que la dette
                            du client est prise en compte alors qu'elle ne l'est pas. -->
                            <div
                                v-if="
                                    clientSolvabiliteLoading &&
                                    !form.vehicule_id
                                "
                                class="mt-3 flex items-center gap-2 text-xs text-muted-foreground"
                            >
                                <svg
                                    class="h-3.5 w-3.5 animate-spin"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                >
                                    <circle
                                        class="opacity-25"
                                        cx="12"
                                        cy="12"
                                        r="10"
                                        stroke="currentColor"
                                        stroke-width="4"
                                    />
                                    <path
                                        class="opacity-75"
                                        fill="currentColor"
                                        d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"
                                    />
                                </svg>
                                Vérification en cours…
                            </div>

                            <!-- ✅ Aucun impayé -->
                            <p
                                v-else-if="
                                    !form.vehicule_id &&
                                    clientSolvabilite &&
                                    !clientSolvabilite.has_debt
                                "
                                class="mt-2 flex items-center gap-1 text-xs font-medium text-emerald-600 dark:text-emerald-400"
                            >
                                <span>✓</span>
                                Client à jour
                            </p>

                            <!-- ⚠ Dettes (dans les limites) -->
                            <div
                                v-else-if="
                                    !form.vehicule_id &&
                                    clientSolvabilite &&
                                    clientSolvabilite.has_debt &&
                                    !clientSolvabilite.blocked
                                "
                                class="mt-3 rounded-xl border p-3"
                                :class="
                                    clientSolvabilite.status === 'impaye'
                                        ? 'border-red-200 bg-red-50 dark:border-red-800 dark:bg-red-950/30'
                                        : 'border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/30'
                                "
                            >
                                <div
                                    class="flex items-start justify-between gap-3"
                                >
                                    <div class="flex items-start gap-2.5">
                                        <span
                                            class="mt-0.5 text-base"
                                            :class="
                                                clientSolvabilite.status ===
                                                'impaye'
                                                    ? 'text-red-500'
                                                    : 'text-amber-500'
                                            "
                                            >⚠</span
                                        >
                                        <div>
                                            <p
                                                class="text-sm font-semibold"
                                                :class="
                                                    clientSolvabilite.status ===
                                                    'impaye'
                                                        ? 'text-red-800 dark:text-red-300'
                                                        : 'text-amber-800 dark:text-amber-300'
                                                "
                                            >
                                                {{
                                                    clientSolvabilite.status ===
                                                    'impaye'
                                                        ? 'Factures impayées détectées'
                                                        : 'Paiement partiel'
                                                }}
                                            </p>
                                            <p
                                                class="mt-1.5 text-xs font-medium opacity-70"
                                                :class="
                                                    clientSolvabilite.status ===
                                                    'impaye'
                                                        ? 'text-red-800 dark:text-red-300'
                                                        : 'text-amber-800 dark:text-amber-300'
                                                "
                                            >
                                                Montant total impayé
                                            </p>
                                            <p
                                                class="text-xl font-bold"
                                                :class="
                                                    clientSolvabilite.status ===
                                                    'impaye'
                                                        ? 'text-red-800 dark:text-red-300'
                                                        : 'text-amber-800 dark:text-amber-300'
                                                "
                                            >
                                                {{
                                                    formatGNF(
                                                        clientSolvabilite.total_remaining,
                                                    )
                                                }}
                                            </p>
                                            <p
                                                class="mt-1 text-xs opacity-70"
                                                :class="
                                                    clientSolvabilite.status ===
                                                    'impaye'
                                                        ? 'text-red-800 dark:text-red-300'
                                                        : 'text-amber-800 dark:text-amber-300'
                                                "
                                            >
                                                Nombre de factures :
                                                {{
                                                    clientSolvabilite.unpaid_invoices_count
                                                }}
                                            </p>
                                            <p
                                                v-if="
                                                    clientSolvabilite.last_invoice_reference
                                                "
                                                class="mt-1 text-xs opacity-60"
                                                :class="
                                                    clientSolvabilite.status ===
                                                    'impaye'
                                                        ? 'text-red-800 dark:text-red-300'
                                                        : 'text-amber-800 dark:text-amber-300'
                                                "
                                            >
                                                Dernière :
                                                {{
                                                    clientSolvabilite.last_invoice_reference
                                                }}
                                                ·
                                                {{
                                                    formatDate(
                                                        clientSolvabilite.last_invoice_date,
                                                    )
                                                }}
                                            </p>
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        class="shrink-0 rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors"
                                        :class="
                                            clientSolvabilite.status ===
                                            'impaye'
                                                ? 'border-red-300 bg-white text-red-700 hover:bg-red-100 dark:border-red-700 dark:bg-red-950/60 dark:text-red-300 dark:hover:bg-red-900/60'
                                                : 'border-amber-300 bg-white text-amber-700 hover:bg-amber-100 dark:border-amber-700 dark:bg-amber-950/60 dark:text-amber-300 dark:hover:bg-amber-900/60'
                                        "
                                        @click="
                                            ouvrirDialogFactures(
                                                clientSolvabilite,
                                                {
                                                    type: 'client',
                                                    titre: clientSelected
                                                        ? clientLabel(
                                                              clientSelected,
                                                          )
                                                        : 'Client',
                                                    sousTitre:
                                                        clientSelected?.telephone
                                                            ? formatPhoneDisplay(
                                                                  clientSelected.telephone,
                                                              )
                                                            : undefined,
                                                },
                                            )
                                        "
                                    >
                                        Voir les factures
                                    </button>
                                </div>
                            </div>

                            <!-- 🚫 Commande bloquée -->
                            <div
                                v-else-if="
                                    !form.vehicule_id &&
                                    clientSolvabilite?.blocked
                                "
                                class="mt-3 rounded-xl border border-red-300 bg-red-100 p-3 dark:border-red-700 dark:bg-red-950/50"
                            >
                                <div
                                    class="mb-3 flex items-center justify-between gap-3"
                                >
                                    <p
                                        class="text-xs font-bold tracking-wide text-red-800 uppercase dark:text-red-300"
                                    >
                                        Commande bloquée — seuil dépassé
                                    </p>
                                    <button
                                        type="button"
                                        class="shrink-0 rounded-lg border border-red-300 bg-white px-3 py-1.5 text-xs font-medium text-red-700 transition-colors hover:bg-red-100 dark:border-red-700 dark:bg-red-950/60 dark:text-red-300 dark:hover:bg-red-900/60"
                                        @click="
                                            ouvrirDialogFactures(
                                                clientSolvabilite,
                                                {
                                                    type: 'client',
                                                    titre: clientSelected
                                                        ? clientLabel(
                                                              clientSelected,
                                                          )
                                                        : 'Client',
                                                    sousTitre:
                                                        clientSelected?.telephone
                                                            ? formatPhoneDisplay(
                                                                  clientSelected.telephone,
                                                              )
                                                            : undefined,
                                                },
                                            )
                                        "
                                    >
                                        Voir les factures
                                    </button>
                                </div>
                                <div class="grid grid-cols-3 gap-2 text-center">
                                    <div>
                                        <p
                                            class="text-xs text-red-700 dark:text-red-400"
                                        >
                                            Dette actuelle
                                        </p>
                                        <p
                                            class="font-bold text-red-900 tabular-nums dark:text-red-200"
                                        >
                                            {{
                                                formatGNF(
                                                    clientSolvabilite.total_remaining,
                                                )
                                            }}
                                        </p>
                                    </div>
                                    <div>
                                        <p
                                            class="text-xs text-red-700 dark:text-red-400"
                                        >
                                            Seuil autorisé
                                        </p>
                                        <p
                                            class="font-bold text-red-900 tabular-nums dark:text-red-200"
                                        >
                                            {{
                                                formatGNF(
                                                    clientSolvabilite.seuil_impayes,
                                                )
                                            }}
                                        </p>
                                    </div>
                                    <div>
                                        <p
                                            class="text-xs text-red-700 dark:text-red-400"
                                        >
                                            Dépassement
                                        </p>
                                        <p
                                            class="font-bold text-red-900 tabular-nums dark:text-red-200"
                                        >
                                            +{{
                                                formatGNF(
                                                    clientSolvabilite.depassement,
                                                )
                                            }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Hint véhicule ou client -->
                    <p
                        v-if="!form.vehicule_id && !form.client_id"
                        class="mt-3 text-xs text-amber-600 dark:text-amber-400"
                    >
                        Sélectionnez au moins un véhicule ou un client.
                    </p>
                </div>

                <!-- Lignes de commande -->
                <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
                    <h2
                        class="mb-5 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Lignes de commande
                    </h2>

                    <p
                        v-if="form.errors.lignes"
                        class="mb-3 text-xs text-destructive"
                    >
                        {{ form.errors.lignes }}
                    </p>

                    <p
                        v-if="!canUpdateUnitPrice"
                        class="mb-3 flex items-center gap-1 text-xs text-muted-foreground"
                    >
                        <Lock class="h-3.5 w-3.5" />
                        Prix unitaire verrouille pour votre profil.
                    </p>

                    <!-- Règle de tarification + capacité — affichées une seule fois -->
                    <div
                        v-if="
                            modeTarification === 'prix_usine' ||
                            form.vehicule_id !== null
                        "
                        class="mb-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs"
                    >
                        <span
                            v-if="modeTarification === 'prix_usine'"
                            class="inline-flex items-center gap-1 font-medium text-amber-600 dark:text-amber-400"
                        >
                            Prix appliqué : Prix usine
                            <Info
                                v-tooltip.top="
                                    'L\'usine encaisse uniquement le prix usine (marge non répercutée).'
                                "
                                class="h-3.5 w-3.5 cursor-help"
                            />
                        </span>
                        <span
                            v-if="
                                form.vehicule_id !== null && !commissionEligible
                            "
                            class="inline-flex items-center gap-1 font-medium text-amber-600 dark:text-amber-400"
                        >
                            Aucune commission ne sera générée
                            <Info
                                v-tooltip.top="
                                    'Ce véhicule n\'est pas éligible aux commissions (indépendamment du mode de tarification).'
                                "
                                class="h-3.5 w-3.5 cursor-help"
                            />
                        </span>
                        <template v-if="form.vehicule_id !== null">
                            <span
                                v-if="capacitesSelectionnees.length === 0"
                                class="text-muted-foreground"
                            >
                                Véhicule non plafonné
                            </span>
                            <span
                                v-for="c in usageParCategorie"
                                :key="c.categorie_id"
                                :class="
                                    capaciteLigneClass(c.qte, c.capacite_max)
                                "
                            >
                                {{ c.categorie_nom }} : {{ c.qte }} /
                                {{ c.capacite_max }}
                                <span v-if="c.qte === c.capacite_max">✓</span>
                                <span v-else-if="c.qte < c.capacite_max">
                                    ({{ c.capacite_max - c.qte }} manquant(s){{
                                        !autoriser_saisie_dessous_qte_max
                                            ? ' — chargement complet requis'
                                            : ''
                                    }})</span
                                >
                                <span v-else>
                                    (+{{ c.qte - c.capacite_max }} en
                                    trop)</span
                                >
                            </span>
                        </template>
                    </div>

                    <!-- ── Tableau desktop ── -->
                    <div
                        class="hidden overflow-hidden rounded-lg border sm:block"
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
                                        style="width: 110px"
                                    >
                                        <span
                                            class="inline-flex items-center justify-center gap-1"
                                        >
                                            Qté
                                            <Lock
                                                v-if="!can_modifier_qte"
                                                class="h-3.5 w-3.5"
                                            />
                                        </span>
                                    </th>
                                    <th
                                        class="px-4 py-2.5 text-right font-medium text-muted-foreground"
                                        style="width: 180px"
                                    >
                                        <span
                                            class="inline-flex items-center justify-end gap-1"
                                        >
                                            {{ prixUnitLabel }}
                                            <Lock
                                                v-if="!unitPriceEditable"
                                                class="h-3.5 w-3.5"
                                            />
                                        </span>
                                    </th>
                                    <th
                                        class="px-4 py-2.5 text-right font-medium text-muted-foreground"
                                        style="width: 160px"
                                    >
                                        {{ totalColumnLabel }}
                                    </th>
                                    <th
                                        class="px-4 py-2.5"
                                        style="width: 48px"
                                    ></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr
                                    v-for="(ligne, index) in form.lignes"
                                    :key="index"
                                    class="hover:bg-muted/10"
                                >
                                    <td class="px-4 py-3">
                                        <Dropdown
                                            :model-value="ligne.produit_id"
                                            @update:model-value="
                                                onProduitChange(index, $event)
                                            "
                                            :options="produitOptions"
                                            option-label="label"
                                            option-value="value"
                                            placeholder="Choisir un produit..."
                                            filter
                                            :disabled="commandeBloquee"
                                            class="w-full"
                                            :class="{
                                                'p-invalid': (
                                                    form.errors as any
                                                )[`lignes.${index}.produit_id`],
                                            }"
                                        />
                                        <p
                                            v-if="
                                                (form.errors as any)[
                                                    `lignes.${index}.produit_id`
                                                ]
                                            "
                                            class="mt-1 text-xs text-destructive"
                                        >
                                            {{
                                                (form.errors as any)[
                                                    `lignes.${index}.produit_id`
                                                ]
                                            }}
                                        </p>
                                    </td>
                                    <td class="px-4 py-3">
                                        <InputNumber
                                            :model-value="ligne.qte"
                                            @update:model-value="
                                                onQteChange(index, $event)
                                            "
                                            :min="1"
                                            :max="
                                                can_modifier_qte
                                                    ? undefined
                                                    : maxPourLigne(
                                                          ligne.produit_id,
                                                      )
                                            "
                                            :disabled="commandeBloquee"
                                            :use-grouping="false"
                                            class="w-full"
                                            input-class="w-full text-center"
                                        />
                                    </td>
                                    <td class="px-4 py-3">
                                        <InputNumber
                                            :model-value="ligneUnitPrice(ligne)"
                                            @update:model-value="
                                                onPrixChange(index, $event)
                                            "
                                            :min="0"
                                            :disabled="!unitPriceEditable"
                                            :use-grouping="false"
                                            suffix=" GNF"
                                            class="w-full"
                                            input-class="w-full text-right"
                                        />
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right font-medium tabular-nums"
                                    >
                                        {{
                                            ligne.total > 0
                                                ? formatGNF(ligne.total)
                                                : '—'
                                        }}
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            class="h-7 w-7 text-destructive hover:text-destructive"
                                            :disabled="form.lignes.length <= 1"
                                            @click="removeLigne(index)"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                        </Button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- ── Cards mobile ── -->
                    <div class="space-y-3 sm:hidden">
                        <div
                            v-for="(ligne, index) in form.lignes"
                            :key="index"
                            class="rounded-xl border bg-muted/20 p-3"
                        >
                            <!-- Produit -->
                            <Dropdown
                                :model-value="ligne.produit_id"
                                @update:model-value="
                                    onProduitChange(index, $event)
                                "
                                :options="produitOptions"
                                option-label="label"
                                option-value="value"
                                placeholder="Choisir un produit..."
                                filter
                                :disabled="commandeBloquee"
                                class="w-full"
                                :class="{
                                    'p-invalid': (form.errors as any)[
                                        `lignes.${index}.produit_id`
                                    ],
                                }"
                            />

                            <!-- Qté + Prix -->
                            <div class="mt-2.5 grid grid-cols-2 gap-2.5">
                                <div>
                                    <p
                                        class="mb-1 text-[11px] font-medium text-muted-foreground"
                                    >
                                        <span
                                            class="inline-flex items-center gap-1"
                                        >
                                            Quantité
                                            <Lock
                                                v-if="!can_modifier_qte"
                                                class="h-3.5 w-3.5"
                                            />
                                        </span>
                                    </p>
                                    <InputNumber
                                        :model-value="ligne.qte"
                                        @update:model-value="
                                            onQteChange(index, $event)
                                        "
                                        :min="1"
                                        :max="
                                            can_modifier_qte
                                                ? undefined
                                                : maxPourLigne(ligne.produit_id)
                                        "
                                        :disabled="commandeBloquee"
                                        :use-grouping="false"
                                        class="w-full"
                                        input-class="w-full text-center"
                                    />
                                </div>
                                <div>
                                    <p
                                        class="mb-1 text-[11px] font-medium text-muted-foreground"
                                    >
                                        <span
                                            class="inline-flex items-center gap-1"
                                        >
                                            {{ prixUnitLabel }} (GNF)
                                            <Lock
                                                v-if="!unitPriceEditable"
                                                class="h-3.5 w-3.5"
                                            />
                                        </span>
                                    </p>
                                    <InputNumber
                                        :model-value="ligneUnitPrice(ligne)"
                                        @update:model-value="
                                            onPrixChange(index, $event)
                                        "
                                        :min="0"
                                        :disabled="!unitPriceEditable"
                                        :use-grouping="false"
                                        class="w-full"
                                        input-class="w-full"
                                    />
                                </div>
                            </div>

                            <!-- Total + Supprimer -->
                            <div
                                class="mt-2.5 flex items-center justify-between"
                            >
                                <div>
                                    <p
                                        class="text-[11px] text-muted-foreground"
                                    >
                                        {{ totalColumnLabel }}
                                    </p>
                                    <p
                                        class="text-sm font-semibold tabular-nums"
                                    >
                                        {{
                                            ligne.total > 0
                                                ? formatGNF(ligne.total)
                                                : '—'
                                        }}
                                    </p>
                                </div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    class="h-8 w-8 text-destructive hover:text-destructive"
                                    :disabled="form.lignes.length <= 1"
                                    @click="removeLigne(index)"
                                >
                                    <Trash2 class="h-4 w-4" />
                                </Button>
                            </div>
                        </div>
                    </div>

                    <!-- Ajouter + Total -->
                    <div class="mt-4 flex items-center justify-between">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            :disabled="commandeBloquee"
                            @click="addLigne"
                        >
                            <Plus class="mr-2 h-4 w-4" />
                            Ajouter une ligne
                        </Button>
                        <div class="text-right">
                            <p
                                class="text-xs tracking-wider text-muted-foreground uppercase"
                            >
                                {{ totalCommandeLabel }}
                            </p>
                            <p class="text-2xl font-bold tabular-nums">
                                {{ formatGNF(totalGeneral) }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Spacer for mobile sticky footer -->
                <div class="h-20 sm:hidden" />

                <!-- Footer -->
                <div class="flex items-center justify-between">
                    <Link href="/backoffice/ventes">
                        <Button type="button" variant="outline">Retour</Button>
                    </Link>
                    <Button type="submit" :disabled="!canSubmit">
                        Créer la commande
                    </Button>
                </div>
            </form>
        </div>

        <!-- Mobile sticky footer -->
        <div
            class="fixed right-0 bottom-0 left-0 z-20 border-t border-border/60 bg-background/95 px-4 py-3 backdrop-blur-sm sm:hidden"
        >
            <Button class="w-full" :disabled="!canSubmit" @click="submit">
                <Save class="mr-2 h-4 w-4" />
                Créer la commande
            </Button>
        </div>

        <!-- Dialog Confirmation création -->
        <Dialog
            v-model:visible="showConfirmDialog"
            modal
            :closable="true"
            :style="{ width: '720px', maxWidth: '95vw' }"
            :pt="{
                root: { class: 'rounded-2xl shadow-2xl' },
                header: {
                    class: 'rounded-t-2xl border-b border-border px-6 py-4',
                },
                content: { class: 'p-0' },
            }"
        >
            <template #header>
                <div>
                    <div class="flex items-center gap-2.5">
                        <h2 class="text-lg font-semibold">
                            Confirmer la création de la commande
                        </h2>
                        <span
                            class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold"
                            :class="
                                isCommandeLogistique
                                    ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300'
                                    : 'bg-violet-100 text-violet-800 dark:bg-violet-900/40 dark:text-violet-300'
                            "
                        >
                            {{
                                isCommandeLogistique
                                    ? 'Vente avec livraison'
                                    : 'Vente directe'
                            }}
                        </span>
                    </div>
                    <p class="mt-0.5 text-sm text-muted-foreground">
                        Vérifiez le récapitulatif avant de valider.
                    </p>
                </div>
            </template>

            <!-- Informations générales -->
            <div
                class="grid grid-cols-2 gap-x-8 gap-y-4 border-b border-border p-5"
            >
                <div>
                    <p class="text-xs text-muted-foreground">Site</p>
                    <p class="mt-0.5 font-medium">{{ user_site.label }}</p>
                </div>
                <div>
                    <p class="text-xs text-muted-foreground">Véhicule</p>
                    <p class="mt-0.5 font-medium">
                        {{
                            vehiculeSelected
                                ? vehiculeLabel(vehiculeSelected)
                                : '—'
                        }}
                    </p>
                </div>
                <div>
                    <p class="text-xs text-muted-foreground">Client</p>
                    <p class="mt-0.5 font-medium">
                        {{ clientSelected ? clientLabel(clientSelected) : '—' }}
                    </p>
                </div>
                <div>
                    <p class="text-xs text-muted-foreground">Chauffeur</p>
                    <template v-if="vehiculeSelected?.livreur_nom">
                        <p class="mt-0.5 font-medium">
                            {{ vehiculeSelected.livreur_nom }}
                        </p>
                        <p
                            class="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground"
                        >
                            <Phone class="h-3 w-3 shrink-0" />
                            {{
                                vehiculeSelected.livreur_telephone
                                    ? formatPhoneDisplay(
                                          vehiculeSelected.livreur_telephone,
                                      )
                                    : 'Non renseigné'
                            }}
                        </p>
                    </template>
                    <p v-else class="mt-0.5 text-sm text-muted-foreground">
                        Non affecté
                    </p>
                </div>
            </div>

            <!-- Produits -->
            <div class="border-b border-border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50">
                        <tr class="border-b border-border">
                            <th
                                class="px-5 py-2.5 text-left text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                            >
                                Produit
                            </th>
                            <th
                                class="px-4 py-2.5 text-right text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                            >
                                Demandée
                            </th>
                            <th
                                class="px-4 py-2.5 text-right text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                            >
                                {{ prixUnitLabel }}
                            </th>
                            <th
                                class="px-5 py-2.5 text-right text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                            >
                                {{ totalColumnLabel }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr
                            v-for="(ligne, i) in lignesVisibles"
                            :key="i"
                            class="hover:bg-muted/30"
                        >
                            <td class="px-5 py-3 font-medium">
                                {{ nomProduit(ligne.produit_id) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ ligne.qte }}
                            </td>
                            <td
                                class="px-4 py-3 text-right text-muted-foreground tabular-nums"
                            >
                                {{ formatGNF(ligneUnitPrice(ligne)) }}
                            </td>
                            <td
                                class="px-5 py-3 text-right font-semibold tabular-nums"
                            >
                                {{ formatGNF(ligne.total) }}
                            </td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t border-border">
                        <tr>
                            <td colspan="2"></td>
                            <td
                                class="px-4 py-2.5 text-right text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                            >
                                Qté totale
                            </td>
                            <td
                                class="px-5 py-2.5 text-right font-semibold tabular-nums"
                            >
                                {{ quantiteTotale }} packs
                            </td>
                        </tr>
                        <tr class="border-t border-border">
                            <td colspan="2"></td>
                            <td
                                class="px-4 py-3 text-right text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                            >
                                {{ totalCommandeLabel }}
                            </td>
                            <td
                                class="px-5 py-3 text-right text-xl font-bold tabular-nums"
                            >
                                {{ formatGNF(totalGeneral) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Alertes -->
            <div
                v-if="
                    vehiculeSolvabilite?.has_debt ||
                    (!form.vehicule_id && clientSolvabilite?.has_debt)
                "
                class="space-y-2 border-b border-border bg-amber-50 px-5 py-3 dark:bg-amber-950/20"
            >
                <p
                    class="text-xs font-semibold tracking-wide text-amber-700 uppercase dark:text-amber-400"
                >
                    Alertes
                </p>
                <div
                    v-if="vehiculeSolvabilite?.has_debt"
                    class="flex items-center gap-2 text-sm text-amber-800 dark:text-amber-300"
                >
                    <span>⚠</span>
                    <span
                        >Véhicule : factures impayées —
                        <strong>{{
                            formatGNF(vehiculeSolvabilite.total_remaining)
                        }}</strong></span
                    >
                </div>
                <div
                    v-if="!form.vehicule_id && clientSolvabilite?.has_debt"
                    class="flex items-center gap-2 text-sm text-amber-800 dark:text-amber-300"
                >
                    <span>⚠</span>
                    <span
                        >Client : factures impayées —
                        <strong>{{
                            formatGNF(clientSolvabilite.total_remaining)
                        }}</strong></span
                    >
                </div>
            </div>

            <!-- Actions -->
            <div class="flex items-center justify-between px-5 py-4">
                <button
                    type="button"
                    class="rounded-lg border bg-card px-4 py-2 text-sm font-medium hover:bg-muted/50"
                    @click="showConfirmDialog = false"
                >
                    Retour à la saisie
                </button>
                <button
                    type="button"
                    class="rounded-lg bg-primary px-5 py-2 text-sm font-semibold text-primary-foreground transition-opacity hover:opacity-90 disabled:opacity-50"
                    :disabled="form.processing || commandeBloquee"
                    @click="confirmerEtCreer"
                >
                    {{
                        form.processing
                            ? 'Création en cours…'
                            : 'Confirmer et créer'
                    }}
                </button>
            </div>
        </Dialog>

        <!-- Dialog Factures impayées -->
        <Dialog
            v-model:visible="showFacturesDialog"
            modal
            :closable="true"
            :style="{ width: '960px', maxWidth: '95vw' }"
            :pt="{
                root: { class: 'rounded-2xl shadow-2xl' },
                header: {
                    class: 'rounded-t-2xl border-b border-border px-6 py-4',
                },
                content: { class: 'p-0' },
            }"
        >
            <template #header>
                <div>
                    <h2 class="text-lg font-semibold">Factures impayées</h2>
                    <div v-if="dialogContext" class="mt-1 space-y-0.5">
                        <p class="text-sm font-medium text-foreground">
                            {{ dialogContext.titre }}
                        </p>
                        <p
                            v-if="dialogContext.chauffeur"
                            class="text-xs text-muted-foreground"
                        >
                            Chauffeur : {{ dialogContext.chauffeur }}
                        </p>
                        <p
                            v-if="dialogContext.sousTitre"
                            class="text-xs text-muted-foreground"
                        >
                            {{ dialogContext.sousTitre }}
                        </p>
                    </div>
                </div>
            </template>

            <template v-if="dialogSolvabilite">
                <!-- KPI cards -->
                <div
                    class="grid grid-cols-2 gap-3 border-b border-border p-5 sm:grid-cols-4"
                >
                    <div class="rounded-xl border bg-card p-3 text-center">
                        <p class="text-2xl font-bold tabular-nums">
                            {{ dialogSolvabilite.unpaid_invoices_count }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Facture(s)
                        </p>
                    </div>
                    <div class="rounded-xl border bg-card p-3 text-center">
                        <p class="text-lg font-bold tabular-nums">
                            {{ formatGNF(dialogSolvabilite.total_encaisse) }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Encaissé
                        </p>
                    </div>
                    <div class="rounded-xl border bg-card p-3 text-center">
                        <p class="text-lg font-bold tabular-nums">
                            {{
                                formatGNF(
                                    dialogSolvabilite.total_remaining +
                                        dialogSolvabilite.total_encaisse,
                                )
                            }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Montant total
                        </p>
                    </div>
                    <div class="rounded-xl border bg-card p-3 text-center">
                        <p
                            class="text-lg font-bold text-red-600 tabular-nums dark:text-red-400"
                        >
                            {{ formatGNF(dialogSolvabilite.total_remaining) }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Total impayé
                        </p>
                    </div>
                </div>

                <!-- Table -->
                <div class="max-h-[420px] overflow-y-auto">
                    <table class="w-full text-sm">
                        <thead
                            class="sticky top-0 bg-muted/60 backdrop-blur-sm"
                        >
                            <tr class="border-b border-border">
                                <th
                                    class="px-4 py-2.5 text-left text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                                >
                                    Référence
                                </th>
                                <th
                                    class="px-4 py-2.5 text-left text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                                >
                                    Date
                                </th>
                                <th
                                    class="px-4 py-2.5 text-right text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                                >
                                    Montant
                                </th>
                                <th
                                    class="px-4 py-2.5 text-right text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                                >
                                    Encaissé
                                </th>
                                <th
                                    class="px-4 py-2.5 text-right text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                                >
                                    Reste
                                </th>
                                <th
                                    class="px-4 py-2.5 text-center text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                                >
                                    Statut
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr
                                v-for="f in dialogSolvabilite.factures"
                                :key="f.reference"
                                class="hover:bg-muted/30"
                            >
                                <td class="px-4 py-3">
                                    <a
                                        :href="`/backoffice/ventes/${f.commande_id}`"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="inline-flex items-center gap-1 font-mono text-xs text-primary underline underline-offset-2 hover:opacity-75"
                                    >
                                        {{ f.reference }}
                                        <ExternalLink
                                            class="h-3 w-3 shrink-0"
                                        />
                                    </a>
                                </td>
                                <td
                                    class="px-4 py-3 text-xs text-muted-foreground"
                                >
                                    {{ formatDate(f.date) }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ formatGNF(f.montant) }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right text-muted-foreground tabular-nums"
                                >
                                    {{ formatGNF(f.encaisse) }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ formatGNF(f.restant) }}
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <StatusDot
                                        :status="f.statut"
                                        :label="f.statut_label"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Footer -->
                <div class="flex justify-end border-t border-border px-5 py-3">
                    <button
                        type="button"
                        class="rounded-lg border bg-card px-4 py-2 text-sm font-medium hover:bg-muted/50"
                        @click="showFacturesDialog = false"
                    >
                        Fermer
                    </button>
                </div>
            </template>
        </Dialog>
    </AppLayout>
</template>
