<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    MOTIFS_AUGMENTATION,
    MOTIFS_DIMINUTION,
} from '@/shared/motifs-ajustement-stock';
import { useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Lock, Package } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import { computed, ref, watch } from 'vue';

interface SiteStock {
    site_id: string;
    site_code: string | null;
    site_nom: string | null;
    qte_stock: number;
}

interface ProduitMin {
    id: string;
    nom: string;
    code_interne: string | null;
    qte_stock: number | null;
    stocks_par_site: SiteStock[];
}

interface Site {
    id: string;
    nom: string;
    code: string;
}

const props = defineProps<{
    visible: boolean;
    produit: ProduitMin;
    sites: Site[];
    isAdmin: boolean;
    userDefaultSiteId: string | null;
}>();

const emit = defineEmits<{
    (e: 'update:visible', val: boolean): void;
}>();

const localVisible = computed({
    get: () => props.visible,
    set: (val) => emit('update:visible', val),
});

// Pré-remplir le site pour les non-admins dès l'ouverture du modal
watch(
    () => props.visible,
    (val) => {
        if (val && !props.isAdmin && props.userDefaultSiteId) {
            form.site_id = props.userDefaultSiteId;
        }
    },
);

const form = useForm({
    site_id: null as string | null,
    augmenter: null as number | null,
    diminuer: null as number | null,
    motif_type: null as string | null,
    motif_detail: '',
});

// Clés de remontage forcé pour que le DOM de l'InputNumber reflète null (vide)
const augKey = ref(0);
const dimKey = ref(0);

// Exclusion mutuelle : dès qu'un champ reçoit une valeur, on vide l'autre
// et on force le remontage du composant pour que l'input DOM soit vidé.
watch(
    () => form.augmenter,
    (val) => {
        if (val !== null && val > 0 && form.diminuer !== null) {
            form.diminuer = null;
            dimKey.value++;
            resetMotifIfInvalid();
        }
    },
);

watch(
    () => form.diminuer,
    (val) => {
        if (val !== null && val > 0 && form.augmenter !== null) {
            form.augmenter = null;
            augKey.value++;
            resetMotifIfInvalid();
        }
    },
);

const direction = computed<'augmenter' | 'diminuer' | ''>(() => {
    if (form.augmenter) return 'augmenter';
    if (form.diminuer) return 'diminuer';
    return '';
});

const motifOptions = computed(() => {
    if (direction.value === 'augmenter') return MOTIFS_AUGMENTATION;
    if (direction.value === 'diminuer') return MOTIFS_DIMINUTION;
    return [];
});

const isAutre = computed(() => form.motif_type === 'autre');

const stockActuel = computed(() => {
    if (!form.site_id) return props.produit.qte_stock ?? 0;
    const siteStock = props.produit.stocks_par_site.find(
        (s) => s.site_id === form.site_id,
    );
    return siteStock?.qte_stock ?? 0;
});

const stockPreview = computed(() => {
    if (form.augmenter && form.augmenter > 0)
        return stockActuel.value + form.augmenter;
    if (form.diminuer && form.diminuer > 0)
        return stockActuel.value - form.diminuer;
    return null;
});

const siteOptions = computed(() =>
    props.sites.map((s) => ({
        label: s.nom + (s.code ? ` (${s.code})` : ''),
        value: s.id,
    })),
);

function resetMotifIfInvalid() {
    if (!form.motif_type) return;
    const valid = motifOptions.value.some((o) => o.value === form.motif_type);
    if (!valid) {
        form.motif_type = null;
        form.motif_detail = '';
    }
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
            augKey++;
            dimKey++;
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
                <p class="text-xs text-muted-foreground">
                    {{ form.site_id ? 'Stock sur ce site' : 'Stock total' }}
                </p>
                <p class="text-2xl font-bold tabular-nums">
                    {{ formatNum(stockActuel) }}
                </p>
            </div>
        </div>

        <div class="space-y-4">
            <!-- Site -->
            <div class="space-y-1.5">
                <label for="ajuster-site" class="text-sm font-medium">
                    Site <span class="text-destructive">*</span>
                </label>

                <!-- Admin : peut choisir n'importe quel site -->
                <Dropdown
                    v-if="isAdmin"
                    v-model="form.site_id"
                    input-id="ajuster-site"
                    :options="siteOptions"
                    option-label="label"
                    option-value="value"
                    placeholder="Sélectionner un site…"
                    class="w-full"
                    :class="form.errors.site_id ? 'p-invalid' : ''"
                    :pt="{ root: { 'data-testid': 'stock-site-select' } }"
                />

                <!-- Non-admin : site verrouillé sur son agence -->
                <div
                    v-else
                    class="flex items-center gap-2 rounded-md border border-input bg-muted/50 px-3 py-2 text-sm"
                >
                    <Lock class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                    <span class="font-medium">
                        {{
                            siteOptions.find((o) => o.value === form.site_id)
                                ?.label ?? '—'
                        }}
                    </span>
                    <span class="ml-auto text-xs text-muted-foreground">Votre agence</span>
                </div>

                <p v-if="form.errors.site_id" class="text-xs text-destructive">
                    {{ form.errors.site_id }}
                </p>
            </div>

            <!-- Augmenter + Diminuer côte à côte -->
            <div class="grid grid-cols-2 gap-3">
                <div class="space-y-1.5" data-testid="stock-augmenter-input">
                    <label
                        for="ajuster-augmenter"
                        class="flex items-center gap-1.5 text-sm font-medium"
                    >
                        <ArrowUp class="h-4 w-4 text-emerald-600" />
                        Augmenter
                    </label>
                    <InputNumber
                        :key="`aug-${augKey}`"
                        v-model="form.augmenter"
                        input-id="ajuster-augmenter"
                        :min="1"
                        :use-grouping="true"
                        :disabled="!form.site_id"
                        class="w-full"
                        :input-class="
                            [
                                'w-full',
                                form.errors.augmenter ? 'p-invalid' : '',
                                form.diminuer ? 'opacity-40' : '',
                            ].join(' ')
                        "
                    />
                    <p
                        v-if="form.errors.augmenter"
                        class="text-xs text-destructive"
                    >
                        {{ form.errors.augmenter }}
                    </p>
                </div>

                <div class="space-y-1.5" data-testid="stock-diminuer-input">
                    <label
                        for="ajuster-diminuer"
                        class="flex items-center gap-1.5 text-sm font-medium"
                    >
                        <ArrowDown class="h-4 w-4 text-destructive" />
                        Diminuer
                    </label>
                    <InputNumber
                        :key="`dim-${dimKey}`"
                        v-model="form.diminuer"
                        input-id="ajuster-diminuer"
                        :min="1"
                        :use-grouping="true"
                        :disabled="!form.site_id"
                        class="w-full"
                        :input-class="
                            [
                                'w-full',
                                form.errors.diminuer ? 'p-invalid' : '',
                                form.augmenter ? 'opacity-40' : '',
                            ].join(' ')
                        "
                    />
                    <p
                        v-if="form.errors.diminuer"
                        class="text-xs text-destructive"
                    >
                        {{ form.errors.diminuer }}
                    </p>
                </div>
            </div>

            <!-- Motif -->
            <div class="space-y-1.5">
                <label for="ajuster-motif" class="text-sm font-medium">
                    Motif <span class="text-destructive">*</span>
                </label>
                <Dropdown
                    v-model="form.motif_type"
                    :options="motifOptions"
                    option-label="label"
                    option-value="value"
                    :placeholder="
                        direction
                            ? 'Sélectionner un motif'
                            : 'Saisissez d\'abord une quantité…'
                    "
                    :disabled="!direction"
                    class="w-full"
                    :class="form.errors.motif_type ? 'p-invalid' : ''"
                    :pt="{ root: { 'data-testid': 'stock-motif-select' } }"
                    @change="onMotifTypeChange"
                />
                <p
                    v-if="form.errors.motif_type"
                    class="text-xs text-destructive"
                >
                    {{ form.errors.motif_type }}
                </p>
            </div>

            <!-- Détail motif -->
            <div v-if="isAutre" class="space-y-1.5">
                <label for="ajuster-motif-detail" class="text-sm font-medium">
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
                    data-testid="stock-submit-button"
                    :disabled="
                        form.processing ||
                        !form.site_id ||
                        (!form.augmenter && !form.diminuer)
                    "
                    @click="submit"
                >
                    Valider
                </Button>
            </div>
        </template>
    </Dialog>
</template>
