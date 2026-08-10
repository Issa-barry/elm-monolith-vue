<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/vue3';
import { Plus, Save, Trash2 } from 'lucide-vue-next';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import { computed, ref } from 'vue';

interface CategorieOption {
    value: string;
    label: string;
}

interface CapaciteRow {
    categorie_id: string | null;
    capacite_max: number | null;
}

const props = defineProps<{
    capacites: Array<{ categorie_id: string; capacite_max: number }>;
    categories: CategorieOption[];
    syncUrl: string;
    /** Repli affiché quand aucune ligne n'est configurée — l'ancien plafond global unique. */
    capaciteLegacy?: number | null;
}>();

const rows = ref<CapaciteRow[]>(
    props.capacites.length > 0
        ? props.capacites.map((c) => ({
              categorie_id: c.categorie_id,
              capacite_max: c.capacite_max,
          }))
        : [],
);

function categorieOptionsFor(index: number): CategorieOption[] {
    const usedElsewhere = rows.value
        .filter((_, i) => i !== index)
        .map((r) => r.categorie_id);
    return props.categories.filter((c) => !usedElsewhere.includes(c.value));
}

function addRow() {
    rows.value.push({ categorie_id: null, capacite_max: null });
}

function removeRow(index: number) {
    rows.value.splice(index, 1);
}

const form = useForm({ capacites: [] as CapaciteRow[] });

const canSave = computed(() =>
    rows.value.every(
        (r) => r.categorie_id && r.capacite_max && r.capacite_max > 0,
    ),
);

function save() {
    form.capacites = rows.value;
    form.transform((data) => ({
        capacites: data.capacites.map((r) => ({
            categorie_id: r.categorie_id,
            capacite_max: r.capacite_max,
        })),
    })).put(props.syncUrl, { preserveScroll: true });
}
</script>

<template>
    <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
        <div class="mb-4 flex items-center justify-between">
            <div>
                <h3
                    class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    Capacités par catégorie
                </h3>
                <p class="mt-1 text-xs text-muted-foreground">
                    Un plafond indépendant par catégorie de produit (ex. sachets
                    et bouteilles ne se partagent pas la même place). Catégorie
                    sans ligne = pas de limite spécifique.
                </p>
            </div>
        </div>

        <p
            v-if="rows.length === 0 && capaciteLegacy"
            class="mb-4 rounded-lg bg-muted/50 px-3 py-2 text-xs text-muted-foreground"
        >
            Aucune capacité par catégorie configurée — la capacité globale
            actuelle ({{ capaciteLegacy }} packs, toutes catégories confondues)
            reste appliquée telle quelle.
        </p>

        <div class="space-y-3">
            <div
                v-for="(row, index) in rows"
                :key="index"
                class="flex items-end gap-2"
            >
                <div class="flex-1">
                    <Label class="mb-1.5 block text-xs">Catégorie</Label>
                    <Dropdown
                        v-model="row.categorie_id"
                        :options="categorieOptionsFor(index)"
                        option-label="label"
                        option-value="value"
                        placeholder="Choisir…"
                        class="w-full"
                    />
                </div>
                <div class="w-32">
                    <Label class="mb-1.5 block text-xs">Capacité max</Label>
                    <InputNumber
                        v-model="row.capacite_max"
                        :min="1"
                        :max="99999"
                        :use-grouping="false"
                        class="w-full"
                    />
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="h-10 w-10 shrink-0 text-destructive"
                    @click="removeRow(index)"
                >
                    <Trash2 class="h-4 w-4" />
                </Button>
            </div>
        </div>

        <p v-if="form.errors.capacites" class="mt-2 text-xs text-destructive">
            {{ form.errors.capacites }}
        </p>

        <div class="mt-4 flex items-center justify-between">
            <Button type="button" variant="outline" size="sm" @click="addRow">
                <Plus class="mr-1.5 h-3.5 w-3.5" />
                Ajouter une capacité
            </Button>
            <Button
                type="button"
                size="sm"
                :disabled="!canSave || form.processing"
                @click="save"
            >
                <Save class="mr-1.5 h-3.5 w-3.5" />
                {{ form.processing ? 'Enregistrement…' : 'Enregistrer' }}
            </Button>
        </div>
    </div>
</template>
