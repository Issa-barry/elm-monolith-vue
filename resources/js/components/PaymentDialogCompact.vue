<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { HandCoins } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import InputNumber from 'primevue/inputnumber';
import Select from 'primevue/select';
import { computed, ref, watch } from 'vue';

interface ModeOption {
    value: string;
    label: string;
}

interface Props {
    visible: boolean;
    title: string;
    /** Montant affiché dans la bannière « Solde à payer » + valeur max par défaut */
    solde: number;
    /** Surcharge du max InputNumber si différent du solde */
    maxMontant?: number;
    processing?: boolean;
    errors?: Record<string, string>;
    modesPaiement?: ModeOption[];
}

const props = withDefaults(defineProps<Props>(), {
    maxMontant: undefined,
    processing: false,
    errors: () => ({}),
    modesPaiement: () => [
        { value: 'especes', label: 'Espèces' },
        { value: 'virement', label: 'Virement' },
        { value: 'cheque', label: 'Chèque' },
        { value: 'mobile_money', label: 'Mobile Money' },
    ],
});

const emit = defineEmits<{
    (e: 'update:visible', val: boolean): void;
    (e: 'submit', payload: { montant: number; mode_paiement: string }): void;
}>();

// Proxy v-model:visible vers le parent sans mutation de prop
const localVisible = computed({
    get: () => props.visible,
    set: (val) => emit('update:visible', val),
});

const montant = ref<number | null>(null);
const modePaiement = ref('especes');

watch(
    () => props.visible,
    (open) => {
        if (open) {
            montant.value = props.solde > 0 ? props.solde : null;
            modePaiement.value = 'especes';
        }
    },
    { immediate: true },
);

function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}

function close() {
    emit('update:visible', false);
}

function handleSubmit() {
    if (!montant.value || montant.value <= 0) return;
    emit('submit', {
        montant: montant.value,
        mode_paiement: modePaiement.value,
    });
}
</script>

<template>
    <Dialog
        v-model:visible="localVisible"
        modal
        :header="title"
        :style="{ width: '420px' }"
        :draggable="false"
    >
        <div class="space-y-4 py-2">
            <!-- Bannière solde -->
            <div
                class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-300"
            >
                Solde à payer :
                <strong>{{ formatGNF(solde) }}</strong>
            </div>

            <!-- Montant -->
            <div>
                <Label class="mb-1.5 block text-sm">Montant (GNF)</Label>
                <InputNumber
                    v-model="montant"
                    :min="1"
                    :max="maxMontant ?? solde"
                    class="w-full"
                    input-class="w-full"
                />
                <p v-if="errors?.montant" class="mt-1 text-xs text-destructive">
                    {{ errors.montant }}
                </p>
            </div>

            <!-- Mode de paiement -->
            <div>
                <Label class="mb-1.5 block text-sm">Mode de paiement</Label>
                <Select
                    v-model="modePaiement"
                    :options="modesPaiement"
                    option-label="label"
                    option-value="value"
                    class="w-full"
                />
            </div>
        </div>

        <template #footer>
            <Button variant="outline" :disabled="processing" @click="close">
                Annuler
            </Button>
            <Button :disabled="processing || !montant" @click="handleSubmit">
                <HandCoins v-if="!processing" class="mr-1.5 h-4 w-4" />
                <span
                    v-else
                    class="mr-2 inline-block h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent"
                />
                Confirmer le paiement
            </Button>
        </template>
    </Dialog>
</template>
