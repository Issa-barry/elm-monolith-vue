<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Plus, Trash2 } from 'lucide-vue-next';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import { computed } from 'vue';

export interface CategorieOption {
    value: string;
    label: string;
}

/**
 * `categorie_id`/`capacite_max` nullable : une ligne fraîchement ajoutée est vide tant que
 * l'utilisateur ne l'a pas remplie — aucune confirmation intermédiaire, la validation
 * (champ manquant, capacité <= 0) est laissée au backend au moment de l'enregistrement
 * global du véhicule (cf. VehiculeController::validationRules()), affichée ligne par ligne
 * via la prop `errors` ci-dessous — même convention que les lignes de commande
 * (cf. Ventes/Create.vue, clés `capacites.{index}.{champ}`).
 */
export interface CapaciteRow {
    categorie_id: string | null;
    capacite_max: number | null;
}

const props = defineProps<{
    modelValue: CapaciteRow[];
    categoriesProduit: CategorieOption[];
    errors?: Record<string, string>;
}>();

const emit = defineEmits<{ 'update:modelValue': [CapaciteRow[]] }>();

function errorFor(
    index: number,
    champ: 'categorie_id' | 'capacite_max',
): string | undefined {
    return props.errors?.[`capacites.${index}.${champ}`];
}

// Catégories déjà utilisées par une AUTRE ligne — jamais reproposées, empêche les doublons
// par construction plutôt que de compter uniquement sur la contrainte `distinct` déjà
// appliquée côté backend (gardée comme filet de sécurité, cf. validationRules()).
function categoriesDisponibles(excludeIndex: number): CategorieOption[] {
    const utilisees = props.modelValue
        .filter((_, i) => i !== excludeIndex)
        .map((r) => r.categorie_id)
        .filter((id): id is string => id !== null);

    return props.categoriesProduit.filter((c) => !utilisees.includes(c.value));
}

const categoriesRestantes = computed(() => categoriesDisponibles(-1));

function addRow(): void {
    emit('update:modelValue', [
        ...props.modelValue,
        { categorie_id: null, capacite_max: null },
    ]);
}

function updateRow(index: number, patch: Partial<CapaciteRow>): void {
    emit(
        'update:modelValue',
        props.modelValue.map((r, i) => (i === index ? { ...r, ...patch } : r)),
    );
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

        <div v-if="modelValue.length > 0" class="mt-4 space-y-3">
            <div
                v-for="(row, index) in modelValue"
                :key="index"
                class="flex flex-col gap-2 rounded-lg border p-3 sm:flex-row sm:items-start"
            >
                <div class="flex-1">
                    <Label class="mb-1.5 block text-xs"
                        >Catégorie de produit</Label
                    >
                    <Dropdown
                        :model-value="row.categorie_id"
                        @update:model-value="
                            updateRow(index, { categorie_id: $event })
                        "
                        :options="categoriesDisponibles(index)"
                        option-label="label"
                        option-value="value"
                        placeholder="Choisir…"
                        class="w-full"
                        :class="{
                            'p-invalid': errorFor(index, 'categorie_id'),
                        }"
                    />
                    <p
                        v-if="errorFor(index, 'categorie_id')"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errorFor(index, 'categorie_id') }}
                    </p>
                </div>
                <div class="w-full sm:w-40">
                    <Label class="mb-1.5 block text-xs">Capacité max</Label>
                    <InputNumber
                        :model-value="row.capacite_max"
                        @update:model-value="
                            updateRow(index, { capacite_max: $event })
                        "
                        :min="1"
                        :max="99999"
                        :use-grouping="false"
                        class="w-full"
                        :class="{
                            'p-invalid': errorFor(index, 'capacite_max'),
                        }"
                    />
                    <p
                        v-if="errorFor(index, 'capacite_max')"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errorFor(index, 'capacite_max') }}
                    </p>
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="mt-1 h-9 w-9 shrink-0 text-destructive sm:mt-6"
                    @click="removeRow(index)"
                >
                    <Trash2 class="h-4 w-4" />
                </Button>
            </div>
        </div>

        <Button
            type="button"
            variant="outline"
            size="sm"
            class="mt-4"
            :disabled="categoriesRestantes.length === 0"
            @click="addRow"
        >
            <Plus class="mr-1.5 h-3.5 w-3.5" />
            Ajouter une ligne
        </Button>
        <p
            v-if="categoriesProduit.length === 0"
            class="mt-2 text-xs text-muted-foreground"
        >
            Aucune catégorie de produit définie —
            <a href="/backoffice/produits/categories" class="underline"
                >en créer une</a
            >
            (ex : Sachet eau, Bouteille).
        </p>
        <p
            v-else-if="categoriesRestantes.length === 0"
            class="mt-2 text-xs text-muted-foreground"
        >
            Toutes les catégories ont déjà une ligne de capacité définie.
        </p>
    </div>
</template>
