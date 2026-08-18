<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Pencil, Plus, Trash2 } from 'lucide-vue-next';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import { computed, ref } from 'vue';

export interface CategorieOption {
    value: string;
    label: string;
}

export interface CapaciteRow {
    categorie_id: string;
    capacite_max: number;
}

const props = defineProps<{
    modelValue: CapaciteRow[];
    categoriesProduit: CategorieOption[];
}>();

const emit = defineEmits<{ 'update:modelValue': [CapaciteRow[]] }>();

function categorieLabel(id: string): string {
    return props.categoriesProduit.find((c) => c.value === id)?.label ?? id;
}

function availableCategories(excludeIndex?: number): CategorieOption[] {
    const used = props.modelValue
        .filter((_, i) => i !== excludeIndex)
        .map((r) => r.categorie_id);
    return props.categoriesProduit.filter((c) => !used.includes(c.value));
}

// ── Ajout ────────────────────────────────────────────────────────────────
const adding = ref(false);
const newCategorieId = ref<string | null>(null);
const newCapacite = ref<number | null>(null);

function startAdd(): void {
    newCategorieId.value = null;
    newCapacite.value = null;
    adding.value = true;
}

// Le bouton "Ajouter" ne doit JAMAIS disparaître (ancien bug) : uniquement désactivé/activé
// via :disabled, jamais retiré du DOM via v-if — l'utilisateur voit toujours où il en est.
const canAdd = computed(
    () =>
        !!newCategorieId.value && !!newCapacite.value && newCapacite.value > 0,
);

function confirmAdd(): void {
    if (!canAdd.value) return;
    emit('update:modelValue', [
        ...props.modelValue,
        {
            categorie_id: newCategorieId.value as string,
            capacite_max: newCapacite.value as number,
        },
    ]);
    adding.value = false;
}

// ── Édition ──────────────────────────────────────────────────────────────
const editingIndex = ref<number | null>(null);
const editCapacite = ref<number | null>(null);

function startEdit(index: number): void {
    editingIndex.value = index;
    editCapacite.value = props.modelValue[index].capacite_max;
}

function confirmEdit(index: number): void {
    if (!editCapacite.value || editCapacite.value <= 0) return;
    emit(
        'update:modelValue',
        props.modelValue.map((r, i) =>
            i === index
                ? { ...r, capacite_max: editCapacite.value as number }
                : r,
        ),
    );
    editingIndex.value = null;
}

function removeRow(index: number): void {
    emit(
        'update:modelValue',
        props.modelValue.filter((_, i) => i !== index),
    );
}
</script>

<template>
    <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
        <h3
            class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
        >
            Capacités maximales de chargement
        </h3>
        <p class="mt-1 text-xs text-muted-foreground">
            Limites propres à ce véhicule, par catégorie de produit (ex : Sachet
            eau, Bouteille) — utilisées pour bloquer toute vente ou tout
            chargement qui les dépasse. Aucune limite définie = véhicule non
            plafonné pour cette catégorie.
        </p>

        <div
            v-if="modelValue.length > 0"
            class="mt-4 divide-y rounded-lg border"
        >
            <div
                v-for="(row, index) in modelValue"
                :key="row.categorie_id"
                class="flex flex-col gap-2 px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between"
            >
                <template v-if="editingIndex === index">
                    <span class="text-sm font-medium">{{
                        categorieLabel(row.categorie_id)
                    }}</span>
                    <div class="flex items-center gap-2">
                        <InputNumber
                            v-model="editCapacite"
                            :min="1"
                            :max="99999"
                            :use-grouping="false"
                            class="w-28"
                            autofocus
                        />
                        <Button
                            type="button"
                            size="sm"
                            @click="confirmEdit(index)"
                            >OK</Button
                        >
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="editingIndex = null"
                            >Annuler</Button
                        >
                    </div>
                </template>
                <template v-else>
                    <span class="text-sm font-medium">{{
                        categorieLabel(row.categorie_id)
                    }}</span>
                    <div class="flex items-center gap-1">
                        <span class="text-sm text-muted-foreground">{{
                            row.capacite_max
                        }}</span>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="h-8 w-8 text-muted-foreground"
                            @click="startEdit(index)"
                        >
                            <Pencil class="h-3.5 w-3.5" />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="h-8 w-8 text-destructive"
                            @click="removeRow(index)"
                        >
                            <Trash2 class="h-3.5 w-3.5" />
                        </Button>
                    </div>
                </template>
            </div>
        </div>

        <div
            v-if="adding"
            class="mt-4 flex flex-col gap-2 rounded-lg border bg-muted/30 p-3 sm:flex-row sm:items-end"
        >
            <div class="flex-1">
                <Label class="mb-1.5 block text-xs">Catégorie de produit</Label>
                <Dropdown
                    v-model="newCategorieId"
                    :options="availableCategories()"
                    option-label="label"
                    option-value="value"
                    placeholder="Choisir…"
                    class="w-full"
                />
            </div>
            <div class="w-full sm:w-32">
                <Label class="mb-1.5 block text-xs">Capacité max</Label>
                <InputNumber
                    v-model="newCapacite"
                    :min="1"
                    :max="99999"
                    :use-grouping="false"
                    class="w-full"
                />
            </div>
            <div class="flex shrink-0 gap-2">
                <Button
                    type="button"
                    size="sm"
                    :disabled="!canAdd"
                    @click="confirmAdd"
                >
                    Ajouter
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    @click="adding = false"
                >
                    Annuler
                </Button>
            </div>
        </div>
        <template v-else>
            <Button
                type="button"
                variant="outline"
                size="sm"
                class="mt-4"
                :disabled="availableCategories().length === 0"
                @click="startAdd"
            >
                <Plus class="mr-1.5 h-3.5 w-3.5" />
                Ajouter une capacité
            </Button>
            <p
                v-if="
                    availableCategories().length === 0 &&
                    categoriesProduit.length === 0
                "
                class="mt-2 text-xs text-muted-foreground"
            >
                Aucune catégorie de produit définie —
                <a href="/backoffice/produits/categories" class="underline"
                    >en créer une</a
                >
                (ex : Sachet eau, Bouteille).
            </p>
            <p
                v-else-if="availableCategories().length === 0"
                class="mt-2 text-xs text-muted-foreground"
            >
                Toutes les catégories ont déjà une ligne de capacité définie.
            </p>
        </template>
    </div>
</template>
