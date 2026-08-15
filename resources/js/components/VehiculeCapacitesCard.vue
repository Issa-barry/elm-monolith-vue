<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/vue3';
import { Check, Pencil, Plus, Trash2, X } from 'lucide-vue-next';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed, ref, watch } from 'vue';

interface CategorieOption {
    value: string;
    label: string;
}

interface Capacite {
    categorie_id: string;
    capacite_max: number;
}

const props = defineProps<{
    capacites: Capacite[];
    categories: CategorieOption[];
    syncUrl: string;
    /** Repli affiché quand aucune ligne n'est configurée — l'ancien plafond global unique. */
    capaciteLegacy?: number | null;
}>();

const toast = useToast();
const confirm = useConfirm();

// Reflète l'état persisté (props.capacites) ; resynchronisé après chaque
// action puisque `persist()` recharge les props Inertia de la page.
const rows = ref<Capacite[]>([...props.capacites]);
watch(
    () => props.capacites,
    (value) => {
        rows.value = [...value];
    },
);

function categorieLabel(id: string): string {
    return props.categories.find((c) => c.value === id)?.label ?? id;
}

function availableCategories(): CategorieOption[] {
    const used = rows.value.map((r) => r.categorie_id);
    return props.categories.filter((c) => !used.includes(c.value));
}

const form = useForm({ capacites: [] as Capacite[] });

/**
 * Persiste immédiatement la liste complète — l'endpoint remplace tout
 * (cf. VehiculeController::syncCapacites). Chaque action (ajout, modification,
 * suppression) a donc un effet direct et visible, pas de bouton
 * "Enregistrer" séparé à actionner ensuite.
 */
function persist(next: Capacite[], onSuccess?: () => void): void {
    form.capacites = next;
    form.transform((data) => ({ capacites: data.capacites })).put(
        props.syncUrl,
        {
            preserveScroll: true,
            onSuccess: () => onSuccess?.(),
            onError: () =>
                toast.add({
                    severity: 'error',
                    summary: "Échec de l'enregistrement",
                    detail: 'Vérifiez la valeur saisie et réessayez.',
                    life: 4000,
                }),
        },
    );
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

const canAdd = computed(
    () =>
        !!newCategorieId.value && !!newCapacite.value && newCapacite.value > 0,
);

function confirmAdd(): void {
    if (!canAdd.value) return;
    const next = [
        ...rows.value,
        {
            categorie_id: newCategorieId.value as string,
            capacite_max: newCapacite.value as number,
        },
    ];
    persist(next, () => {
        adding.value = false;
    });
}

// ── Édition (capacité uniquement — changer de catégorie = retirer/ajouter) ─
const editingIndex = ref<number | null>(null);
const editCapacite = ref<number | null>(null);

function startEdit(index: number): void {
    editingIndex.value = index;
    editCapacite.value = rows.value[index].capacite_max;
}

function confirmEdit(index: number): void {
    if (!editCapacite.value || editCapacite.value <= 0) return;
    const next = rows.value.map((r, i) =>
        i === index ? { ...r, capacite_max: editCapacite.value as number } : r,
    );
    persist(next, () => {
        editingIndex.value = null;
    });
}

// ── Suppression ──────────────────────────────────────────────────────────
function removeRow(index: number): void {
    const row = rows.value[index];
    confirm.require({
        message: `Retirer la capacité « ${categorieLabel(row.categorie_id)} » ?`,
        header: 'Confirmer la suppression',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Annuler',
        acceptLabel: 'Retirer',
        acceptClass: 'p-button-danger',
        accept: () => persist(rows.value.filter((_, i) => i !== index)),
    });
}
</script>

<template>
    <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
        <h3
            class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
        >
            Capacités par catégorie
        </h3>
        <p class="mt-1 text-xs text-muted-foreground">
            Définissez la capacité maximale du véhicule pour chaque catégorie de
            produit.
        </p>

        <p
            v-if="rows.length === 0 && capaciteLegacy"
            class="mt-4 rounded-lg bg-muted/50 px-3 py-2 text-xs text-muted-foreground"
        >
            Aucune capacité par catégorie définie — la capacité globale du
            véhicule ({{ capaciteLegacy }} sachets) s'applique à toutes les
            catégories.
        </p>

        <div v-if="rows.length > 0" class="mt-4 divide-y rounded-lg border">
            <div
                v-for="(row, index) in rows"
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
                        <span class="text-xs text-muted-foreground">sachets</span>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="h-8 w-8 text-emerald-600"
                            :disabled="form.processing"
                            @click="confirmEdit(index)"
                        >
                            <Check class="h-4 w-4" />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="h-8 w-8"
                            :disabled="form.processing"
                            @click="editingIndex = null"
                        >
                            <X class="h-4 w-4" />
                        </Button>
                    </div>
                </template>
                <template v-else>
                    <span class="text-sm font-medium">{{
                        categorieLabel(row.categorie_id)
                    }}</span>
                    <div class="flex items-center gap-1">
                        <span class="text-sm text-muted-foreground"
                            >{{ row.capacite_max }} sachets</span
                        >
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="h-8 w-8 text-muted-foreground"
                            :disabled="form.processing"
                            @click="startEdit(index)"
                        >
                            <Pencil class="h-3.5 w-3.5" />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="h-8 w-8 text-destructive"
                            :disabled="form.processing"
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
                <Label class="mb-1.5 block text-xs">Catégorie</Label>
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
            <div class="flex gap-2">
                <Button
                    type="button"
                    size="sm"
                    :disabled="!canAdd || form.processing"
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
                v-if="availableCategories().length === 0 && categories.length"
                class="mt-2 text-xs text-muted-foreground"
            >
                Toutes les catégories ont déjà une capacité définie.
            </p>
        </template>
    </div>
</template>
