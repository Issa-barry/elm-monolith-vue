<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
    AlertTriangle,
    Image,
    Layers,
    Plus,
    Save,
    TrendingDown,
    TrendingUp,
    X,
} from 'lucide-vue-next';
import Dropdown from 'primevue/dropdown';
import Editor from 'primevue/editor';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import RadioButton from 'primevue/radiobutton';
import { computed, reactive, ref } from 'vue';
import CategorieSelect from './CategorieSelect.vue';
import FournisseurSelect, {
    type FournisseurOption,
} from './FournisseurSelect.vue';
import OptionCatalogueSelect, {
    type OptionCatalogue,
} from './OptionCatalogueSelect.vue';
import VariantesGroupees, {
    type Variante as VarianteGroupee,
} from './VariantesGroupees.vue';

// ── Props / Emits ─────────────────────────────────────────────────────────────
interface Option {
    value: string;
    label: string;
}

// `required_prices`/`gere_stock` (cf. ProduitController::typesOptions() côté backend, seule
// source de vérité) pilotent le "*" affiché sur les prix obligatoires et l'affichage de la
// section Stock pour le type sélectionné. `achetable`/`vendable` pilotent une notion distincte,
// l'applicabilité fonctionnelle (même champs que ceux qui filtrent les flux achat/vente) : un
// prix peut être applicable sans être obligatoire — ne jamais confondre les deux (cf. analyse
// tarification tricycle/applicabilité).
interface ProduitTypeOption extends Option {
    gere_stock: boolean;
    required_prices: string[];
    achetable: boolean;
    vendable: boolean;
    /** Repère technique stable (cf. ProduitType) — pilote la visibilité de la section
     * "Tarification clients", réservée au type dont le code vaut 'fabricable'. */
    code: string;
}

interface Categorie {
    id: string;
    nom: string;
    parent_id: string | null;
}

interface Limites {
    max_photos_produit: number;
    max_options_produit: number;
    max_valeurs_option: number;
    max_variantes_produit: number;
}

interface SiteOption {
    id: string;
    code: string;
    label: string;
}

interface OptionInput {
    nom: string;
    valeurs: string[];
    /** Rattachement au catalogue d'options réutilisables — cf. OptionCatalogueSelect.vue.
     * null pour une option historique créée avant l'existence du catalogue. */
    option_catalogue_id: string | null;
}

interface Variante {
    id: string;
    libelle: string;
    sku: string | null;
    code_barres: string | null;
    prix_usine: number | null;
    prix_usine_tricycle: number | null;
    prix_vente: number | null;
    prix_achat: number | null;
    cout: number | null;
    is_default: boolean;
    is_active: boolean;
    options: VarianteGroupee['options'];
}

interface FormData {
    nom: string;
    categorie_id: string | null;
    fournisseur_id: string | null;
    code_barres: string | null;
    produit_type_id: string | null;
    statut: string;
    prix_usine: number | null;
    prix_usine_tricycle: number | null;
    // Tarifs par nature de client — n'ont d'effet que pour un produit fabricable, cf.
    // isFabricable ci-dessous et PrixVenteNatureResolver côté serveur.
    prix_externe: number | null;
    prix_revendeur: number | null;
    prix_distributeur: number | null;
    prix_vente: number | null;
    prix_achat: number | null;
    cout: number | null;
    // Choix obligatoire, jamais de valeur implicite : l'utilisateur tranche explicitement
    // s'il veut être alerté en cas de stock faible.
    alerte_stock_active: boolean;
    // Seuil spécifique PAR SITE (absent d'un site = hérite du seuil par défaut de
    // l'organisation, cf. StockStatutService::seuilEffectifPourSite()) — remplace l'ancien
    // seuil unique produit. Toujours vide à la création (aucun site ne peut encore être
    // configuré tant que le produit n'a pas d'id) ; peuplé par Edit.vue à partir des sites
    // actifs de l'organisation.
    seuils_site: { site_id: string; seuil: number | null }[];
    description: string | null;
    // Tableau (même si un seul fichier ici) : le backend attend la clé "images[]"
    // (ProduitController::validerFormulaire()), partagée avec la galerie multi-photo.
    images: File[];
    options: OptionInput[];
}

const props = withDefaults(
    defineProps<{
        form: FormData;
        errors: Partial<Record<string, string>>;
        types: ProduitTypeOption[];
        statuts: Option[];
        categories?: Categorie[];
        fournisseurs?: FournisseurOption[];
        optionsCatalogue?: OptionCatalogue[];
        limites?: Limites;
        processing: boolean;
        currentImageUrl?: string | null;
        currentSku?: string | null;
        // Le builder d'options n'est proposé qu'à la création — ProduitService::mettreAJourSimple()
        // ne gère que le produit simple (variante par défaut), pas l'ajout de déclinaisons après coup.
        allowDeclinaisons?: boolean;
        existingVariantes?: Variante[];
        /** Seuil de stock faible par défaut de l'organisation (Paramètres) — utilisé par tout
         * site n'ayant pas de seuil spécifique ci-dessous. */
        seuilOrganisationDefaut?: number;
        /** Sites ACTIFS de l'organisation, pour la section "Alerte de stock faible" par
         * agence — absent/vide à la création (aucun produit id à rattacher), peuplé par
         * Edit.vue. */
        sites?: SiteOption[];
    }>(),
    {
        categories: () => [],
        fournisseurs: () => [],
        optionsCatalogue: () => [],
        limites: undefined,
        currentImageUrl: null,
        currentSku: null,
        allowDeclinaisons: false,
        existingVariantes: () => [],
        seuilOrganisationDefaut: 10,
        sites: () => [],
    },
);

const emit = defineEmits<{
    submit: [];
    'update:form': [FormData];
    'edit-variante': [Variante];
}>();

const selectedType = computed(() =>
    props.types.find((t) => t.value === props.form.produit_type_id),
);
const typeHasStock = computed(() => selectedType.value?.gere_stock ?? true);

// Champs de prix obligatoires pour le type sélectionné (cf. ProduitType::requiredPrices() —
// le backend reste la seule source de vérité, ce tableau vient directement de `types`).
const requiredPrices = computed(
    () => selectedType.value?.required_prices ?? [],
);
function prixRequis(champ: string): boolean {
    return requiredPrices.value.includes(champ);
}
// Applicabilité (visibilité du champ) — distincte de l'obligation ci-dessus. prix_achat/
// prix_vente suivent achetable/vendable (un type peut accepter un prix facultatif sans
// l'exiger) ; prix_usine/prix_usine_tricycle restent pilotés par leur obligation, faute de
// notion "applicable mais facultatif" pertinente pour ce prix dans ce domaine.
const prixAchatApplicable = computed(
    () => selectedType.value?.achetable ?? true,
);
const prixVenteApplicable = computed(
    () => selectedType.value?.vendable ?? true,
);

// Tarification par nature de client (Externe/Revendeur/Distributeur) — réservée aux produits
// fabricables (cf. ProduitService::nettoyerPrixNatureSiNonFabricable() côté serveur, seule
// source de vérité ; ce computed ne pilote que l'affichage).
const isFabricable = computed(() => selectedType.value?.code === 'fabricable');

// Rouge dès qu'un champ requis est vide ET que le formulaire a déjà été refusé une fois pour
// ce motif — le backend regroupe toutes les erreurs de prix sous la clé `produit_type_id` (un
// seul message listant les champs manquants), jamais sous le champ lui-même individuellement.
function prixInvalide(champ: string, valeur: number | null): boolean {
    return (
        !!props.errors.produit_type_id &&
        (prixRequis(champ) || prixNatureRequis(champ)) &&
        (valeur === null || valeur === undefined)
    );
}

// ── Résumé de rentabilité (aperçu live, purement informatif) ────────────────
// Le backend reste seul juge de ce qui est accepté (ProduitService::validerPrixSelonType) —
// ce bloc ne fait qu'anticiper visuellement la même règle pour éviter un aller-retour serveur
// inutile. Bénéfice/marge basés sur le coût de revient (jamais obligatoire, jamais bloquant) ;
// seuil de marge faible = 10 %, cohérent avec le seuil déjà évoqué pour le chantier marge
// (cf. mémoire "Prix produit & marge — spec cible").
const SEUIL_MARGE_FAIBLE_PCT = 10;

function formatMontant(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(Math.round(val));
}

const beneficeEstime = computed(() => {
    if (props.form.prix_vente === null || props.form.cout === null) return null;
    return props.form.prix_vente - props.form.cout;
});
const margeEstimeePct = computed(() => {
    if (beneficeEstime.value === null || !props.form.prix_vente) return null;
    return (beneficeEstime.value / props.form.prix_vente) * 100;
});
type EtatRentabilite = 'perte' | 'faible' | 'saine';
const etatRentabilite = computed<EtatRentabilite | null>(() => {
    if (margeEstimeePct.value === null) return null;
    if (margeEstimeePct.value <= 0) return 'perte';
    if (margeEstimeePct.value < SEUIL_MARGE_FAIBLE_PCT) return 'faible';
    return 'saine';
});

// Marge de commission par catégorie tarifaire — simple différence, purement indicative (le
// calcul réel/officiel reste CommissionCalculator côté serveur, sur les montants snapshotés).
const margeCommissionAutresVehicules = computed(() => {
    if (
        !prixRequis('prix_usine') ||
        props.form.prix_vente === null ||
        props.form.prix_usine === null
    )
        return null;
    return props.form.prix_vente - props.form.prix_usine;
});
const margeCommissionTricycle = computed(() => {
    if (
        !prixRequis('prix_usine') ||
        props.form.prix_vente === null ||
        props.form.prix_usine_tricycle === null
    )
        return null;
    return props.form.prix_vente - props.form.prix_usine_tricycle;
});

// ── Blocage bouton Enregistrer ───────────────────────────────────────────────
// Anticipe côté client exactement la règle bloquante déjà appliquée côté serveur
// (prix_vente doit être strictement supérieur à prix_usine ET prix_usine_tricycle quand
// requis) — le coût de revient, lui, ne bloque jamais (warning fort uniquement, cf.
// etatRentabilite === 'perte'), le métier peut vendre à perte volontairement (promotion,
// liquidation, produit d'appel...).
const margeUsineBloquante = computed(() => {
    if (!prixRequis('prix_usine') || props.form.prix_vente === null)
        return false;
    const depasseAutresVehicules =
        props.form.prix_usine !== null &&
        props.form.prix_vente <= props.form.prix_usine;
    const depasseTricycle =
        props.form.prix_usine_tricycle !== null &&
        props.form.prix_vente <= props.form.prix_usine_tricycle;

    return depasseAutresVehicules || depasseTricycle;
});

// Tarification par nature de client — obligatoire pour un produit fabricable (au même titre
// que prix_usine/prix_usine_tricycle/prix_vente), cf. ProduitService::raisonIncoherencePrix()
// côté serveur, seule source de vérité reproduite ici pour l'anticipation visuelle.
const CHAMPS_PRIX_NATURE = [
    'prix_externe',
    'prix_revendeur',
    'prix_distributeur',
] as const;

function prixNatureRequis(champ: string): boolean {
    return (
        isFabricable.value &&
        (CHAMPS_PRIX_NATURE as readonly string[]).includes(champ)
    );
}

const champsObligatoiresManquants = computed(() => {
    if (
        !props.form.nom?.trim() ||
        !props.form.produit_type_id ||
        !props.form.statut
    )
        return true;

    const champsPrixRequis = isFabricable.value
        ? [...requiredPrices.value, ...CHAMPS_PRIX_NATURE]
        : requiredPrices.value;

    return champsPrixRequis.some((champ) => {
        const valeur = (props.form as unknown as Record<string, unknown>)[
            champ
        ];

        return valeur === null || valeur === undefined || valeur === '';
    });
});

const canSubmit = computed(
    () => !champsObligatoiresManquants.value && !margeUsineBloquante.value,
);

defineExpose({ canSubmit });

// ── Alerte de stock faible (seuil par site) ─────────────────────────────────
function seuilPourSite(siteId: string): number | null {
    return (
        props.form.seuils_site.find((s) => s.site_id === siteId)?.seuil ?? null
    );
}

function setSeuilPourSite(siteId: string, valeur: number | null) {
    const existe = props.form.seuils_site.some((s) => s.site_id === siteId);
    const seuils = existe
        ? props.form.seuils_site.map((s) =>
              s.site_id === siteId ? { ...s, seuil: valeur } : s,
          )
        : [...props.form.seuils_site, { site_id: siteId, seuil: valeur }];
    emit('update:form', { ...props.form, seuils_site: seuils });
}

const previewUrl = ref<string | null>(null);

function onImageChange(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0] ?? null;
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
    previewUrl.value = file ? URL.createObjectURL(file) : null;
    emit('update:form', { ...props.form, images: file ? [file] : [] });
}

function removeImage() {
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
    previewUrl.value = null;
    emit('update:form', { ...props.form, images: [] });
}

const displayImage = computed(
    () => previewUrl.value ?? props.currentImageUrl ?? null,
);

// ── Déclinaisons (options/variantes) ────────────────────────────────────────
const hasDeclinaisons = computed({
    get: () => props.form.options.length > 0,
    set: (actif: boolean) => {
        emit('update:form', {
            ...props.form,
            options: actif
                ? [{ nom: '', valeurs: [], option_catalogue_id: null }]
                : [],
        });
    },
});

function addOption() {
    if (
        props.limites &&
        props.form.options.length >= props.limites.max_options_produit
    ) {
        return;
    }
    emit('update:form', {
        ...props.form,
        options: [
            ...props.form.options,
            { nom: '', valeurs: [], option_catalogue_id: null },
        ],
    });
}

function removeOption(index: number) {
    emit('update:form', {
        ...props.form,
        options: props.form.options.filter((_, i) => i !== index),
    });
}

function updateOption(index: number, patch: Partial<OptionInput>) {
    emit('update:form', {
        ...props.form,
        options: props.form.options.map((o, i) =>
            i === index ? { ...o, ...patch } : o,
        ),
    });
}

// Choix d'une option de catalogue pour la ligne : repart d'une sélection de valeurs
// vierge pour éviter de mélanger les valeurs d'une option précédemment choisie.
function onOptionCatalogueSelected(
    index: number,
    option: OptionCatalogue | null,
) {
    updateOption(index, {
        nom: option?.nom ?? '',
        option_catalogue_id: option?.id ?? null,
        valeurs: [],
    });
}

// Valeurs proposées à cocher pour une ligne : celles du catalogue + celles déjà
// sélectionnées sur cette ligne mais absentes du catalogue (valeur historique ou
// tout juste ajoutée via "+ Ajouter une valeur", pas encore rechargée depuis le serveur).
function valeursProposees(index: number): string[] {
    const option = props.form.options[index];
    const catalogue = props.optionsCatalogue.find(
        (o) => o.id === option.option_catalogue_id,
    );
    const proposees = catalogue?.valeurs.map((v) => v.valeur) ?? [];

    return [...new Set([...proposees, ...option.valeurs])];
}

// Pastille de couleur (aide visuelle uniquement) — cf. migration hex sur
// option_catalogue_valeurs. Retourne null si la valeur n'a pas de hex renseigné.
function hexPour(index: number, valeur: string): string | null {
    const option = props.form.options[index];
    const catalogue = props.optionsCatalogue.find(
        (o) => o.id === option.option_catalogue_id,
    );

    return catalogue?.valeurs.find((v) => v.valeur === valeur)?.hex ?? null;
}

function toggleValeur(index: number, valeur: string) {
    const option = props.form.options[index];
    const dejaCoche = option.valeurs.includes(valeur);

    if (dejaCoche) {
        updateOption(index, {
            valeurs: option.valeurs.filter((v) => v !== valeur),
        });
        return;
    }
    if (
        props.limites &&
        option.valeurs.length >= props.limites.max_valeurs_option
    ) {
        return;
    }
    updateOption(index, { valeurs: [...option.valeurs, valeur] });
}

const nouvellesValeurs = reactive<Record<number, string>>({});

function ajouterValeurLibre(index: number) {
    const valeur = (nouvellesValeurs[index] ?? '').trim();
    if (!valeur) return;

    const option = props.form.options[index];
    if (!option.valeurs.includes(valeur)) {
        updateOption(index, { valeurs: [...option.valeurs, valeur] });
    }
    nouvellesValeurs[index] = '';
}

const totalVariantes = computed(() =>
    props.form.options.length === 0
        ? 0
        : props.form.options.reduce(
              (acc, o) => acc * Math.max(o.valeurs.length, 1),
              1,
          ),
);

const optionsSummary = computed(() =>
    props.form.options
        .filter((o) => o.valeurs.length > 0)
        .map((o) => `${o.valeurs.length} ${o.nom || 'valeur(s)'}`)
        .join(' × '),
);

const depasseLimiteVariantes = computed(
    () =>
        !!props.limites &&
        totalVariantes.value > props.limites.max_variantes_produit,
);
</script>

<template>
    <form
        id="produit-form"
        class="space-y-4 sm:space-y-8"
        @submit.prevent="emit('submit')"
    >
        <!-- Section : Identification ──────────────────────────────────────── -->
        <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
            <h3
                class="mb-4 text-xs font-semibold tracking-wider text-muted-foreground uppercase sm:mb-5"
            >
                Identification
            </h3>

            <div class="grid gap-4 sm:grid-cols-2 sm:gap-5">
                <!-- Type -->
                <div>
                    <Label class="mb-1.5 block"
                        >Type <span class="text-destructive">*</span></Label
                    >
                    <Dropdown
                        :model-value="form.produit_type_id"
                        @update:model-value="
                            $emit('update:form', {
                                ...form,
                                produit_type_id: $event,
                            })
                        "
                        :options="types"
                        option-label="label"
                        option-value="value"
                        placeholder="Sélectionner un type"
                        class="w-full"
                        :class="{ 'p-invalid': errors.produit_type_id }"
                    />
                    <p
                        v-if="errors.produit_type_id"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errors.produit_type_id }}
                    </p>
                </div>

                <!-- Statut -->
                <div>
                    <Label class="mb-1.5 block"
                        >Statut <span class="text-destructive">*</span></Label
                    >
                    <Dropdown
                        :model-value="form.statut"
                        @update:model-value="
                            $emit('update:form', { ...form, statut: $event })
                        "
                        :options="statuts"
                        option-label="label"
                        option-value="value"
                        placeholder="Sélectionner un statut"
                        class="w-full"
                        :class="{ 'p-invalid': errors.statut }"
                    />
                    <p
                        v-if="errors.statut"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errors.statut }}
                    </p>
                </div>

                <!-- Nom -->
                <div class="sm:col-span-2">
                    <Label for="nom" class="mb-1.5 block"
                        >Nom du produit
                        <span class="text-destructive">*</span></Label
                    >
                    <InputText
                        id="nom"
                        :model-value="form.nom"
                        @update:model-value="
                            $emit('update:form', {
                                ...form,
                                nom: $event ?? '',
                            })
                        "
                        class="w-full"
                        :class="{ 'p-invalid': errors.nom }"
                    />
                    <p v-if="errors.nom" class="mt-1 text-xs text-destructive">
                        {{ errors.nom }}
                    </p>
                </div>

                <!-- Catégorie -->
                <div>
                    <Label class="mb-1.5 block">Catégorie</Label>
                    <CategorieSelect
                        :model-value="form.categorie_id"
                        @update:model-value="
                            $emit('update:form', {
                                ...form,
                                categorie_id: $event,
                            })
                        "
                        :categories="categories"
                        :invalid="!!errors.categorie_id"
                    />
                    <p
                        v-if="errors.categorie_id"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errors.categorie_id }}
                    </p>
                    <p v-else class="mt-1 text-xs text-muted-foreground">
                        Sert aussi de référence pour le contrôle de capacité
                        véhicule (Véhicules &gt; capacités par catégorie).
                    </p>
                </div>

                <!-- Fournisseur -->
                <div>
                    <Label class="mb-1.5 block">Fournisseur</Label>
                    <FournisseurSelect
                        :model-value="form.fournisseur_id"
                        @update:model-value="
                            $emit('update:form', {
                                ...form,
                                fournisseur_id: $event,
                            })
                        "
                        :fournisseurs="fournisseurs"
                        :invalid="!!errors.fournisseur_id"
                    />
                    <p
                        v-if="errors.fournisseur_id"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errors.fournisseur_id }}
                    </p>
                </div>

                <!-- Référence (sku, générée automatiquement, édition uniquement) -->
                <div v-if="currentSku">
                    <Label class="mb-1.5 block">Référence</Label>
                    <div
                        class="flex h-10 w-full items-center rounded-md border bg-muted/40 px-3 font-mono text-sm tracking-widest text-muted-foreground select-all"
                    >
                        {{ currentSku }}
                    </div>
                </div>

                <!-- Code-barres -->
                <div>
                    <Label for="code_barres" class="mb-1.5 block"
                        >Code-barres</Label
                    >
                    <InputText
                        id="code_barres"
                        :model-value="form.code_barres ?? ''"
                        @update:model-value="
                            $emit('update:form', {
                                ...form,
                                code_barres: $event || null,
                            })
                        "
                        class="w-full font-mono"
                        :class="{ 'p-invalid': errors.code_barres }"
                    />
                    <p
                        v-if="errors.code_barres"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errors.code_barres }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Section : Options & variantes ─────────────────────────────────── -->
        <div
            v-if="allowDeclinaisons"
            class="rounded-xl border bg-card p-4 shadow-sm sm:p-6"
        >
            <div class="mb-4 flex items-center justify-between sm:mb-5">
                <h3
                    class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    Options &amp; variantes
                </h3>
                <div class="flex items-center gap-2">
                    <Checkbox
                        id="has_declinaisons"
                        :model-value="hasDeclinaisons"
                        @update:model-value="hasDeclinaisons = $event === true"
                    />
                    <Label
                        for="has_declinaisons"
                        class="cursor-pointer text-sm font-medium"
                        >Ce produit a des variantes</Label
                    >
                </div>
            </div>

            <template v-if="!hasDeclinaisons">
                <p class="text-sm text-muted-foreground">
                    Produit simple, sans variante — couleur, taille, etc. Cochez
                    la case ci-dessus pour définir des options (ex : Couleur,
                    Taille) et générer les combinaisons automatiquement.
                </p>
            </template>

            <div v-else class="space-y-4">
                <div
                    v-for="(option, i) in form.options"
                    :key="i"
                    class="rounded-lg border bg-muted/20 p-3 sm:p-4"
                >
                    <div class="mb-3 flex items-center gap-2">
                        <div class="flex-1">
                            <OptionCatalogueSelect
                                :model-value="option.option_catalogue_id"
                                :options-catalogue="optionsCatalogue"
                                :invalid="!!errors[`options.${i}.nom`]"
                                @selected="onOptionCatalogueSelected(i, $event)"
                            />
                        </div>
                        <button
                            type="button"
                            @click="removeOption(i)"
                            class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive"
                            aria-label="Supprimer l'option"
                        >
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                    <p
                        v-if="errors[`options.${i}.nom`]"
                        class="mb-2 text-xs text-destructive"
                    >
                        {{ errors[`options.${i}.nom`] }}
                    </p>

                    <template v-if="option.option_catalogue_id">
                        <div class="flex flex-wrap gap-2">
                            <label
                                v-for="valeur in valeursProposees(i)"
                                :key="valeur"
                                class="flex cursor-pointer items-center gap-1.5 rounded-md border bg-background px-2.5 py-1.5 text-sm"
                                :class="{
                                    'border-primary bg-primary/5':
                                        option.valeurs.includes(valeur),
                                }"
                            >
                                <Checkbox
                                    :model-value="
                                        option.valeurs.includes(valeur)
                                    "
                                    @update:model-value="
                                        toggleValeur(i, valeur)
                                    "
                                />
                                <span
                                    v-if="hexPour(i, valeur)"
                                    class="h-3 w-3 shrink-0 rounded-full border"
                                    :style="{
                                        backgroundColor: hexPour(i, valeur)!,
                                    }"
                                />
                                {{ valeur }}
                            </label>
                        </div>

                        <div class="mt-2 flex items-center gap-2">
                            <InputText
                                v-model="nouvellesValeurs[i]"
                                placeholder="+ Ajouter une valeur"
                                class="h-8 max-w-[12rem] text-sm"
                                @keyup.enter="ajouterValeurLibre(i)"
                            />
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                @click="ajouterValeurLibre(i)"
                            >
                                Ajouter
                            </Button>
                        </div>

                        <p class="mt-2 text-xs text-muted-foreground">
                            {{ option.valeurs.length }}
                            <template v-if="limites">
                                / {{ limites.max_valeurs_option }}</template
                            >
                            valeur(s) sélectionnée(s)
                        </p>
                        <p
                            v-if="errors[`options.${i}.valeurs`]"
                            class="text-xs text-destructive"
                        >
                            {{ errors[`options.${i}.valeurs`] }}
                        </p>
                    </template>
                    <p v-else class="text-xs text-muted-foreground">
                        Choisissez une option ci-dessus pour proposer ses
                        valeurs (ex : Noir, Blanc, Rouge…).
                    </p>
                </div>

                <Button
                    v-if="
                        !limites ||
                        form.options.length < limites.max_options_produit
                    "
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="addOption"
                >
                    <Plus class="mr-1.5 h-4 w-4" />
                    Ajouter une option
                </Button>

                <div
                    class="flex items-start gap-2 rounded-lg bg-muted/40 p-3 text-sm"
                    :class="{
                        'bg-destructive/10 text-destructive':
                            depasseLimiteVariantes,
                    }"
                >
                    <Layers class="mt-0.5 h-4 w-4 shrink-0" />
                    <span v-if="totalVariantes > 0">
                        {{ optionsSummary }} =
                        <strong>{{ totalVariantes }} variante(s)</strong>
                        seront générées à l'enregistrement.
                        <template v-if="depasseLimiteVariantes">
                            Cela dépasse la limite de
                            {{ limites?.max_variantes_produit }} variantes
                            autorisée pour votre organisation.
                        </template>
                    </span>
                    <span v-else class="text-muted-foreground">
                        Ajoutez au moins une valeur par option pour générer les
                        variantes.
                    </span>
                </div>
                <p v-if="errors.options" class="text-xs text-destructive">
                    {{ errors.options }}
                </p>
            </div>
        </div>

        <!-- Variantes existantes (édition d'un produit déjà décliné) ───────── -->
        <div
            v-else-if="existingVariantes.length > 1"
            class="rounded-xl border bg-card p-4 shadow-sm sm:p-6"
        >
            <h3
                class="mb-1 flex items-center gap-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase"
            >
                <Layers class="h-4 w-4" />
                Variantes ({{ existingVariantes.length }})
            </h3>
            <p class="mb-4 text-xs text-muted-foreground">
                Prix et statut se gèrent individuellement par variante — les
                champs de tarification ci-dessous ne s'appliquent qu'à la
                variante par défaut.
            </p>
            <VariantesGroupees
                :variantes="existingVariantes"
                @edit-variante="
                    (v) =>
                        emit(
                            'edit-variante',
                            existingVariantes.find((ev) => ev.id === v.id)!,
                        )
                "
            />
        </div>

        <!-- Section : Tarification ───────────────────────────────────────── -->
        <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
            <h3
                class="mb-4 text-xs font-semibold tracking-wider text-muted-foreground uppercase sm:mb-5"
            >
                Tarification
                <span class="text-xs font-normal normal-case">(GNF)</span>
            </h3>
            <p
                v-if="hasDeclinaisons"
                class="-mt-2 mb-4 text-xs text-muted-foreground"
            >
                Appliqué aux {{ totalVariantes || '' }} variantes générées —
                ajustable ensuite individuellement.
            </p>

            <!--
                Grille fluide auto-fit : le navigateur détermine seul le nombre de colonnes
                selon la largeur réellement disponible (jamais de nombre de colonnes codé en
                dur/calculé en JS) — chaque champ garde une largeur confortable (min 15rem) et
                passe naturellement à la ligne suivante sans laisser de trou quand un champ
                conditionnel (prix_achat/prix_vente) est absent. L'ordre du DOM ci-dessous EST
                l'ordre visuel (pas de `order-*`) : Coût de revient reste toujours en dernier
                simplement parce qu'il est écrit en dernier.
            -->
            <div
                class="grid grid-cols-[repeat(auto-fit,minmax(15rem,1fr))] gap-4 sm:gap-5"
            >
                <div v-if="prixRequis('prix_usine')">
                    <Label for="prix_usine" class="mb-1.5 block"
                        >Prix usine — Tous véhicules
                        <span
                            v-if="prixRequis('prix_usine')"
                            class="text-destructive"
                            >*</span
                        ></Label
                    >
                    <InputNumber
                        input-id="prix_usine"
                        :model-value="form.prix_usine"
                        @update:model-value="
                            $emit('update:form', {
                                ...form,
                                prix_usine: $event,
                            })
                        "
                        :min="0"
                        :use-grouping="true"
                        locale="fr-GN"
                        class="w-full"
                        input-class="w-full"
                        :class="{
                            'p-invalid': prixInvalide(
                                'prix_usine',
                                form.prix_usine,
                            ),
                        }"
                    />
                    <p
                        v-if="errors.prix_usine"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errors.prix_usine }}
                    </p>
                </div>

                <div v-if="prixRequis('prix_usine')">
                    <Label for="prix_usine_tricycle" class="mb-1.5 block"
                        >Prix usine — Tricycle
                        <span class="text-destructive">*</span></Label
                    >
                    <InputNumber
                        input-id="prix_usine_tricycle"
                        :model-value="form.prix_usine_tricycle"
                        @update:model-value="
                            $emit('update:form', {
                                ...form,
                                prix_usine_tricycle: $event,
                            })
                        "
                        :min="0"
                        :use-grouping="true"
                        locale="fr-GN"
                        class="w-full"
                        input-class="w-full"
                        :class="{
                            'p-invalid': prixInvalide(
                                'prix_usine_tricycle',
                                form.prix_usine_tricycle,
                            ),
                        }"
                    />
                    <p
                        v-if="errors.prix_usine_tricycle"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errors.prix_usine_tricycle }}
                    </p>
                </div>

                <div v-if="prixVenteApplicable">
                    <Label for="prix_vente" class="mb-1.5 block"
                        >Prix vente
                        <span
                            v-if="prixRequis('prix_vente')"
                            class="text-destructive"
                            >*</span
                        ></Label
                    >
                    <InputNumber
                        input-id="prix_vente"
                        :model-value="form.prix_vente"
                        @update:model-value="
                            $emit('update:form', {
                                ...form,
                                prix_vente: $event,
                            })
                        "
                        :min="0"
                        :use-grouping="true"
                        locale="fr-GN"
                        class="w-full"
                        input-class="w-full"
                        :class="{
                            'p-invalid': prixInvalide(
                                'prix_vente',
                                form.prix_vente,
                            ),
                        }"
                    />
                    <p
                        v-if="errors.prix_vente"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errors.prix_vente }}
                    </p>
                </div>

                <div v-if="prixAchatApplicable">
                    <Label for="prix_achat" class="mb-1.5 block"
                        >Prix achat
                        <span
                            v-if="prixRequis('prix_achat')"
                            class="text-destructive"
                            >*</span
                        ></Label
                    >
                    <InputNumber
                        input-id="prix_achat"
                        :model-value="form.prix_achat"
                        @update:model-value="
                            $emit('update:form', {
                                ...form,
                                prix_achat: $event,
                            })
                        "
                        :min="0"
                        :use-grouping="true"
                        locale="fr-GN"
                        class="w-full"
                        input-class="w-full"
                        :class="{
                            'p-invalid': prixInvalide(
                                'prix_achat',
                                form.prix_achat,
                            ),
                        }"
                    />
                    <p
                        v-if="errors.prix_achat"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errors.prix_achat }}
                    </p>
                </div>

                <div>
                    <Label for="cout" class="mb-1.5 block"
                        >Coût de revient</Label
                    >
                    <InputNumber
                        input-id="cout"
                        :model-value="form.cout"
                        @update:model-value="
                            $emit('update:form', { ...form, cout: $event })
                        "
                        :min="0"
                        :use-grouping="true"
                        locale="fr-GN"
                        class="w-full"
                        input-class="w-full"
                    />
                </div>
            </div>

            <!-- Tarification par nature de client — fabricable uniquement ─────────── -->
            <div v-if="isFabricable" class="mt-4 border-t pt-4 sm:mt-5 sm:pt-5">
                <h4
                    class="mb-1 text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    Tarification clients
                </h4>
                <p class="mb-3 text-xs text-muted-foreground">
                    Remplace le prix de vente selon la nature du client.
                </p>
                <div
                    class="grid grid-cols-[repeat(auto-fit,minmax(15rem,1fr))] gap-4 sm:gap-5"
                >
                    <div>
                        <Label for="prix_externe" class="mb-1.5 block"
                            >Prix externe
                            <span class="text-destructive">*</span></Label
                        >
                        <InputNumber
                            input-id="prix_externe"
                            :model-value="form.prix_externe"
                            @update:model-value="
                                $emit('update:form', {
                                    ...form,
                                    prix_externe: $event,
                                })
                            "
                            :min="0"
                            :use-grouping="true"
                            locale="fr-GN"
                            class="w-full"
                            input-class="w-full"
                            :class="{
                                'p-invalid': prixInvalide(
                                    'prix_externe',
                                    form.prix_externe,
                                ),
                            }"
                        />
                        <p
                            v-if="errors.prix_externe"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ errors.prix_externe }}
                        </p>
                    </div>

                    <div>
                        <Label for="prix_distributeur" class="mb-1.5 block"
                            >Prix distributeur
                            <span class="text-destructive">*</span></Label
                        >
                        <InputNumber
                            input-id="prix_distributeur"
                            :model-value="form.prix_distributeur"
                            @update:model-value="
                                $emit('update:form', {
                                    ...form,
                                    prix_distributeur: $event,
                                })
                            "
                            :min="0"
                            :use-grouping="true"
                            locale="fr-GN"
                            class="w-full"
                            input-class="w-full"
                            :class="{
                                'p-invalid': prixInvalide(
                                    'prix_distributeur',
                                    form.prix_distributeur,
                                ),
                            }"
                        />
                        <p
                            v-if="errors.prix_distributeur"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ errors.prix_distributeur }}
                        </p>
                    </div>

                    <div>
                        <Label for="prix_revendeur" class="mb-1.5 block"
                            >Prix revendeur
                            <span class="text-destructive">*</span></Label
                        >
                        <InputNumber
                            input-id="prix_revendeur"
                            :model-value="form.prix_revendeur"
                            @update:model-value="
                                $emit('update:form', {
                                    ...form,
                                    prix_revendeur: $event,
                                })
                            "
                            :min="0"
                            :use-grouping="true"
                            locale="fr-GN"
                            class="w-full"
                            input-class="w-full"
                            :class="{
                                'p-invalid': prixInvalide(
                                    'prix_revendeur',
                                    form.prix_revendeur,
                                ),
                            }"
                        />
                        <p
                            v-if="errors.prix_revendeur"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ errors.prix_revendeur }}
                        </p>
                    </div>
                </div>
            </div>

            <!--
                Résumé de rentabilité — aperçu live, informatif uniquement (le backend reste
                seul juge à l'enregistrement). Petit bloc discret sous la grille, pas une
                carte séparée.
            -->
            <div
                v-if="
                    etatRentabilite !== null ||
                    margeCommissionAutresVehicules !== null ||
                    margeCommissionTricycle !== null
                "
                class="mt-4 space-y-1.5 border-t pt-4 text-xs sm:mt-5 sm:pt-5"
            >
                <div
                    v-if="etatRentabilite !== null"
                    class="flex items-center gap-1.5 font-medium"
                    :class="{
                        'text-destructive': etatRentabilite === 'perte',
                        'text-amber-600': etatRentabilite === 'faible',
                        'text-emerald-600': etatRentabilite === 'saine',
                    }"
                >
                    <TrendingDown
                        v-if="etatRentabilite === 'perte'"
                        class="h-3.5 w-3.5 shrink-0"
                    />
                    <AlertTriangle
                        v-else-if="etatRentabilite === 'faible'"
                        class="h-3.5 w-3.5 shrink-0"
                    />
                    <TrendingUp v-else class="h-3.5 w-3.5 shrink-0" />

                    <span v-if="etatRentabilite === 'perte'">
                        Perte estimée :
                        {{ formatMontant(Math.abs(beneficeEstime!)) }} GNF /
                        unité
                    </span>
                    <span v-else-if="etatRentabilite === 'faible'">
                        Marge faible : {{ formatMontant(beneficeEstime!) }} GNF
                        ({{ Math.round(margeEstimeePct!) }} %)
                    </span>
                    <span v-else>
                        Bénéfice estimé : +{{
                            formatMontant(beneficeEstime!)
                        }}
                        GNF / unité — Marge {{ Math.round(margeEstimeePct!) }}
                        %
                    </span>
                </div>

                <div
                    v-if="
                        margeCommissionAutresVehicules !== null ||
                        margeCommissionTricycle !== null
                    "
                    class="flex flex-wrap gap-x-3 gap-y-1 text-muted-foreground"
                >
                    <span>Marge commission —</span>
                    <span v-if="margeCommissionAutresVehicules !== null">
                        Autres véhicules :
                        {{ formatMontant(margeCommissionAutresVehicules) }}
                        GNF
                    </span>
                    <span v-if="margeCommissionTricycle !== null">
                        Tricycle :
                        {{ formatMontant(margeCommissionTricycle) }} GNF
                    </span>
                </div>
            </div>
        </div>

        <!-- Section : Stock ───────────────────────────────────────────────── -->
        <div
            v-if="typeHasStock"
            class="rounded-xl border bg-card p-4 shadow-sm sm:p-6"
        >
            <h3
                class="mb-4 text-xs font-semibold tracking-wider text-muted-foreground uppercase sm:mb-5"
            >
                Alerte de stock faible
            </h3>

            <!-- L'état Disponible/Stock faible/Rupture est TOUJOURS calculé automatiquement
                 (jamais saisi) — ce bloc ne configure que : voulez-vous être alerté, et à
                 partir de quel seuil. -->
            <div class="space-y-4">
                <div>
                    <Label class="mb-2 block"
                        >Souhaitez-vous être alerté lorsque le stock devient
                        faible ? <span class="text-destructive">*</span></Label
                    >
                    <div class="flex flex-wrap gap-4 sm:gap-6">
                        <label class="flex cursor-pointer items-center gap-2">
                            <RadioButton
                                :model-value="form.alerte_stock_active"
                                :value="true"
                                @update:model-value="
                                    $emit('update:form', {
                                        ...form,
                                        alerte_stock_active: true,
                                    })
                                "
                            />
                            <span class="text-sm">Oui</span>
                        </label>
                        <label class="flex cursor-pointer items-center gap-2">
                            <RadioButton
                                :model-value="form.alerte_stock_active"
                                :value="false"
                                @update:model-value="
                                    $emit('update:form', {
                                        ...form,
                                        alerte_stock_active: false,
                                        seuils_site: [],
                                    })
                                "
                            />
                            <span class="text-sm">Non</span>
                        </label>
                    </div>
                    <p
                        v-if="errors.alerte_stock_active"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errors.alerte_stock_active }}
                    </p>
                </div>

                <div
                    v-if="form.alerte_stock_active"
                    class="space-y-3 border-t pt-4"
                >
                    <Label class="block">Seuil d'alerte par agence</Label>

                    <template v-if="sites.length > 0">
                        <div class="divide-y rounded-lg border">
                            <div
                                v-for="site in sites"
                                :key="site.id"
                                class="flex items-center justify-between gap-3 px-3 py-2"
                            >
                                <span class="text-sm font-medium">{{
                                    site.label
                                }}</span>
                                <div class="flex items-center gap-2">
                                    <InputNumber
                                        :model-value="seuilPourSite(site.id)"
                                        @update:model-value="
                                            setSeuilPourSite(site.id, $event)
                                        "
                                        :min="1"
                                        :placeholder="
                                            String(seuilOrganisationDefaut)
                                        "
                                        class="w-28"
                                        input-class="w-full text-right"
                                    />
                                    <span
                                        v-if="seuilPourSite(site.id) === null"
                                        class="w-24 text-[11px] text-muted-foreground"
                                    >
                                        Défaut : {{ seuilOrganisationDefaut }}
                                    </span>
                                </div>
                            </div>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Chaque agence peut avoir son propre seuil.
                            Lorsqu'aucun seuil spécifique n'est renseigné, le
                            seuil par défaut de l'organisation est utilisé.
                        </p>
                    </template>
                    <p v-else class="text-xs text-muted-foreground">
                        Le seuil par défaut de l'organisation ({{
                            seuilOrganisationDefaut
                        }}
                        unités) s'appliquera à toutes les agences. Vous pourrez
                        définir un seuil spécifique par agence après la création
                        du produit.
                    </p>
                </div>
            </div>
        </div>

        <!-- Section : Image ──────────────────────────────────────────────── -->
        <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
            <h3
                class="mb-4 text-xs font-semibold tracking-wider text-muted-foreground uppercase sm:mb-5"
            >
                Image du produit
            </h3>

            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-start sm:gap-6"
            >
                <!-- Prévisualisation -->
                <div
                    class="flex h-28 w-28 shrink-0 items-center justify-center self-center overflow-hidden rounded-xl border-2 border-dashed bg-muted/40 sm:h-36 sm:w-36 sm:self-start"
                >
                    <img
                        v-if="displayImage"
                        :src="displayImage"
                        alt="Aperçu"
                        class="h-full w-full object-cover"
                    />
                    <Image v-else class="h-10 w-10 text-muted-foreground/40" />
                </div>

                <!-- Actions -->
                <div class="flex flex-col gap-3">
                    <Label class="block text-sm text-muted-foreground">
                        Formats acceptés : JPG, PNG, WEBP — max 2 Mo
                    </Label>
                    <div class="flex flex-wrap items-center gap-2">
                        <label class="cursor-pointer">
                            <input
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                class="sr-only"
                                @change="onImageChange"
                            />
                            <span
                                class="inline-flex items-center gap-1.5 rounded-md border bg-background px-3 py-2 text-sm font-medium shadow-sm transition-colors hover:bg-muted"
                            >
                                <Image class="h-4 w-4" />
                                Choisir une image
                            </span>
                        </label>
                        <button
                            v-if="displayImage"
                            type="button"
                            @click="removeImage"
                            class="inline-flex items-center gap-1.5 rounded-md px-3 py-2 text-sm text-destructive transition-colors hover:bg-destructive/10"
                        >
                            <X class="h-4 w-4" />
                            Supprimer
                        </button>
                    </div>
                    <p v-if="errors.image" class="text-xs text-destructive">
                        {{ errors.image }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Section : Description ────────────────────────────────────────── -->
        <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
            <h3
                class="mb-4 text-xs font-semibold tracking-wider text-muted-foreground uppercase sm:mb-5"
            >
                Description
            </h3>
            <Editor
                :model-value="form.description ?? ''"
                @update:model-value="
                    $emit('update:form', {
                        ...form,
                        description: $event || null,
                    })
                "
                editor-style="min-height: 140px"
                class="w-full"
            >
                <template #toolbar>
                    <span class="ql-formats">
                        <button class="ql-bold" /><button
                            class="ql-italic"
                        /><button class="ql-underline" />
                    </span>
                    <span class="ql-formats">
                        <button class="ql-list" value="bullet" /><button
                            class="ql-list"
                            value="ordered"
                        />
                    </span>
                    <span class="ql-formats">
                        <button class="ql-link" /><button class="ql-clean" />
                    </span>
                </template>
            </Editor>
        </div>

        <!-- Pied de formulaire (desktop uniquement) ─────────────────────── -->
        <div class="hidden items-center justify-between sm:flex">
            <a href="/backoffice/produits">
                <Button type="button" variant="outline"> Retour </Button>
            </a>
            <Button type="submit" :disabled="processing || !canSubmit">
                <Save class="mr-2 h-4 w-4" />
                {{ processing ? 'Enregistrement…' : 'Enregistrer' }}
            </Button>
        </div>

        <!-- Espace pour le footer sticky mobile ─────────────────────────── -->
        <div class="h-20 sm:hidden" />
    </form>
</template>
