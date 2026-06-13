<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Package } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import { computed } from 'vue';

interface ProduitMin {
    id: string;
    nom: string;
    code_interne: string | null;
    qte_stock: number | null;
}

const props = defineProps<{
    visible: boolean;
    produit: ProduitMin;
}>();

const emit = defineEmits<{
    (e: 'update:visible', val: boolean): void;
}>();

const localVisible = computed({
    get: () => props.visible,
    set: (val) => emit('update:visible', val),
});

const MOTIF_OPTIONS = [
    { label: 'Après production', value: 'apres_production' },
    { label: 'Correction de stock', value: 'correction_stock' },
    { label: 'Perte', value: 'perte' },
    { label: 'Retour', value: 'retour' },
    { label: 'Don', value: 'don' },
    { label: 'Autre', value: 'autre' },
];

const form = useForm({
    augmenter: null as number | null,
    diminuer: null as number | null,
    motif_type: null as string | null,
    motif_detail: '',
});

const isAutre = computed(() => form.motif_type === 'autre');

const stockActuel = computed(() => props.produit.qte_stock ?? 0);

const stockPreview = computed(() => {
    if (form.augmenter && form.augmenter > 0)
        return stockActuel.value + form.augmenter;
    if (form.diminuer && form.diminuer > 0)
        return stockActuel.value - form.diminuer;
    return null;
});

function onAugmenterChange() {
    if (form.augmenter) form.diminuer = null;
}

function onDiminuerChange() {
    if (form.diminuer) form.augmenter = null;
}

function onMotifTypeChange() {
    if (form.motif_type !== 'autre') form.motif_detail = '';
}

function close() {
    localVisible.value = false;
    form.reset();
    form.clearErrors();
}

function formatNum(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val);
}

function submit() {
    form.post(`/produits/${props.produit.id}/ajuster-stock`, {
        preserveScroll: true,
        onSuccess: () => close(),
    });
}
</script>

<template>
    <Dialog
        v-model:visible="localVisible"
        modal
        :header="'Ajuster le stock'"
        :style="{ width: '32rem' }"
        :draggable="false"
        @hide="
            form.reset();
            form.clearErrors();
        "
    >
        <!-- Produit info -->
        <div
            class="mb-5 flex items-center gap-3 rounded-lg bg-muted/50 px-4 py-3"
        >
            <Package class="h-5 w-5 shrink-0 text-muted-foreground" />
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold">{{ produit.nom }}</p>
                <p
                    v-if="produit.code_interne"
                    class="font-mono text-xs text-muted-foreground"
                >
                    {{ produit.code_interne }}
                </p>
            </div>
            <div class="ml-auto shrink-0 text-right">
                <p class="text-xs text-muted-foreground">Stock actuel</p>
                <p class="text-2xl font-bold tabular-nums">
                    {{ formatNum(stockActuel) }}
                </p>
            </div>
        </div>

        <div class="space-y-4">
            <!-- Augmenter + Diminuer côte à côte -->
            <div class="grid grid-cols-2 gap-3">
                <!-- Augmenter -->
                <div class="space-y-1.5">
                    <label
                        for="ajuster-augmenter"
                        class="flex items-center gap-1.5 text-sm font-medium"
                    >
                        <ArrowUp class="h-4 w-4 text-emerald-600" />
                        Augmenter
                    </label>
                    <InputNumber
                        v-model="form.augmenter"
                        input-id="ajuster-augmenter"
                        :min="1"
                        :use-grouping="true"
                        class="w-full"
                        :input-class="
                            form.errors.augmenter
                                ? 'p-invalid w-full'
                                : 'w-full'
                        "
                        @update:model-value="onAugmenterChange"
                    />
                    <p
                        v-if="form.errors.augmenter"
                        class="text-xs text-destructive"
                    >
                        {{ form.errors.augmenter }}
                    </p>
                </div>

                <!-- Diminuer -->
                <div class="space-y-1.5">
                    <label
                        for="ajuster-diminuer"
                        class="flex items-center gap-1.5 text-sm font-medium"
                    >
                        <ArrowDown class="h-4 w-4 text-destructive" />
                        Diminuer
                    </label>
                    <InputNumber
                        v-model="form.diminuer"
                        input-id="ajuster-diminuer"
                        :min="1"
                        :use-grouping="true"
                        class="w-full"
                        :input-class="
                            form.errors.diminuer ? 'p-invalid w-full' : 'w-full'
                        "
                        @update:model-value="onDiminuerChange"
                    />
                    <p
                        v-if="form.errors.diminuer"
                        class="text-xs text-destructive"
                    >
                        {{ form.errors.diminuer }}
                    </p>
                </div>
            </div>

            <!-- Motif (obligatoire) -->
            <div class="space-y-1.5">
                <label
                    for="ajuster-motif"
                    class="text-sm font-medium"
                >
                    Motif <span class="text-destructive">*</span>
                </label>
                <Dropdown
                    v-model="form.motif_type"
                    :options="MOTIF_OPTIONS"
                    option-label="label"
                    option-value="value"
                    placeholder="Sélectionner un motif"
                    class="w-full"
                    :class="form.errors.motif_type ? 'p-invalid' : ''"
                    @change="onMotifTypeChange"
                />
                <p
                    v-if="form.errors.motif_type"
                    class="text-xs text-destructive"
                >
                    {{ form.errors.motif_type }}
                </p>
            </div>

            <!-- Détail motif (affiché uniquement pour "Autre") -->
            <div v-if="isAutre" class="space-y-1.5">
                <label
                    for="ajuster-motif-detail"
                    class="text-sm font-medium"
                >
                    Préciser <span class="text-destructive">*</span>
                </label>
                <InputText
                    id="ajuster-motif-detail"
                    v-model="form.motif_detail"
                    placeholder="Décrire le motif…"
                    class="w-full"
                    :class="form.errors.motif_detail ? 'p-invalid' : ''"
                    maxlength="500"
                />
                <p
                    v-if="form.errors.motif_detail"
                    class="text-xs text-destructive"
                >
                    {{ form.errors.motif_detail }}
                </p>
            </div>

            <!-- Aperçu stock après -->
            <div
                v-if="stockPreview !== null"
                class="flex items-center justify-between rounded-lg border px-4 py-2.5 text-sm"
                :class="
                    stockPreview < 0
                        ? 'border-destructive/30 bg-destructive/5 text-destructive'
                        : 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800/30 dark:bg-emerald-950/20 dark:text-emerald-400'
                "
            >
                <span>Stock après ajustement</span>
                <span class="text-lg font-bold tabular-nums">{{
                    formatNum(stockPreview)
                }}</span>
            </div>
        </div>

        <template #footer>
            <div class="flex justify-end gap-2">
                <Button variant="outline" @click="close">Annuler</Button>
                <Button
                    :disabled="
                        form.processing || (!form.augmenter && !form.diminuer)
                    "
                    @click="submit"
                >
                    Valider
                </Button>
            </div>
        </template>
    </Dialog>
</template>
