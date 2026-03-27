<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Image, Save, X } from 'lucide-vue-next';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import Editor from 'primevue/editor';
import { computed, ref } from 'vue';

// ── Props / Emits ─────────────────────────────────────────────────────────────
interface Option { value: string; label: string }

interface FormData {
    nom: string;
    code_fournisseur: string | null;
    type: string;
    statut: string;
    prix_usine: number | null;
    prix_vente: number | null;
    prix_achat: number | null;
    cout: number | null;
    qte_stock: number;
    seuil_alerte_stock: number | null;
    description: string | null;
    is_critique: boolean;
    image: File | null;
}

const props = defineProps<{
    form: FormData;
    errors: Partial<Record<keyof FormData, string>>;
    types: Option[];
    statuts: Option[];
    processing: boolean;
    currentImageUrl?: string | null;
    currentCodeInterne?: string | null;
}>();

const emit = defineEmits<{
    submit: [];
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

const displayImage = computed(() => previewUrl.value ?? props.currentImageUrl ?? null);
</script>

<template>
    <form id="produit-form" class="space-y-4 sm:space-y-8" @submit.prevent="emit('submit')">

        <!-- Section : Identification ──────────────────────────────────────── -->
        <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
            <h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-muted-foreground sm:mb-5">
                Identification
            </h3>

            <div class="grid gap-4 sm:grid-cols-2 sm:gap-5">
                <!-- Type -->
                <div>
                    <Label class="mb-1.5 block">Type <span class="text-destructive">*</span></Label>
                    <Dropdown
                        :model-value="form.type"
                        @update:model-value="$emit('update:form', { ...form, type: $event })"
                        :options="types"
                        option-label="label"
                        option-value="value"
                        placeholder="Sélectionner un type"
                        class="w-full"
                        :class="{ 'p-invalid': errors.type }"
                    />
                    <p v-if="errors.type" class="mt-1 text-xs text-destructive">{{ errors.type }}</p>
                </div>

                <!-- Statut -->
                <div>
                    <Label class="mb-1.5 block">Statut <span class="text-destructive">*</span></Label>
                    <Dropdown
                        :model-value="form.statut"
                        @update:model-value="$emit('update:form', { ...form, statut: $event })"
                        :options="statuts"
                        option-label="label"
                        option-value="value"
                        placeholder="Sélectionner un statut"
                        class="w-full"
                        :class="{ 'p-invalid': errors.statut }"
                    />
                    <p v-if="errors.statut" class="mt-1 text-xs text-destructive">{{ errors.statut }}</p>
                </div>

                <!-- Nom -->
                <div class="sm:col-span-2">
                    <Label for="nom" class="mb-1.5 block">Nom du produit <span class="text-destructive">*</span></Label>
                    <InputText
                        id="nom"
                        :model-value="form.nom"
                        @update:model-value="$emit('update:form', { ...form, nom: $event })"
                        class="w-full"
                        :class="{ 'p-invalid': errors.nom }"
                    />
                    <p v-if="errors.nom" class="mt-1 text-xs text-destructive">{{ errors.nom }}</p>
                </div>

                <!-- Code-barres (édition uniquement) -->
                <div v-if="currentCodeInterne">
                    <Label class="mb-1.5 block">Code-barres (Code 128)</Label>
                    <div class="flex h-10 w-full items-center rounded-md border bg-muted/40 px-3 font-mono text-sm tracking-widest text-muted-foreground select-all">
                        {{ currentCodeInterne }}
                    </div>
                </div>

                <!-- Code fournisseur -->
                <div>
                    <Label for="code_fournisseur" class="mb-1.5 block">Code fournisseur</Label>
                    <InputText
                        id="code_fournisseur"
                        :model-value="form.code_fournisseur ?? ''"
                        @update:model-value="$emit('update:form', { ...form, code_fournisseur: $event || null })"
                        class="w-full font-mono"
                    />
                </div>
            </div>
        </div>

        <!-- Section : Tarification ───────────────────────────────────────── -->
        <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
            <h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-muted-foreground sm:mb-5">
                Tarification <span class="text-xs font-normal normal-case">(GNF)</span>
            </h3>

            <div class="grid gap-4 sm:grid-cols-2 sm:gap-5" :class="isFabricable ? 'lg:grid-cols-4' : 'lg:grid-cols-3'">
                <div v-if="isFabricable">
                    <Label class="mb-1.5 block">Prix usine</Label>
                    <InputNumber
                        :model-value="form.prix_usine"
                        @update:model-value="$emit('update:form', { ...form, prix_usine: $event })"
                        :min="0" :use-grouping="true" locale="fr-GN"
                        class="w-full" input-class="w-full"
                    />
                    <p v-if="errors.prix_usine" class="mt-1 text-xs text-destructive">{{ errors.prix_usine }}</p>
                </div>

                <div>
                    <Label class="mb-1.5 block">Prix achat</Label>
                    <InputNumber
                        :model-value="form.prix_achat"
                        @update:model-value="$emit('update:form', { ...form, prix_achat: $event })"
                        :min="0" :use-grouping="true" locale="fr-GN"
                        class="w-full" input-class="w-full"
                    />
                    <p v-if="errors.prix_achat" class="mt-1 text-xs text-destructive">{{ errors.prix_achat }}</p>
                </div>

                <div>
                    <Label class="mb-1.5 block">Prix vente</Label>
                    <InputNumber
                        :model-value="form.prix_vente"
                        @update:model-value="$emit('update:form', { ...form, prix_vente: $event })"
                        :min="0" :use-grouping="true" locale="fr-GN"
                        class="w-full" input-class="w-full"
                    />
                    <p v-if="errors.prix_vente" class="mt-1 text-xs text-destructive">{{ errors.prix_vente }}</p>
                </div>

                <div>
                    <Label class="mb-1.5 block">Coût de revient</Label>
                    <InputNumber
                        :model-value="form.cout"
                        @update:model-value="$emit('update:form', { ...form, cout: $event })"
                        :min="0" :use-grouping="true" locale="fr-GN"
                        class="w-full" input-class="w-full"
                    />
                </div>
            </div>
        </div>

        <!-- Section : Stock ───────────────────────────────────────────────── -->
        <div v-if="typeHasStock" class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
            <h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-muted-foreground sm:mb-5">
                Stock
            </h3>

            <div class="grid gap-4 sm:grid-cols-3 sm:gap-5">
                <div>
                    <Label class="mb-1.5 block">Quantité en stock</Label>
                    <InputNumber
                        :model-value="form.qte_stock"
                        @update:model-value="$emit('update:form', { ...form, qte_stock: $event ?? 0 })"
                        :min="0" class="w-full" input-class="w-full"
                    />
                </div>

                <div>
                    <Label class="mb-1.5 block">Seuil d'alerte stock</Label>
                    <InputNumber
                        :model-value="form.seuil_alerte_stock"
                        @update:model-value="$emit('update:form', { ...form, seuil_alerte_stock: $event })"
                        :min="0" class="w-full" input-class="w-full"
                    />
                    <p class="mt-1 text-xs text-muted-foreground">Laisser vide pour utiliser le seuil global</p>
                </div>

                <div class="flex items-center gap-3 sm:pt-6">
                    <Checkbox
                        id="is_critique"
                        :checked="form.is_critique"
                        @update:checked="$emit('update:form', { ...form, is_critique: $event })"
                    />
                    <div>
                        <Label for="is_critique" class="cursor-pointer font-medium">Produit critique</Label>
                        <p class="text-xs text-muted-foreground">Déclenche une alerte en cas de rupture</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section : Image ──────────────────────────────────────────────── -->
        <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
            <h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-muted-foreground sm:mb-5">
                Image du produit
            </h3>

            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:gap-6">
                <!-- Prévisualisation -->
                <div class="flex h-28 w-28 shrink-0 items-center justify-center self-center overflow-hidden rounded-xl border-2 border-dashed bg-muted/40 sm:h-36 sm:w-36 sm:self-start">
                    <img v-if="displayImage" :src="displayImage" alt="Aperçu" class="h-full w-full object-cover" />
                    <Image v-else class="h-10 w-10 text-muted-foreground/40" />
                </div>

                <!-- Actions -->
                <div class="flex flex-col gap-3">
                    <Label class="block text-sm text-muted-foreground">
                        Formats acceptés : JPG, PNG, WEBP — max 2 Mo
                    </Label>
                    <div class="flex flex-wrap items-center gap-2">
                        <label class="cursor-pointer">
                            <input type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" @change="onImageChange" />
                            <span class="inline-flex items-center gap-1.5 rounded-md border bg-background px-3 py-2 text-sm font-medium shadow-sm transition-colors hover:bg-muted">
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
                    <p v-if="errors.image" class="text-xs text-destructive">{{ errors.image }}</p>
                </div>
            </div>
        </div>

        <!-- Section : Description ────────────────────────────────────────── -->
        <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
            <h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-muted-foreground sm:mb-5">
                Description
            </h3>
            <Editor
                :model-value="form.description ?? ''"
                @update:model-value="$emit('update:form', { ...form, description: $event || null })"
                editor-style="min-height: 140px"
                class="w-full"
            >
                <template #toolbar>
                    <span class="ql-formats">
                        <button class="ql-bold" /><button class="ql-italic" /><button class="ql-underline" />
                    </span>
                    <span class="ql-formats">
                        <button class="ql-list" value="bullet" /><button class="ql-list" value="ordered" />
                    </span>
                    <span class="ql-formats">
                        <button class="ql-link" /><button class="ql-clean" />
                    </span>
                </template>
            </Editor>
        </div>

        <!-- Pied de formulaire (desktop uniquement) ─────────────────────── -->
        <div class="hidden items-center justify-between sm:flex">
            <a href="/produits">
                <Button type="button" variant="outline">
                    Retour
                </Button>
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
