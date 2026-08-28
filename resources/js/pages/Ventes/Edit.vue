<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/composables/usePermissions';
import { useVehiculeCommandeTarification } from '@/composables/useVehiculeCommandeTarification';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPhoneDisplay } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Lock, Plus, Save, Trash2 } from 'lucide-vue-next';
import AutoComplete from 'primevue/autocomplete';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import { computed, onMounted, ref } from 'vue';

// ── Types ─────────────────────────────────────────────────────────────────────
interface ProduitOption {
    id: number;
    nom: string;
    categorie_id: number | null;
    prix_vente: number;
    prix_usine: number;
    // Tarification par nature de client (cf. PrixVenteNatureResolver côté serveur) —
    // réservée aux produits fabricables, null = pas de tarif spécifique configuré.
    is_fabricable: boolean;
    prix_externe: number | null;
    prix_revendeur: number | null;
    prix_distributeur: number | null;
}

type PrixOrigine = 'usine' | 'vente' | 'externe' | 'revendeur' | 'distributeur';

const PRIX_ORIGINE_LABELS: Record<PrixOrigine, string> = {
    usine: 'Prix usine',
    vente: 'Prix vente',
    externe: 'Prix externe',
    revendeur: 'Prix revendeur',
    distributeur: 'Prix distributeur',
};

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
}

interface ClientVehiculeOption {
    id: number;
    libelle_affiche: string;
}

interface ClientOption {
    id: number;
    nom_complet: string;
    telephone: string | null;
    type: 'externe' | 'revendeur' | 'distributeur';
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

interface CommandeExistante {
    id: number;
    reference: string;
    vehicule_id: number | null;
    client_id: number | null;
    client_vehicule_id: number | null;
    lignes: { produit_id: number; qte: number; prix_vente: number }[];
}

// ── Props ─────────────────────────────────────────────────────────────────────
const props = defineProps<{
    commande: CommandeExistante;
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
    {
        title: props.commande.reference,
        href: `/backoffice/ventes/${props.commande.id}`,
    },
    { title: 'Modifier', href: '#' },
];

// ── Form ──────────────────────────────────────────────────────────────────────
function produitPrixUsineFrom(
    produits: ProduitOption[],
    produitId: number | null,
): number {
    if (produitId === null) return 0;
    return produits.find((p) => p.id === produitId)?.prix_usine ?? 0;
}

// Un véhicule de flotte (toujours livraison_vente=true dans ce picker) facture
// toujours au prix de vente plein ; sans véhicule, seul un client externe
// facture à prix usine — cf. useVehiculeCommandeTarification.
const initialClient = props.clients.find(
    (c) => c.id === props.commande.client_id,
);
const initialModeTarification: 'prix_vente' | 'prix_usine' =
    !props.commande.vehicule_id && initialClient?.type === 'externe'
        ? 'prix_usine'
        : 'prix_vente';

const form = useForm({
    vehicule_id: props.commande.vehicule_id as number | null,
    client_id: props.commande.client_id as number | null,
    client_vehicule_id: props.commande.client_vehicule_id as number | null,
    lignes: props.commande.lignes.map((l) => ({
        produit_id: l.produit_id,
        qte: l.qte,
        prix_vente: l.prix_vente,
        total:
            initialModeTarification === 'prix_usine'
                ? produitPrixUsineFrom(props.produits, l.produit_id) * l.qte
                : l.prix_vente * l.qte,
    })) as LigneForm[],
});

// ── AutoComplete : Véhicule ───────────────────────────────────────────────────
const vehiculeSelected = ref<VehiculeOption | null>(
    props.vehicules.find((v) => v.id === props.commande.vehicule_id) ?? null,
);
const vehiculeSuggests = ref<VehiculeOption[]>([]);

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

function onVehiculeSelect(v: VehiculeOption | null) {
    form.vehicule_id = v?.id ?? null;
    applyVehiculeCapacityOnSingleLine(v);
    recomputeAllTotals();
}

function onVehiculeClear() {
    form.vehicule_id = null;
    vehiculeSelected.value = null;
    recomputeAllTotals();
}

// Pré-remplit la quantité de l'unique ligne à la capacité du véhicule — seulement quand ce
// véhicule n'a qu'un seul groupe de capacité configuré (sinon ambigu : lequel choisir ?).
function applyVehiculeCapacityOnSingleLine(vehicule: VehiculeOption | null) {
    if (!vehicule || vehicule.capacites.length !== 1) {
        return;
    }

    if (form.lignes.length !== 1) {
        return;
    }

    form.lignes[0].qte = vehicule.capacites[0].capacite_max;
    form.lignes[0].total = computeLigneTotal(form.lignes[0]);
}

// ── Mode de tarification & éligibilité aux commissions ────────────────────────
// Cf. Ventes/Create.vue / useVehiculeCommandeTarification — miroir
// d'affichage, la source de vérité est VehiculeCommandeContextResolver côté
// serveur.
const { modeTarification, commissionEligible } =
    useVehiculeCommandeTarification(
        () => props.vehicules,
        () => form.vehicule_id,
        () => props.clients,
        () => form.client_id,
    );

// ── Prix affiché — "Prix appliqué" plutôt qu'un intitulé de colonne unique :
// des lignes différentes peuvent relever de politiques de prix différentes
// dans la même commande (ex: un produit fabricable au tarif Revendeur à côté
// d'un produit classique au prix de vente), donc chaque ligne affiche à la
// fois le montant et l'origine de son propre prix (cf. resoudrePrixLigne()).
const prixUnitLabel = 'Prix appliqué';
const totalColumnLabel = 'Total';
const totalCommandeLabel = 'Total commande';

/**
 * Résolution du prix RÉELLEMENT appliqué à une ligne — miroir d'affichage de
 * PrixVenteNatureResolver (backend, seul juge à l'enregistrement) :
 *  - produit fabricable + client sélectionné → tarif de la nature du client
 *    (Externe/Revendeur/Distributeur), ou repli sur prix_vente si ce tarif
 *    n'est pas configuré pour ce produit ;
 *  - sinon → comportement historique (modeTarification global : prix_usine
 *    pour un client Externe sans véhicule, prix_vente saisi/éditable sinon).
 */
function resoudrePrixLigne(ligne: LigneForm): {
    montant: number;
    origine: PrixOrigine;
} {
    const produit = props.produits.find((p) => p.id === ligne.produit_id);

    if (produit?.is_fabricable && clientSelected.value) {
        const tarifsParNature: Record<ClientOption['type'], number | null> = {
            externe: produit.prix_externe,
            revendeur: produit.prix_revendeur,
            distributeur: produit.prix_distributeur,
        };
        const nature = clientSelected.value.type;
        const tarif = tarifsParNature[nature];
        if (tarif !== null && tarif !== undefined) {
            return { montant: tarif, origine: nature };
        }

        return { montant: produit.prix_vente, origine: 'vente' };
    }

    if (modeTarification.value === 'prix_usine') {
        return {
            montant: produitPrixUsineFrom(props.produits, ligne.produit_id),
            origine: 'usine',
        };
    }

    return { montant: ligne.prix_vente, origine: 'vente' };
}

function ligneUnitPrice(ligne: LigneForm): number {
    return resoudrePrixLigne(ligne).montant;
}
function ligneOrigineLabel(ligne: LigneForm): string {
    return PRIX_ORIGINE_LABELS[resoudrePrixLigne(ligne).origine];
}
/**
 * Une ligne au tarif de nature (fabricable + client) n'est jamais éditable — le serveur
 * ignore de toute façon le prix soumis pour ces lignes (cf. CommandeVenteController::
 * buildLignesDataAndTotal()), l'éditer donnerait une fausse impression de contrôle.
 */
function ligneUnitPriceEditable(ligne: LigneForm): boolean {
    const produit = props.produits.find((p) => p.id === ligne.produit_id);
    if (produit?.is_fabricable && clientSelected.value) {
        return false;
    }

    return canUpdateUnitPrice.value && modeTarification.value !== 'prix_usine';
}

function computeLigneTotal(ligne: LigneForm): number {
    return resoudrePrixLigne(ligne).montant * ligne.qte;
}

function recomputeAllTotals() {
    form.lignes.forEach((l) => {
        l.total = computeLigneTotal(l);
    });
}

function vehiculeLabel(v: VehiculeOption): string {
    return `${v.nom_vehicule} — ${v.immatriculation}`;
}

// ── AutoComplete : Client ─────────────────────────────────────────────────────
const clientSelected = ref<ClientOption | null>(
    props.clients.find((c) => c.id === props.commande.client_id) ?? null,
);
const clientSuggests = ref<ClientOption[]>([]);

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

const selectedClientVehicules = computed(
    () => clientSelected.value?.vehicules ?? [],
);

function onClientSelect(c: ClientOption | null) {
    form.client_id = c?.id ?? null;
    form.client_vehicule_id = null;
    // Le type de client (externe) peut à lui seul faire basculer modeTarification
    // vers "prix_usine" (cf. useVehiculeCommandeTarification) — sans ce recalcul, le
    // total des lignes déjà saisies reste figé sur l'ancien mode (prix_vente) alors
    // que le prix unitaire affiché, lui, se met à jour immédiatement.
    recomputeAllTotals();
}

function onClientClear() {
    form.client_id = null;
    form.client_vehicule_id = null;
    clientSelected.value = null;
    recomputeAllTotals();
}

function clientLabel(c: ClientOption): string {
    return c.nom_complet;
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

const vehiculeSelectionne = computed(() => {
    if (form.vehicule_id === null) {
        return null;
    }

    return props.vehicules.find((v) => v.id === form.vehicule_id) ?? null;
});

// Plafonds par catégorie de produit du véhicule sélectionné — vide si aucune capacité n'est
// configurée pour ce véhicule (non plafonné), exactement comme VehiculeCapaciteService côté
// serveur (plus aucun héritage depuis le type).
const capacitesSelectionnees = computed(
    () => vehiculeSelectionne.value?.capacites ?? [],
);

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
    // Ajouter une ligne vide si la commande n'avait aucune ligne
    if (form.lignes.length === 0) {
        form.lignes.push({ produit_id: null, qte: 1, prix_vente: 0, total: 0 });
    }
    // Le total initial (calculé ci-dessus, avant que clientSelected n'existe) ignore encore
    // la tarification par nature de client — recalculé ici pour rester correct dès l'affichage.
    recomputeAllTotals();
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
function submit() {
    form.put(`/backoffice/ventes/${props.commande.id}`);
}
</script>

<template>
    <Head>
        <title>Modifier {{ commande.reference }}</title>
    </Head>

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- Mobile sticky header -->
        <div
            class="sticky top-0 z-20 border-b border-border/60 bg-background/95 backdrop-blur-sm sm:hidden"
        >
            <div class="relative flex items-center justify-center px-4 py-3">
                <Link
                    :href="`/backoffice/ventes/${commande.id}`"
                    class="absolute left-4 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground transition-transform active:scale-95"
                >
                    <ArrowLeft class="h-4 w-4" />
                </Link>
                <div class="text-center">
                    <h1 class="text-[17px] leading-tight font-semibold">
                        Modifier la commande
                    </h1>
                    <p class="font-mono text-[11px] text-muted-foreground">
                        {{ commande.reference }}
                    </p>
                </div>
            </div>
        </div>

        <div class="mx-auto max-w-5xl p-4 sm:p-6">
            <div class="mb-6 hidden sm:block">
                <h1 class="font-mono text-2xl font-bold tracking-wide">
                    {{ commande.reference }}
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Modifiez cette commande en brouillon. Les changements sont
                    enregistrés immédiatement.
                </p>
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
                            <Label
                                for="vente-edit-vehicule"
                                class="mb-1.5 block text-sm"
                                >Véhicule</Label
                            >
                            <AutoComplete
                                v-model="vehiculeSelected"
                                input-id="vente-edit-vehicule"
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
                                            >
                                                {{ option.livreur_nom }}
                                            </span>
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
                        </div>

                        <!-- Client -->
                        <div>
                            <Label
                                for="vente-edit-client"
                                class="mb-1.5 block text-sm"
                                >Client</Label
                            >
                            <AutoComplete
                                v-model="clientSelected"
                                input-id="vente-edit-client"
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

                            <!-- Véhicule externe : facultatif, jamais requis pour vendre -->
                            <div
                                v-if="
                                    clientSelected?.type === 'externe' &&
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
                        </div>
                    </div>

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

                    <template v-if="form.vehicule_id !== null">
                        <p
                            v-if="capacitesSelectionnees.length === 0"
                            class="mb-3 text-xs text-muted-foreground"
                        >
                            Véhicule non plafonné
                        </p>
                        <p
                            v-for="c in usageParCategorie"
                            :key="c.categorie_id"
                            class="mb-1 text-xs"
                            :class="capaciteLigneClass(c.qte, c.capacite_max)"
                        >
                            {{ c.categorie_nom }} : {{ c.qte }} /
                            {{ c.capacite_max }} packs
                            <span v-if="c.qte === c.capacite_max"
                                >— capacité atteinte ✓</span
                            >
                            <span v-else-if="c.qte < c.capacite_max">
                                — {{ c.capacite_max - c.qte }} pack(s)
                                manquant(s){{
                                    !autoriser_saisie_dessous_qte_max
                                        ? ' — chargement complet requis'
                                        : ''
                                }}</span
                            >
                            <span v-else>
                                — {{ c.qte - c.capacite_max }} pack(s) en
                                trop</span
                            >
                        </p>
                    </template>

                    <!-- Mode de tarification : véhicule non pris en charge par l'usine -->
                    <div
                        v-if="modeTarification === 'prix_usine'"
                        class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-300"
                    >
                        Véhicule non pris en charge par l'usine — le montant à
                        encaisser est calculé au <strong>prix usine</strong>
                        (et non au prix de vente affiché ci-dessous). La marge
                        reste à l'exploitant.
                    </div>

                    <!-- Éligibilité aux commissions : notion indépendante du mode
                         de tarification ci-dessus. -->
                    <div
                        v-if="form.vehicule_id !== null && !commissionEligible"
                        class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-300"
                    >
                        Ce véhicule n'est pas éligible aux commissions — aucune
                        commission ne sera générée pour cette commande.
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
                                        Qté
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
                                                v-if="!canUpdateUnitPrice"
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
                                            :disabled="
                                                !ligneUnitPriceEditable(ligne)
                                            "
                                            :use-grouping="false"
                                            suffix=" GNF"
                                            class="w-full"
                                            input-class="w-full text-right"
                                        />
                                        <p
                                            v-if="ligne.produit_id"
                                            class="mt-1 text-right text-[11px] text-muted-foreground"
                                        >
                                            {{ ligneOrigineLabel(ligne) }}
                                        </p>
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

                            <div class="mt-2.5 grid grid-cols-2 gap-2.5">
                                <div>
                                    <p
                                        class="mb-1 text-[11px] font-medium text-muted-foreground"
                                    >
                                        Quantité
                                    </p>
                                    <InputNumber
                                        :model-value="ligne.qte"
                                        @update:model-value="
                                            onQteChange(index, $event)
                                        "
                                        :min="1"
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
                                                v-if="!canUpdateUnitPrice"
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
                                        :disabled="
                                            !ligneUnitPriceEditable(ligne)
                                        "
                                        :use-grouping="false"
                                        class="w-full"
                                        input-class="w-full"
                                    />
                                    <p
                                        v-if="ligne.produit_id"
                                        class="mt-1 text-[11px] text-muted-foreground"
                                    >
                                        {{ ligneOrigineLabel(ligne) }}
                                    </p>
                                </div>
                            </div>

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
                    <Link :href="`/backoffice/ventes/${commande.id}`">
                        <Button type="button" variant="outline">Retour</Button>
                    </Link>
                    <Button type="submit" :disabled="!canSubmit">
                        {{
                            form.processing
                                ? 'Enregistrement…'
                                : 'Enregistrer les modifications'
                        }}
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
                {{
                    form.processing
                        ? 'Enregistrement…'
                        : 'Enregistrer les modifications'
                }}
            </Button>
        </div>
    </AppLayout>
</template>
