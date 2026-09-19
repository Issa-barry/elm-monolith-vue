<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    FileText,
    HandCoins,
    Info,
    Landmark,
    type LucideIcon,
    Receipt,
    Smartphone,
    Wallet,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import Tooltip from 'primevue/tooltip';
import { computed, ref, watch } from 'vue';

const vTooltip = Tooltip;

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
    icon: LucideIcon;
    /** Classes Tailwind du badge icône (fond + couleur) — juste pour distinguer visuellement
     * les options entre elles, pas un logo de marque. */
    badgeClass: string;
    /** Exemple affiché en placeholder du champ Référence, propre à chaque mode. */
    referencePlaceholder?: string;
}

interface InfoRow {
    label: string;
    value: string;
}

interface Props {
    visible: boolean;
    title: string;
    /** Montant affiché dans le bloc "Montant dû" + valeur max par défaut */
    solde: number;
    /** Lignes de contexte optionnelles affichées au-dessus du bloc Montant dû (ex: référence commande) */
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
// Icônes/couleurs par option : simples repères visuels (pas des logos de marque officiels).
const DEFAULT_MODES: ModeOption[] = [
    {
        key: 'especes',
        label: 'Espèces',
        mode_paiement: 'especes',
        requiresReference: false,
        icon: Wallet,
        badgeClass: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
    },
    {
        key: 'orange_money',
        label: 'Orange Money',
        mode_paiement: 'mobile_money',
        operateur_mobile_money: 'orange_money',
        requiresReference: true,
        icon: Smartphone,
        badgeClass: 'bg-orange-100 text-orange-600 dark:bg-orange-900/40 dark:text-orange-300',
        referencePlaceholder: 'Ex. OM123456789',
    },
    {
        key: 'kulu',
        label: 'Kulu',
        mode_paiement: 'mobile_money',
        operateur_mobile_money: 'kulu',
        requiresReference: true,
        icon: Smartphone,
        badgeClass: 'bg-sky-100 text-sky-600 dark:bg-sky-900/40 dark:text-sky-300',
        referencePlaceholder: 'Ex. KU123456789',
    },
    {
        key: 'soutra_money',
        label: 'Soutra Money',
        mode_paiement: 'mobile_money',
        operateur_mobile_money: 'soutra_money',
        requiresReference: true,
        icon: Smartphone,
        badgeClass: 'bg-cyan-100 text-cyan-600 dark:bg-cyan-900/40 dark:text-cyan-300',
        referencePlaceholder: 'Ex. SM123456789',
    },
    {
        key: 'momo',
        label: 'MOMO (MTN Mobile Money)',
        mode_paiement: 'mobile_money',
        operateur_mobile_money: 'momo',
        requiresReference: true,
        icon: Smartphone,
        badgeClass: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300',
        referencePlaceholder: 'Ex. MTN123456789',
    },
    {
        key: 'paycard',
        label: 'PayCard',
        mode_paiement: 'mobile_money',
        operateur_mobile_money: 'paycard',
        requiresReference: true,
        icon: Smartphone,
        badgeClass: 'bg-violet-100 text-violet-600 dark:bg-violet-900/40 dark:text-violet-300',
        referencePlaceholder: 'Ex. PC123456789',
    },
    {
        key: 'virement',
        label: 'Virement bancaire',
        mode_paiement: 'virement',
        requiresReference: true,
        icon: Landmark,
        badgeClass: 'bg-blue-100 text-blue-600 dark:bg-blue-900/40 dark:text-blue-300',
        referencePlaceholder: 'Ex. VIR20260915001',
    },
    {
        key: 'cheque',
        label: 'Chèque',
        mode_paiement: 'cheque',
        requiresReference: false,
        icon: FileText,
        badgeClass: 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
    },
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

function modeByKey(key: string): ModeOption | undefined {
    return modeOptions.value.find((m) => m.key === key);
}

const modeActif = computed(() => modeByKey(selectedKey.value));
const referencePaiementRequise = computed(() => modeActif.value?.requiresReference ?? false);
const referencePlaceholder = computed(
    () => modeActif.value?.referencePlaceholder ?? 'Numéro ou référence de la transaction',
);

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
    if (!modeByKey(key)?.requiresReference) {
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
        :style="{ width: '460px' }"
        :draggable="false"
    >
        <div class="space-y-6 py-2">
            <!-- Contexte optionnel (ex: référence commande, montant total) — secondaire, ne doit
                 jamais rivaliser visuellement avec le montant dû ci-dessous. -->
            <div
                v-if="infoRows.length"
                class="space-y-1 text-sm text-muted-foreground"
            >
                <div
                    v-for="row in infoRows"
                    :key="row.label"
                    class="flex justify-between"
                >
                    <span>{{ row.label }}</span>
                    <span class="font-medium text-foreground">{{ row.value }}</span>
                </div>
            </div>

            <!-- Montant dû — information principale du modal, à ne jamais confondre avec le
                 montant saisi ci-dessous (cf. docs/encaissements.md). -->
            <div
                class="flex items-center justify-between rounded-xl border border-orange-200 bg-orange-50 px-5 py-4 dark:border-orange-900 dark:bg-orange-950/30"
            >
                <div>
                    <p class="text-sm font-semibold text-orange-600 dark:text-orange-400">
                        Montant dû
                    </p>
                    <p
                        class="mt-1 text-3xl font-extrabold tracking-tight tabular-nums text-orange-700 dark:text-orange-300"
                    >
                        {{ formatGNF(solde) }}
                    </p>
                </div>
                <div
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-orange-100 dark:bg-orange-900/50"
                >
                    <Receipt class="h-5 w-5 text-orange-600 dark:text-orange-300" />
                </div>
            </div>

            <!-- Montant à encaisser -->
            <div>
                <Label class="mb-2 flex items-center gap-1 text-sm font-medium"
                    >Montant (GNF)
                    <span class="text-destructive">*</span>
                    <Info
                        v-tooltip.top="'Veuillez saisir le montant à encaisser en GNF.'"
                        class="h-3.5 w-3.5 cursor-help text-muted-foreground"
                    /></Label
                >
                <div class="relative">
                    <InputNumber
                        v-model="montant"
                        :min="1"
                        :max="maxMontant ?? solde"
                        :min-fraction-digits="0"
                        :max-fraction-digits="0"
                        :use-grouping="true"
                        locale="fr-FR"
                        class="w-full"
                        input-class="h-14 w-full pr-16 text-2xl font-bold tabular-nums"
                        :class="{ 'p-invalid': errors?.montant }"
                    />
                    <span
                        class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 rounded-md border border-border bg-muted px-2.5 py-1 text-sm font-semibold text-muted-foreground"
                    >
                        GNF
                    </span>
                </div>
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
                >
                    <template #value="{ value }">
                        <div v-if="modeByKey(value)" class="flex items-center gap-2">
                            <span
                                class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md"
                                :class="modeByKey(value)!.badgeClass"
                            >
                                <component
                                    :is="modeByKey(value)!.icon"
                                    class="h-3.5 w-3.5"
                                />
                            </span>
                            <span>{{ modeByKey(value)!.label }}</span>
                        </div>
                    </template>
                    <template #option="{ option }">
                        <div class="flex items-center gap-2">
                            <span
                                class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md"
                                :class="option.badgeClass"
                            >
                                <component :is="option.icon" class="h-3.5 w-3.5" />
                            </span>
                            <span>{{ option.label }}</span>
                        </div>
                    </template>
                </Select>
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
                    :placeholder="referencePlaceholder"
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
