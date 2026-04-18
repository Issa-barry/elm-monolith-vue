<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/composables/usePermissions';
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
    prix_vente: number;
    prix_usine: number;
}

interface VehiculeOption {
    id: number;
    nom_vehicule: string;
    immatriculation: string;
    capacite_packs: number | null;
    livreur_nom: string | null;
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
}

function onVehiculeClear() {
    form.vehicule_id = null;
    vehiculeSelected.value = null;
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

function onClientSelect(c: ClientOption | null) {
    form.client_id = c?.id ?? null;
}

function onClientClear() {
    form.client_id = null;
    clientSelected.value = null;
}

function clientLabel(c: ClientOption): string {
    return [c.prenom, c.nom].filter(Boolean).join(' ');
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
    if (form.vehicule_id === null) {
        return true;
    }

    if (capaciteVehiculeSelectionne.value === null) {
        return false;
    }

    return quantiteTotale.value === capaciteVehiculeSelectionne.value;
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
function submit() {
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
                <p class="mt-1 text-sm text-muted-foreground">
                    Créez une commande et sa facture sera générée
                    automatiquement.
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
                                ? 'text-emerald-600 dark:text-emerald-400'
                                : 'text-amber-600 dark:text-amber-400'
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
                            <span v-if="capaciteVehiculeConforme"> — capacité atteinte ✓</span>
                            <span v-else-if="quantiteTotale < capaciteVehiculeSelectionne"> — {{ capaciteVehiculeSelectionne - quantiteTotale }} pack(s) manquant(s)</span>
                            <span v-else> — {{ quantiteTotale - capaciteVehiculeSelectionne }} pack(s) en trop</span>
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
                        {{
                            form.processing
                                ? 'Enregistrement…'
                                : 'Enregistrer la commande'
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
                    form.processing ? 'Enregistrement…' : 'Enregistrer la vente'
                }}
            </Button>
        </div>
    </AppLayout>
</template>
