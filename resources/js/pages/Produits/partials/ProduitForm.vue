<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Image, Layers, Plus, Save, X } from 'lucide-vue-next';
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

// `required_prices`/`gere_stock` (cf. ProduitTypeController::typesOptions() côté backend,
// seule source de vérité, remplace l'ancien enum figé) pilotent le "*" affiché sur les prix
// obligatoires et l'affichage de la section Stock pour le type sélectionné.
interface ProduitTypeOption extends Option {
    gere_stock: boolean;
    required_prices: string[];
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
    prix_vente: number | null;
    prix_achat: number | null;
    cout: number | null;
    // Choix obligatoire, jamais de valeur implicite : l'utilisateur tranche explicitement
    // s'il veut être alerté en cas de stock faible.
    alerte_stock_active: boolean;
    // null = hérite du seuil par défaut de l'organisation ; valeur = seuil spécifique à ce
    // produit, appliqué à toutes ses variantes/sites (cf. StockStatutService).
    seuil_alerte_stock: number | null;
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
        /** Seuil de stock faible par défaut de l'organisation (Paramètres) — affiché comme
         * repère dans le choix "seuil par défaut / seuil spécifique" ci-dessous. */
        seuilOrganisationDefaut?: number;
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
// Rouge dès qu'un champ requis est vide ET que le formulaire a déjà été refusé une fois pour
// ce motif — le backend regroupe toutes les erreurs de prix sous la clé `produit_type_id` (un
// seul message listant les champs manquants), jamais sous le champ lui-même individuellement.
function prixInvalide(champ: string, valeur: number | null): boolean {
    return (
        !!props.errors.produit_type_id &&
        prixRequis(champ) &&
        (valeur === null || valeur === undefined)
    );
}

// ── Alerte de stock faible ──────────────────────────────────────────────────
const seuilSpecifique = computed({
    get: () => props.form.seuil_alerte_stock !== null,
    set: (actif: boolean) => {
        emit('update:form', {
            ...props.form,
            seuil_alerte_stock: actif
                ? (props.form.seuil_alerte_stock ??
                  props.seuilOrganisationDefaut)
                : null,
        });
    },
});

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

            <div
                class="grid gap-4 sm:grid-cols-2 sm:gap-5"
                :class="
                    prixRequis('prix_usine')
                        ? 'lg:grid-cols-4'
                        : 'lg:grid-cols-3'
                "
            >
                <div v-if="prixRequis('prix_usine')">
                    <Label for="prix_usine" class="mb-1.5 block"
                        >Prix usine
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

                <div>
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
                                        seuil_alerte_stock: null,
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
                    class="space-y-2 border-t pt-4"
                >
                    <Label class="block">Seuil d'alerte</Label>
                    <label class="flex cursor-pointer items-center gap-2">
                        <RadioButton
                            :model-value="seuilSpecifique"
                            :value="false"
                            @update:model-value="seuilSpecifique = false"
                        />
                        <span class="text-sm"
                            >Utiliser le seuil par défaut de l'organisation :
                            {{ seuilOrganisationDefaut }} unités</span
                        >
                    </label>
                    <label class="flex cursor-pointer items-center gap-2">
                        <RadioButton
                            :model-value="seuilSpecifique"
                            :value="true"
                            @update:model-value="seuilSpecifique = true"
                        />
                        <span class="text-sm"
                            >Définir un seuil spécifique :</span
                        >
                        <InputNumber
                            v-if="seuilSpecifique"
                            :model-value="form.seuil_alerte_stock"
                            @update:model-value="
                                $emit('update:form', {
                                    ...form,
                                    seuil_alerte_stock: $event,
                                })
                            "
                            :min="1"
                            class="w-32"
                            input-class="w-full"
                        />
                    </label>
                    <p class="text-xs text-muted-foreground">
                        Le stock sera signalé comme faible lorsqu'une variante
                        atteindra
                        {{
                            seuilSpecifique
                                ? (form.seuil_alerte_stock ??
                                  seuilOrganisationDefaut)
                                : seuilOrganisationDefaut
                        }}
                        unités ou moins, sur un site donné — ce seuil s'applique
                        à toutes les variantes de ce produit.
                    </p>
                    <p
                        v-if="errors.seuil_alerte_stock"
                        class="text-xs text-destructive"
                    >
                        {{ errors.seuil_alerte_stock }}
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
            <Button type="submit" :disabled="processing">
                <Save class="mr-2 h-4 w-4" />
                {{ processing ? 'Enregistrement…' : 'Enregistrer' }}
            </Button>
        </div>

        <!-- Espace pour le footer sticky mobile ─────────────────────────── -->
        <div class="h-20 sm:hidden" />
    </form>
</template>
