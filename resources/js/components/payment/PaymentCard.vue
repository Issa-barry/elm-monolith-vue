<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { formatGNF } from '@/lib/utils';
import { HandCoins, Info, Receipt } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import Tooltip from 'primevue/tooltip';
import { computed, ref, watch } from 'vue';
import {
    construireOptions,
    type EncaissementPayload,
    type ModeOption,
    type MoyenEncaissement,
} from './moyensEncaissement';

const vTooltip = Tooltip;

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
    /** Moyens hors espèces disponibles dans l'agence de la facture, fournis par le backend
     * (`moyens_encaissement`, un par support de trésorerie actif) — jamais une liste fixe : un
     * opérateur sans support dans l'agence n'est pas proposé. Défaut vide : un écran qui l'oublierait
     * ne propose rien de plus que les espèces, jamais un moyen sans compte réel derrière. */
    moyens?: MoyenEncaissement[];
    /** L'utilisateur peut-il encaisser en espèces sur cette facture ? Fourni par le backend
     * (`peut_encaisser_especes`) : caisse dédiée active sur le site de la facture. Défaut `true` :
     * un écran qui l'oublierait ne masque rien, la garantie réelle reste côté serveur. */
    especesDisponibles?: boolean;
}

// Texte identique à CaisseAgentResolver::MESSAGE_SANS_CAISSE (renvoyé aussi par le backend si le
// bouton était contourné) — visible directement sous la liste, pas seulement au survol.
const MESSAGE_ESPECES_INDISPONIBLE =
    "Vous ne disposez pas d'une caisse active : impossible d'encaisser en espèces. Contactez votre responsable pour qu'il vous en crée une.";

// Une seule liste déroulante : Espèces, puis chaque support de l'agence (un Mobile Money par
// opérateur réellement configuré, virement/chèque par banque) — jamais un second select opérateur.
// Le choix envoie mode_paiement (4 valeurs stables attendues par la comptabilisation) + le support
// choisi (compte_tresorerie_id) ; l'opérateur est déduit du support côté serveur. Voir
// docs/encaissements.md.
const props = withDefaults(defineProps<Props>(), {
    infoRows: () => [],
    maxMontant: undefined,
    processing: false,
    errors: () => ({}),
    moyens: () => [],
    especesDisponibles: true,
});

const modeOptions = computed(() => construireOptions(props.moyens));

function modeIndisponible(mode: ModeOption): boolean {
    return !!mode.requiresCaisse && !props.especesDisponibles;
}

const especesBloquees = computed(() =>
    modeOptions.value.some((m) => modeIndisponible(m)),
);

const aucunAutreMoyen = computed(() => props.moyens.length === 0);

// Mode présélectionné à l'ouverture : le premier, sauf s'il est indisponible — jamais un autre mode
// choisi à la place de l'utilisateur (un Mobile Money enregistré par erreur serait un encaissement
// mal classé) : la liste reste vide et il choisit lui-même.
function modeInitial(): string {
    const premier = modeOptions.value[0];

    return premier && !modeIndisponible(premier) ? premier.key : '';
}

const emit = defineEmits<{
    (e: 'update:visible', val: boolean): void;
    (e: 'submit', payload: EncaissementPayload): void;
}>();

// Proxy v-model:visible vers le parent sans mutation de prop
const localVisible = computed({
    get: () => props.visible,
    set: (val) => emit('update:visible', val),
});

const montant = ref<number | null>(null);
const selectedKey = ref(modeInitial());
const referencePaiement = ref('');

function modeByKey(key: string): ModeOption | undefined {
    return modeOptions.value.find((m) => m.key === key);
}

const modeActif = computed(() => modeByKey(selectedKey.value));
const referencePaiementRequise = computed(
    () => modeActif.value?.requiresReference ?? false,
);
const referencePlaceholder = computed(
    () =>
        modeActif.value?.referencePlaceholder ??
        'Numéro ou référence de la transaction',
);

watch(
    () => props.visible,
    (open) => {
        if (open) {
            montant.value = props.solde > 0 ? props.solde : null;
            selectedKey.value = modeInitial();
            referencePaiement.value = '';
        }
    },
    { immediate: true },
);

// La disponibilité des espèces peut changer pendant que la fenêtre est ouverte (rafraîchissement
// des données de la page) : une sélection devenue impossible est retirée, jamais soumise.
watch(
    () => props.especesDisponibles,
    () => {
        const actif = modeActif.value;
        if (actif && modeIndisponible(actif)) {
            selectedKey.value = '';
        }
    },
);

// Effacer la référence dès qu'on quitte un mode qui l'exige, pour ne jamais soumettre une
// valeur devenue obsolète après un changement de mode dans la même ouverture.
watch(selectedKey, (key) => {
    if (!modeByKey(key)?.requiresReference) {
        referencePaiement.value = '';
    }
});

// Les montants pré-formatés reçus des pages parentes (infoRows) utilisent souvent l'espace fine
// U+202F de fr-FR, quasi invisible : on la remplace par une espace normale.
function espacer(texte: string): string {
    return texte.replace(/[  ]/g, ' ');
}

function close() {
    emit('update:visible', false);
}

function handleSubmit() {
    if (!montant.value || montant.value <= 0) return;
    const mode = modeActif.value;
    if (!mode || modeIndisponible(mode)) return;
    if (mode.requiresReference && !referencePaiement.value) return;
    emit('submit', {
        montant: montant.value,
        mode_paiement: mode.mode_paiement,
        compte_tresorerie_id: mode.compte_tresorerie_id,
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
                    <span class="font-medium text-foreground">{{
                        espacer(row.value)
                    }}</span>
                </div>
            </div>

            <!-- Montant dû — information principale du modal, à ne jamais confondre avec le
                 montant saisi ci-dessous (cf. docs/encaissements.md). -->
            <div
                class="flex items-center justify-between rounded-xl border border-primary/20 bg-primary/10 px-5 py-3"
            >
                <div>
                    <p class="text-sm font-semibold text-primary">Montant dû</p>
                    <p
                        class="mt-0.5 text-2xl font-extrabold tracking-tight text-primary tabular-nums [word-spacing:0.16em]"
                    >
                        {{ formatGNF(solde) }}
                    </p>
                </div>
                <div
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/15"
                >
                    <Receipt class="h-4 w-4 text-primary" />
                </div>
            </div>

            <!-- Montant à encaisser -->
            <div>
                <Label class="mb-2 flex items-center gap-1 text-sm font-medium"
                    >Montant (GNF)
                    <span class="text-destructive">*</span>
                    <Info
                        v-tooltip.top="
                            'Veuillez saisir le montant à encaisser en GNF.'
                        "
                        class="h-3.5 w-3.5 cursor-help text-muted-foreground"
                /></Label>
                <div class="relative">
                    <!-- fr-CA et non fr-FR : sépare les milliers par une espace insécable de largeur
                         normale (U+00A0) au lieu de l'espace fine U+202F, quasi invisible en gras. -->
                    <InputNumber
                        v-model="montant"
                        :min="1"
                        :max="maxMontant ?? solde"
                        :min-fraction-digits="0"
                        :max-fraction-digits="0"
                        :use-grouping="true"
                        locale="fr-CA"
                        class="w-full"
                        input-class="h-14 w-full pr-16 text-xl font-bold tabular-nums [word-spacing:0.19em]"
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

            <!-- Mode de paiement — une seule liste : espèces + supports de l'agence -->
            <div>
                <Label class="mb-1.5 block text-sm"
                    >Mode de paiement
                    <span class="text-destructive">*</span></Label
                >
                <Select
                    v-model="selectedKey"
                    :options="modeOptions"
                    option-label="label"
                    option-value="key"
                    :option-disabled="modeIndisponible"
                    placeholder="Choisir un mode de paiement"
                    class="w-full"
                    :class="{ 'p-invalid': errors?.mode_paiement }"
                >
                    <template #value="{ value }">
                        <div
                            v-if="modeByKey(value)"
                            class="flex items-center gap-2"
                        >
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
                        <span v-else class="text-muted-foreground"
                            >Choisir un mode de paiement</span
                        >
                    </template>
                    <template #option="{ option }">
                        <div class="flex items-center gap-2">
                            <span
                                class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md"
                                :class="option.badgeClass"
                            >
                                <component
                                    :is="option.icon"
                                    class="h-3.5 w-3.5"
                                />
                            </span>
                            <span>{{ option.label }}</span>
                            <span
                                v-if="modeIndisponible(option)"
                                class="text-xs text-muted-foreground"
                                >— caisse requise</span
                            >
                        </div>
                    </template>
                </Select>
                <!-- Espèces impossibles (aucune caisse dédiée active sur ce site) : message visible en
                     permanence sous la liste, pas seulement au survol. Ambre (attention, les autres
                     modes restent possibles) — jamais rouge : rien n'est bloqué pour eux. -->
                <p
                    v-if="especesBloquees"
                    class="mt-1.5 text-xs text-amber-700 dark:text-amber-400"
                    data-testid="especes-indisponible"
                >
                    {{ MESSAGE_ESPECES_INDISPONIBLE }}
                </p>
                <p
                    v-if="errors?.mode_paiement"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ errors.mode_paiement }}
                </p>
                <!-- Aucun support Mobile Money/Banque actif dans l'agence : information (bleu), rien
                     n'est cassé — c'est une configuration de Trésorerie > Supports. -->
                <p
                    v-if="aucunAutreMoyen"
                    class="mt-1.5 text-xs text-blue-700 dark:text-blue-400"
                    data-testid="aucun-autre-moyen"
                >
                    Aucun compte Mobile Money ni bancaire actif dans cette
                    agence : seules les espèces sont proposées.
                </p>
                <p
                    v-if="errors?.compte_tresorerie_id"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ errors.compte_tresorerie_id }}
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
                    !modeActif ||
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
