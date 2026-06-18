<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPhoneDisplay } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Lock, Phone, Plus, Save, Trash2 } from 'lucide-vue-next';
import AutoComplete from 'primevue/autocomplete';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import { computed, onMounted, ref } from 'vue';

// ── Types ─────────────────────────────────────────────────────────────────────
interface FactureDetail {
    reference: string;
    date: string | null;
    montant: number;
    encaisse: number;
    restant: number;
    statut: string;
    statut_label: string;
}

interface SolvabiliteResult {
    has_debt: boolean;
    status: 'aucun' | 'partiel' | 'impaye';
    unpaid_invoices_count: number;
    total_remaining: number;
    total_encaisse: number;
    last_invoice_reference: string | null;
    last_invoice_date: string | null;
    factures: FactureDetail[];
}

interface ProduitOption {
    id: number;
    nom: string;
    prix_vente: number;
    prix_usine: number;
}

interface VehiculeOption {
    id: number;
    nom_vehicule: string;
    immatriculation: string;
    capacite_packs: number | null;
    livreur_nom: string | null;
    livreur_telephone: string | null;
}

interface ClientOption {
    id: number;
    nom: string;
    prenom: string | null;
    telephone: string | null;
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
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Ventes', href: '/ventes' },
    { title: 'Nouvelle commande', href: '/ventes/create' },
];

// ── Form ──────────────────────────────────────────────────────────────────────
const form = useForm({
    vehicule_id: null as number | null,
    client_id: null as number | null,
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
    if (v) {
        vehiculeSolvabiliteLoading.value = true;
        vehiculeSolvabilite.value = null;
        try {
            const res = await fetch(
                `/ventes/check-solvabilite?vehicule_id=${v.id}`,
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
}

function applyVehiculeCapacityOnSingleLine(vehicule: VehiculeOption | null) {
    if (!vehicule || vehicule.capacite_packs === null) {
        return;
    }

    if (form.lignes.length !== 1) {
        return;
    }

    form.lignes[0].qte = vehicule.capacite_packs;
    form.lignes[0].total = form.lignes[0].prix_vente * form.lignes[0].qte;
}

function vehiculeLabel(v: VehiculeOption): string {
    return `${v.nom_vehicule} — ${v.immatriculation}`;
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
                  c.nom.toLowerCase().includes(q) ||
                  (c.prenom && c.prenom.toLowerCase().includes(q)) ||
                  (c.telephone && c.telephone.includes(q)),
          )
        : [...props.clients];
}

async function onClientSelect(c: ClientOption | null) {
    form.client_id = c?.id ?? null;
    if (c) {
        clientSolvabiliteLoading.value = true;
        clientSolvabilite.value = null;
        try {
            const res = await fetch(
                `/ventes/check-solvabilite?client_id=${c.id}`,
            );
            clientSolvabilite.value = await res.json();
        } finally {
            clientSolvabiliteLoading.value = false;
        }
    }
}

function onClientClear() {
    form.client_id = null;
    clientSelected.value = null;
    clientSolvabilite.value = null;
}

function clientLabel(c: ClientOption): string {
    return [c.prenom, c.nom].filter(Boolean).join(' ');
}

// ── Solvabilité — dialog ──────────────────────────────────────────────────────
const showFacturesDialog = ref(false);
const dialogSolvabilite = ref<SolvabiliteResult | null>(null);
const dialogContextLabel = ref('');

function ouvrirDialogFactures(solv: SolvabiliteResult, label: string) {
    dialogSolvabilite.value = solv;
    dialogContextLabel.value = label;
    showFacturesDialog.value = true;
}

// ── Solvabilité helpers ───────────────────────────────────────────────────────
function formatDate(dateStr: string | null): string {
    if (!dateStr) return '—';
    const [y, m, d] = dateStr.split('-');
    return `${d}/${m}/${y}`;
}

function statutBadgeClass(statut: string): string {
    const map: Record<string, string> = {
        impayee: 'bg-red-100 text-red-700 dark:bg-red-950/40 dark:text-red-300',
        partiel:
            'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
        payee: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
        annulee: 'bg-muted text-muted-foreground',
    };
    return map[statut] ?? 'bg-muted text-muted-foreground';
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
        form.lignes[existingIndex].total =
            form.lignes[existingIndex].prix_vente *
            form.lignes[existingIndex].qte;
        form.lignes.splice(index, 1);
        return;
    }

    // Nouveau produit → capacité par défaut uniquement sur la 1re ligne
    const ligne = form.lignes[index];
    ligne.produit_id = produitId;
    const produit = props.produits.find((p) => p.id === produitId);
    ligne.prix_vente = produit ? produit.prix_vente : 0;
    const qteParDefaut =
        index === 0
            ? (capaciteVehiculeSelectionne.value ?? ligne.qte)
            : ligne.qte;
    ligne.qte = Math.max(1, qteParDefaut);
    ligne.total = ligne.prix_vente * ligne.qte;
}

function onQteChange(index: number, qte: number | null) {
    const ligne = form.lignes[index];
    ligne.qte = Math.max(1, qte ?? 1);
    ligne.total = ligne.prix_vente * ligne.qte;
}

function onPrixChange(index: number, prix: number | null) {
    if (!canUpdateUnitPrice.value) {
        return;
    }

    const ligne = form.lignes[index];
    ligne.prix_vente = prix ?? 0;
    ligne.total = ligne.prix_vente * ligne.qte;
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

const capaciteVehiculeSelectionne = computed(
    () => vehiculeSelectionne.value?.capacite_packs ?? null,
);

const capaciteVehiculeConforme = computed(() => {
    if (form.vehicule_id === null) return true;
    if (capaciteVehiculeSelectionne.value === null) return false;

    const qte = quantiteTotale.value;
    const cap = capaciteVehiculeSelectionne.value;

    // Dépassement : admin (can_modifier_qte) peut dépasser la capacité
    if (qte > cap) return props.can_modifier_qte;

    // En dessous → paramètre organisationnel, s'applique à tous
    if (qte < cap) return props.autoriser_saisie_dessous_qte_max;

    return true;
});

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
        form.lignes[0].total = first.prix_vente * form.lignes[0].qte;
    }
});

// ── Validation locale ────────────────────────────────────────────────────────
const canSubmit = computed(
    () =>
        (form.vehicule_id !== null || form.client_id !== null) &&
        totalGeneral.value > 0 &&
        capaciteVehiculeConforme.value &&
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
    form.post('/ventes');
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
                    href="/ventes"
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
                                                    option.capacite_packs !==
                                                    null
                                                "
                                                class="before:mr-2 before:content-['·']"
                                            >
                                                {{ option.capacite_packs }}
                                                packs
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

                            <!-- ✅ Aucun impayé -->
                            <div
                                v-else-if="
                                    vehiculeSolvabilite &&
                                    !vehiculeSolvabilite.has_debt
                                "
                                class="mt-3 rounded-xl border border-emerald-200 bg-emerald-50 p-3 dark:border-emerald-800 dark:bg-emerald-950/30"
                            >
                                <div class="flex items-start gap-2.5">
                                    <span
                                        class="mt-0.5 text-base text-emerald-600 dark:text-emerald-400"
                                        >✓</span
                                    >
                                    <div>
                                        <p
                                            class="text-sm font-semibold text-emerald-800 dark:text-emerald-300"
                                        > Ce véhicule est à jour de ses paiements.                                     
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- ⚠ Dettes -->
                            <div
                                v-else-if="
                                    vehiculeSolvabilite &&
                                    vehiculeSolvabilite.has_debt
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
                                                vehiculeSelected
                                                    ? vehiculeLabel(
                                                          vehiculeSelected,
                                                      )
                                                    : 'Véhicule',
                                            )
                                        "
                                    >
                                        Voir les factures
                                    </button>
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
                                            {{
                                                [option.prenom, option.nom]
                                                    .filter(Boolean)
                                                    .join(' ')
                                            }}
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

                            <!-- Solvabilité client -->
                            <div
                                v-if="clientSolvabiliteLoading"
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
                            <div
                                v-else-if="
                                    clientSolvabilite &&
                                    !clientSolvabilite.has_debt
                                "
                                class="mt-3 rounded-xl border border-emerald-200 bg-emerald-50 p-3 dark:border-emerald-800 dark:bg-emerald-950/30"
                            >
                                <div class="flex items-center gap-2.5">
                                    <span
                                        class="text-base text-emerald-600 dark:text-emerald-400"
                                        >✓</span
                                    >
                                    <p
                                        class="text-sm font-semibold text-emerald-800 dark:text-emerald-300"
                                    >
                                        Ce client est à jour de ses paiements.
                                    </p> 
                                </div>
                            </div>

                            <!-- ⚠ Dettes -->
                            <div
                                v-else-if="
                                    clientSolvabilite &&
                                    clientSolvabilite.has_debt
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
                                                clientSelected
                                                    ? clientSelected.nom
                                                    : 'Client',
                                            )
                                        "
                                    >
                                        Voir les factures
                                    </button>
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

                    <p
                        v-if="form.vehicule_id !== null"
                        class="mb-3 text-xs"
                        :class="
                            capaciteVehiculeConforme
                                ? quantiteTotale === capaciteVehiculeSelectionne
                                    ? 'text-emerald-600 dark:text-emerald-400'
                                    : 'text-amber-600 dark:text-amber-400'
                                : 'text-destructive'
                        "
                    >
                        Capacité véhicule:
                        {{
                            capaciteVehiculeSelectionne === null
                                ? 'non définie'
                                : `${capaciteVehiculeSelectionne} packs`
                        }}
                        · Quantité saisie: {{ quantiteTotale }} packs
                        <template v-if="capaciteVehiculeSelectionne !== null">
                            <span
                                v-if="
                                    quantiteTotale ===
                                    capaciteVehiculeSelectionne
                                "
                            >
                                — capacité atteinte ✓</span
                            >
                            <span
                                v-else-if="
                                    quantiteTotale < capaciteVehiculeSelectionne
                                "
                            >
                                —
                                {{
                                    capaciteVehiculeSelectionne - quantiteTotale
                                }}
                                pack(s) manquant(s){{
                                    !autoriser_saisie_dessous_qte_max
                                        ? ' — chargement complet requis'
                                        : ''
                                }}</span
                            >
                            <span v-else>
                                —
                                {{
                                    quantiteTotale - capaciteVehiculeSelectionne
                                }}
                                pack(s) en trop</span
                            >
                        </template>
                    </p>

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
                                            Prix unit.
                                            <Lock
                                                v-if="!canUpdateUnitPrice"
                                                class="h-3.5 w-3.5"
                                            />
                                        </span>
                                    </th>
                                    <th
                                        class="px-4 py-2.5 text-right font-medium text-muted-foreground"
                                        style="width: 160px"
                                    >
                                        Total
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
                                                    : (capaciteVehiculeSelectionne ??
                                                      undefined)
                                            "
                                            :use-grouping="false"
                                            class="w-full"
                                            input-class="w-full text-center"
                                        />
                                    </td>
                                    <td class="px-4 py-3">
                                        <InputNumber
                                            :model-value="ligne.prix_vente"
                                            @update:model-value="
                                                onPrixChange(index, $event)
                                            "
                                            :min="0"
                                            :disabled="!canUpdateUnitPrice"
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
                                                : (capaciteVehiculeSelectionne ??
                                                  undefined)
                                        "
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
                                            Prix unit. (GNF)
                                            <Lock
                                                v-if="!canUpdateUnitPrice"
                                                class="h-3.5 w-3.5"
                                            />
                                        </span>
                                    </p>
                                    <InputNumber
                                        :model-value="ligne.prix_vente"
                                        @update:model-value="
                                            onPrixChange(index, $event)
                                        "
                                        :min="0"
                                        :disabled="!canUpdateUnitPrice"
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
                                        Total ligne
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
                            @click="addLigne"
                        >
                            <Plus class="mr-2 h-4 w-4" />
                            Ajouter une ligne
                        </Button>
                        <div class="text-right">
                            <p
                                class="text-xs tracking-wider text-muted-foreground uppercase"
                            >
                                Total commande
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
                    <Link href="/ventes">
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
                    <h2 class="text-lg font-semibold">
                        Confirmer la création de la commande
                    </h2>
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
                                Prix unit.
                            </th>
                            <th
                                class="px-5 py-2.5 text-right text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                            >
                                Total
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
                                {{ formatGNF(ligne.prix_vente) }}
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
                                Total
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
                    vehiculeSolvabilite?.has_debt || clientSolvabilite?.has_debt
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
                    v-if="clientSolvabilite?.has_debt"
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
                    :disabled="form.processing"
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
                    <p
                        v-if="dialogContextLabel"
                        class="mt-0.5 text-sm text-muted-foreground"
                    >
                        {{ dialogContextLabel }}
                    </p>
                </div>
            </template>

            <template v-if="dialogSolvabilite">
                <!-- KPI cards -->
                <div
                    class="grid grid-cols-2 gap-3 border-b border-border p-5 sm:grid-cols-4"
                >
                    <div class="rounded-xl border bg-card p-3 text-center">
                        <p
                            class="text-2xl font-bold text-red-600 dark:text-red-400"
                        >
                            {{ dialogSolvabilite.unpaid_invoices_count }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Facture(s)
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
                    <div class="rounded-xl border bg-card p-3 text-center">
                        <p
                            class="text-lg font-bold text-emerald-600 tabular-nums dark:text-emerald-400"
                        >
                            {{ formatGNF(dialogSolvabilite.total_encaisse) }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Encaissé
                        </p>
                    </div>
                    <div class="rounded-xl border bg-card p-3 text-center">
                        <p
                            class="text-lg font-bold text-amber-600 tabular-nums dark:text-amber-400"
                        >
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
                                <td
                                    class="px-4 py-3 font-mono text-xs text-muted-foreground"
                                >
                                    {{ f.reference }}
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    {{ formatDate(f.date) }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ formatGNF(f.montant) }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right text-emerald-700 tabular-nums dark:text-emerald-400"
                                >
                                    {{ formatGNF(f.encaisse) }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right font-semibold text-red-700 tabular-nums dark:text-red-400"
                                >
                                    {{ formatGNF(f.restant) }}
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span
                                        class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="statutBadgeClass(f.statut)"
                                    >
                                        {{ f.statut_label }}
                                    </span>
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
