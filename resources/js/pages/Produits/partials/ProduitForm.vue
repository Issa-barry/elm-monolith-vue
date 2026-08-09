<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Image, Layers, Plus, Save, X } from 'lucide-vue-next';
import Chips from 'primevue/chips';
import Dropdown from 'primevue/dropdown';
import Editor from 'primevue/editor';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import { computed, ref } from 'vue';

// ── Props / Emits ─────────────────────────────────────────────────────────────
interface Option {
    value: string;
    label: string;
}

interface Categorie {
    id: number | string;
    nom: string;
    parent_id: number | string | null;
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
}

interface FormData {
    nom: string;
    categorie_id: number | string | null;
    code_fournisseur: string | null;
    type: string;
    statut: string;
    prix_usine: number | null;
    prix_vente: number | null;
    prix_achat: number | null;
    cout: number | null;
    seuil_alerte_stock: number | null;
    description: string | null;
    is_alerte: boolean;
    image: File | null;
    options: OptionInput[];
}

const props = withDefaults(
    defineProps<{
        form: FormData;
        errors: Partial<Record<string, string>>;
        types: Option[];
        statuts: Option[];
        categories?: Categorie[];
        limites?: Limites;
        processing: boolean;
        currentImageUrl?: string | null;
        currentSku?: string | null;
        // Le builder d'options n'est proposé qu'à la création — ProduitService::mettreAJourSimple()
        // ne gère que le produit simple (variante par défaut), pas l'ajout de déclinaisons après coup.
        allowDeclinaisons?: boolean;
        existingVariantesCount?: number;
    }>(),
    {
        categories: () => [],
        limites: undefined,
        currentImageUrl: null,
        currentSku: null,
        allowDeclinaisons: false,
        existingVariantesCount: 0,
    },
);

const emit = defineEmits<{
    submit: [];
    'update:form': [FormData];
}>();

const typeHasStock = computed(() => !['service'].includes(props.form.type));
const isFabricable = computed(() => props.form.type === 'fabricable');

const previewUrl = ref<string | null>(null);

function onImageChange(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0] ?? null;
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
    previewUrl.value = file ? URL.createObjectURL(file) : null;
    emit('update:form', { ...props.form, image: file });
}

function removeImage() {
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
    previewUrl.value = null;
    emit('update:form', { ...props.form, image: null });
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
            options: actif ? [{ nom: '', valeurs: [] }] : [],
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
        options: [...props.form.options, { nom: '', valeurs: [] }],
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
                        :model-value="form.type"
                        @update:model-value="
                            $emit('update:form', { ...form, type: $event })
                        "
                        :options="types"
                        option-label="label"
                        option-value="value"
                        placeholder="Sélectionner un type"
                        class="w-full"
                        :class="{ 'p-invalid': errors.type }"
                    />
                    <p v-if="errors.type" class="mt-1 text-xs text-destructive">
                        {{ errors.type }}
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
                    <Dropdown
                        :model-value="form.categorie_id"
                        @update:model-value="
                            $emit('update:form', {
                                ...form,
                                categorie_id: $event,
                            })
                        "
                        :options="categories"
                        option-label="nom"
                        option-value="id"
                        show-clear
                        placeholder="Aucune catégorie"
                        class="w-full"
                        :class="{ 'p-invalid': errors.categorie_id }"
                    />
                    <p
                        v-if="errors.categorie_id"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errors.categorie_id }}
                    </p>
                </div>

                <!-- SKU (généré automatiquement, édition uniquement) -->
                <div v-if="currentSku">
                    <Label class="mb-1.5 block">SKU</Label>
                    <div
                        class="flex h-10 w-full items-center rounded-md border bg-muted/40 px-3 font-mono text-sm tracking-widest text-muted-foreground select-all"
                    >
                        {{ currentSku }}
                    </div>
                </div>

                <!-- Code fournisseur -->
                <div>
                    <Label for="code_fournisseur" class="mb-1.5 block"
                        >Code fournisseur</Label
                    >
                    <InputText
                        id="code_fournisseur"
                        :model-value="form.code_fournisseur ?? ''"
                        @update:model-value="
                            $emit('update:form', {
                                ...form,
                                code_fournisseur: $event || null,
                            })
                        "
                        class="w-full font-mono"
                    />
                </div>
            </div>
        </div>

        <!-- Section : Déclinaisons (options/variantes) ─────────────────────── -->
        <div
            v-if="allowDeclinaisons"
            class="rounded-xl border bg-card p-4 shadow-sm sm:p-6"
        >
            <div class="mb-4 flex items-center justify-between sm:mb-5">
                <h3
                    class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    Déclinaisons
                </h3>
                <div class="flex items-center gap-2">
                    <Checkbox
                        id="has_declinaisons"
                        :model-value="hasDeclinaisons"
                        @update:model-value="
                            hasDeclinaisons = $event === true
                        "
                    />
                    <Label
                        for="has_declinaisons"
                        class="cursor-pointer text-sm font-medium"
                        >Ce produit a des déclinaisons</Label
                    >
                </div>
            </div>

            <template v-if="!hasDeclinaisons">
                <p class="text-sm text-muted-foreground">
                    Produit simple, sans déclinaison — couleur, taille, etc.
                    Cochez la case ci-dessus pour définir des options (ex :
                    Couleur, Taille) et générer les combinaisons
                    automatiquement.
                </p>
            </template>

            <div v-else class="space-y-4">
                <div
                    v-for="(option, i) in form.options"
                    :key="i"
                    class="rounded-lg border bg-muted/20 p-3 sm:p-4"
                >
                    <div class="mb-2 flex items-center gap-2">
                        <InputText
                            :model-value="option.nom"
                            @update:model-value="
                                updateOption(i, { nom: $event })
                            "
                            placeholder="Nom de l'option (ex : Couleur)"
                            class="flex-1"
                            :class="{
                                'p-invalid':
                                    errors[`options.${i}.nom`],
                            }"
                        />
                        <button
                            type="button"
                            @click="removeOption(i)"
                            class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive"
                            aria-label="Supprimer l'option"
                        >
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                    <Chips
                        :model-value="option.valeurs"
                        @update:model-value="
                            updateOption(i, { valeurs: $event })
                        "
                        placeholder="Ajouter une valeur puis Entrée (ex : Noir)"
                        class="w-full"
                        :class="{
                            'p-invalid': errors[`options.${i}.valeurs`],
                        }"
                    />
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ option.valeurs.length }}
                        <template v-if="limites">
                            / {{ limites.max_valeurs_option }}</template
                        >
                        valeur(s)
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
                        Ajoutez au moins une valeur par option pour générer
                        les variantes.
                    </span>
                </div>
                <p v-if="errors.options" class="text-xs text-destructive">
                    {{ errors.options }}
                </p>
            </div>
        </div>

        <!-- Info variantes existantes (édition d'un produit déjà décliné) ──── -->
        <div
            v-else-if="existingVariantesCount > 1"
            class="flex items-start gap-3 rounded-xl border bg-muted/30 p-4 text-sm sm:p-6"
        >
            <Layers class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
            <p class="text-muted-foreground">
                Ce produit a {{ existingVariantesCount }} déclinaisons. Le
                prix/stock de chaque variante se gère individuellement depuis
                la fiche produit — les champs ci-dessous ne s'appliquent qu'à
                la variante par défaut.
            </p>
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
                :class="isFabricable ? 'lg:grid-cols-4' : 'lg:grid-cols-3'"
            >
                <div v-if="isFabricable">
                    <Label class="mb-1.5 block">Prix usine</Label>
                    <InputNumber
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
                    />
                    <p
                        v-if="errors.prix_usine"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errors.prix_usine }}
                    </p>
                </div>

                <div>
                    <Label class="mb-1.5 block">Prix achat</Label>
                    <InputNumber
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
                    />
                    <p
                        v-if="errors.prix_achat"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errors.prix_achat }}
                    </p>
                </div>

                <div>
                    <Label class="mb-1.5 block">Prix vente</Label>
                    <InputNumber
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
                    />
                    <p
                        v-if="errors.prix_vente"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errors.prix_vente }}
                    </p>
                </div>

                <div>
                    <Label class="mb-1.5 block">Coût de revient</Label>
                    <InputNumber
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
                Stock
            </h3>

            <div class="grid gap-4 sm:grid-cols-2 sm:gap-5">
                <div>
                    <Label class="mb-1.5 block">Seuil d'alerte stock</Label>
                    <InputNumber
                        :model-value="form.seuil_alerte_stock"
                        @update:model-value="
                            $emit('update:form', {
                                ...form,
                                seuil_alerte_stock: $event,
                            })
                        "
                        :min="0"
                        class="w-full"
                        input-class="w-full"
                    />
                    <p class="mt-1 text-xs text-muted-foreground">
                        Laisser vide pour utiliser le seuil global
                    </p>
                </div>

                <div class="flex items-center gap-3 sm:pt-6">
                    <Checkbox
                        id="is_alerte"
                        :model-value="Boolean(form.is_alerte)"
                        @update:model-value="
                            $emit('update:form', {
                                ...form,
                                is_alerte: $event === true,
                            })
                        "
                    />
                    <div>
                        <Label
                            for="is_alerte"
                            class="cursor-pointer font-medium"
                            >Produit en alerte</Label
                        >
                        <p class="text-xs text-muted-foreground">
                            Déclenche une alerte en cas de rupture
                        </p>
                    </div>
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
