<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { HandCoins } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import { computed, ref, watch } from 'vue';

interface ModeOption {
    /** Clé de l'option dans le select — PAS forcément égale à mode_paiement (cf. Mobile Money). */
    key: string;
    label: string;
    /** Valeur envoyée au backend dans `mode_paiement` (App\Enums\ModePaiement, 4 valeurs stables :
     * especes/mobile_money/virement/cheque — jamais un opérateur, cf. docs/encaissements.md). */
    mode_paiement: string;
    /** Envoyé dans `operateur_mobile_money` uniquement quand mode_paiement = mobile_money. */
    operateur_mobile_money?: string;
    requiresReference: boolean;
}

interface InfoRow {
    label: string;
    value: string;
}

interface Props {
    visible: boolean;
    title: string;
    /** Montant affiché dans la bannière "Restant dû" + valeur max par défaut */
    solde: number;
    /** Lignes de contexte optionnelles affichées au-dessus du solde (ex: référence commande) */
    infoRows?: InfoRow[];
    /** Surcharge du max InputNumber si différent du solde */
    maxMontant?: number;
    processing?: boolean;
    errors?: Record<string, string>;
    modes?: ModeOption[];
}

// Une seule liste déroulante "Mode de paiement" — l'opérateur Mobile Money (Orange Money, Kulu,
// Soutra Money, MOMO, PayCard) apparaît comme option directe, jamais comme un second select.
// Sous le capot, chaque option Mobile Money envoie mode_paiement="mobile_money" +
// operateur_mobile_money="<opérateur>" — mode_paiement reste l'une des 4 valeurs stables
// attendues par la comptabilisation (App\Services\Comptabilite\VenteComptabilisationService,
// PlanComptableBootstrapService, CompteMappingResolver) : y stocker directement "orange_money"
// ferait retomber l'écriture sur le compte de trésorerie par défaut (Caisse) au lieu du compte
// Mobile Money dédié — montant mal classé en comptabilité. Voir docs/encaissements.md.
const DEFAULT_MODES: ModeOption[] = [
    { key: 'especes', label: 'Espèces', mode_paiement: 'especes', requiresReference: false },
    {
        key: 'orange_money',
        label: 'Orange Money',
        mode_paiement: 'mobile_money',
        operateur_mobile_money: 'orange_money',
        requiresReference: true,
    },
    {
        key: 'kulu',
        label: 'Kulu',
        mode_paiement: 'mobile_money',
        operateur_mobile_money: 'kulu',
        requiresReference: true,
    },
    {
        key: 'soutra_money',
        label: 'Soutra Money',
        mode_paiement: 'mobile_money',
        operateur_mobile_money: 'soutra_money',
        requiresReference: true,
    },
    {
        key: 'momo',
        label: 'MOMO (MTN Mobile Money)',
        mode_paiement: 'mobile_money',
        operateur_mobile_money: 'momo',
        requiresReference: true,
    },
    {
        key: 'paycard',
        label: 'PayCard',
        mode_paiement: 'mobile_money',
        operateur_mobile_money: 'paycard',
        requiresReference: true,
    },
    { key: 'virement', label: 'Virement bancaire', mode_paiement: 'virement', requiresReference: true },
    { key: 'cheque', label: 'Chèque', mode_paiement: 'cheque', requiresReference: false },
];

// `defineProps()` est hissé hors du setup() par le compilateur : sa valeur par défaut ne peut
// pas référencer une variable locale du module (DEFAULT_MODES) — d'où le fallback via un
// computed séparé (modeOptions) plutôt que dans withDefaults.
const props = withDefaults(defineProps<Props>(), {
    infoRows: () => [],
    maxMontant: undefined,
    processing: false,
    errors: () => ({}),
});

const modeOptions = computed(() => props.modes ?? DEFAULT_MODES);

const emit = defineEmits<{
    (e: 'update:visible', val: boolean): void;
    (
        e: 'submit',
        payload: {
            montant: number;
            mode_paiement: string;
            operateur_mobile_money?: string;
            reference_paiement?: string;
        },
    ): void;
}>();

// Proxy v-model:visible vers le parent sans mutation de prop
const localVisible = computed({
    get: () => props.visible,
    set: (val) => emit('update:visible', val),
});

const montant = ref<number | null>(null);
const selectedKey = ref(modeOptions.value[0]?.key ?? 'especes');
const referencePaiement = ref('');

const modeActif = computed(() => modeOptions.value.find((m) => m.key === selectedKey.value));
const referencePaiementRequise = computed(() => modeActif.value?.requiresReference ?? false);

watch(
    () => props.visible,
    (open) => {
        if (open) {
            montant.value = props.solde > 0 ? props.solde : null;
            selectedKey.value = modeOptions.value[0]?.key ?? 'especes';
            referencePaiement.value = '';
        }
    },
    { immediate: true },
);

// Effacer la référence dès qu'on quitte un mode qui l'exige, pour ne jamais soumettre une
// valeur devenue obsolète après un changement de mode dans la même ouverture.
watch(selectedKey, (key) => {
    const requiert = modeOptions.value.find((m) => m.key === key)?.requiresReference;
    if (!requiert) {
        referencePaiement.value = '';
    }
});

function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}

function close() {
    emit('update:visible', false);
}

function handleSubmit() {
    if (!montant.value || montant.value <= 0) return;
    const mode = modeActif.value;
    if (!mode) return;
    if (mode.requiresReference && !referencePaiement.value) return;
    emit('submit', {
        montant: montant.value,
        mode_paiement: mode.mode_paiement,
        operateur_mobile_money: mode.operateur_mobile_money,
        reference_paiement: referencePaiement.value || undefined,
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
            <!-- Bannière contexte + solde -->
            <div
                class="space-y-1.5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-300"
            >
                <div
                    v-for="row in infoRows"
                    :key="row.label"
                    class="flex justify-between"
                >
                    <span>{{ row.label }}</span>
                    <span class="font-semibold">{{ row.value }}</span>
                </div>
                <div
                    class="flex justify-between"
                    :class="{ 'border-t border-amber-200 pt-1.5 dark:border-amber-800': infoRows.length }"
                >
                    <span>Restant dû</span>
                    <strong>{{ formatGNF(solde) }}</strong>
                </div>
            </div>

            <!-- Montant -->
            <div>
                <Label class="mb-1.5 block text-sm"
                    >Montant (GNF) <span class="text-destructive">*</span></Label
                >
                <InputNumber
                    v-model="montant"
                    :min="1"
                    :max="maxMontant ?? solde"
                    class="w-full"
                    input-class="w-full"
                    :class="{ 'p-invalid': errors?.montant }"
                />
                <p v-if="errors?.montant" class="mt-1 text-xs text-destructive">
                    {{ errors.montant }}
                </p>
            </div>

            <!-- Mode de paiement — une seule liste, l'opérateur Mobile Money en fait partie -->
            <div>
                <Label class="mb-1.5 block text-sm"
                    >Mode de paiement <span class="text-destructive">*</span></Label
                >
                <Select
                    v-model="selectedKey"
                    :options="modeOptions"
                    option-label="label"
                    option-value="key"
                    class="w-full"
                    :class="{ 'p-invalid': errors?.mode_paiement }"
                />
                <p v-if="errors?.mode_paiement" class="mt-1 text-xs text-destructive">
                    {{ errors.mode_paiement }}
                </p>
                <p
                    v-if="errors?.operateur_mobile_money"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ errors.operateur_mobile_money }}
                </p>
            </div>

            <!-- Référence de paiement -->
            <div v-if="referencePaiementRequise">
                <Label class="mb-1.5 block text-sm"
                    >Référence de transaction
                    <span class="text-destructive">*</span></Label
                >
                <InputText
                    v-model="referencePaiement"
                    class="w-full"
                    :class="{ 'p-invalid': errors?.reference_paiement }"
                />
                <p
                    v-if="errors?.reference_paiement"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ errors.reference_paiement }}
                </p>
            </div>
        </div>

        <template #footer>
            <Button variant="outline" :disabled="processing" @click="close">
                Annuler
            </Button>
            <Button
                :disabled="
                    processing ||
                    !montant ||
                    (referencePaiementRequise && !referencePaiement)
                "
                @click="handleSubmit"
            >
                <HandCoins v-if="!processing" class="mr-1.5 h-4 w-4" />
                <span
                    v-else
                    class="mr-2 inline-block h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent"
                />
                Confirmer
            </Button>
        </template>
    </Dialog>
</template>
