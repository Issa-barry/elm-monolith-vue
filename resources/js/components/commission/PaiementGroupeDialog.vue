<script setup lang="ts">
import {
    construireOptions,
    type ModeOption,
} from '@/components/payment/moyensEncaissement';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { PaiementGroupePayload } from '@/composables/usePaiementGroupe';
import { formatGNF } from '@/lib/utils';
import type { FicheAPayer } from '@/types/commission';
import { HandCoins } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import { computed, ref, watch } from 'vue';

/**
 * Paiement groupé (ADR 0068) : marque comme payées des fiches déjà réglées hors application, pour
 * leur reste entier, depuis un seul moyen de l'agence qui paie. Le serveur revérifie tout
 * (droits, période, reste, solde, référence) ; cette fenêtre évite seulement un envoi voué à l'échec.
 */
const props = withDefaults(
    defineProps<{
        visible: boolean;
        fiches: FicheAPayer[];
        processing?: boolean;
        progression?: number;
        erreur?: string | null;
    }>(),
    { processing: false, progression: 0, erreur: null },
);

const emit = defineEmits<{
    (e: 'update:visible', val: boolean): void;
    (e: 'submit', payload: PaiementGroupePayload): void;
}>();

const localVisible = computed({
    get: () => props.visible,
    set: (val) => {
        // Jamais fermée pendant l'envoi : les lots en cours doivent finir.
        if (!props.processing) emit('update:visible', val);
    },
});

const lignes = computed(() =>
    [...props.fiches].sort((a, b) =>
        a.beneficiaire_nom.localeCompare(b.beneficiaire_nom, 'fr'),
    ),
);

const total = computed(() =>
    props.fiches.reduce((somme, f) => somme + f.montant_restant, 0),
);

// Un seul compte débité : toutes les fiches doivent sortir de la même agence.
const plusieursAgences = computed(
    () => new Set(props.fiches.map((f) => f.tresorerie.site_id)).size > 1,
);
const refusTresorerie = computed(
    () => props.fiches.find((f) => f.tresorerie.message)?.tresorerie.message,
);
const tresorerie = computed(() => props.fiches[0]?.tresorerie ?? null);

const modeOptions = computed<ModeOption[]>(() =>
    construireOptions(tresorerie.value?.moyens ?? []).map((option) =>
        option.requiresCaisse
            ? {
                  ...option,
                  soldeDisponible: tresorerie.value?.solde_especes ?? null,
              }
            : option,
    ),
);

function modeIndisponible(mode: ModeOption): boolean {
    return !!mode.requiresCaisse && !tresorerie.value?.especes_disponibles;
}

const selectedKey = ref('');
const modeActif = computed(() =>
    modeOptions.value.find((m) => m.key === selectedKey.value),
);
const soldeSupport = computed(() => modeActif.value?.soldeDisponible ?? null);
const soldeInsuffisant = computed(
    () =>
        soldeSupport.value !== null && total.value > soldeSupport.value + 0.004,
);
const referenceRequise = computed(
    () => modeActif.value?.requiresReference ?? false,
);

const datePaiement = ref(aujourdhui());
const referenceCommune = ref('');
const references = ref<Record<string, string>>({});

const lignesSansReference = computed(() =>
    referenceRequise.value && !referenceCommune.value.trim()
        ? props.fiches.filter((f) => !references.value[f.id]?.trim()).length
        : 0,
);

const bloque = computed(
    () => plusieursAgences.value || !!refusTresorerie.value,
);

const peutEnvoyer = computed(
    () =>
        !props.processing &&
        !bloque.value &&
        !!modeActif.value &&
        !modeIndisponible(modeActif.value) &&
        !soldeInsuffisant.value &&
        lignesSansReference.value === 0 &&
        !!datePaiement.value &&
        datePaiement.value <= aujourdhui(),
);

watch(
    () => props.visible,
    (open) => {
        if (!open) return;
        const premier = modeOptions.value[0];
        selectedKey.value =
            premier && !modeIndisponible(premier) ? premier.key : '';
        datePaiement.value = aujourdhui();
        referenceCommune.value = '';
        references.value = {};
    },
    { immediate: true },
);

function submit() {
    const mode = modeActif.value;
    if (!peutEnvoyer.value || !mode) return;
    emit('submit', {
        mode_paiement: mode.mode_paiement,
        compte_tresorerie_id: mode.compte_tresorerie_id,
        reference_paiement: referenceRequise.value
            ? referenceCommune.value.trim() || undefined
            : undefined,
        date_paiement: datePaiement.value,
        references: referenceRequise.value ? references.value : {},
    });
}

/** « P2 » d'une référence « PAY-202609-P2-LIV » (M pour une période mensuelle). */
function codePeriode(reference: string | null): string {
    return reference?.match(/-(P1|P2|M)(?:-|$)/)?.[1] ?? '—';
}

/** Date locale (pas UTC) : un paiement saisi à 00h30 à Conakry reste daté du jour. */
function aujourdhui(): string {
    const d = new Date();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const jj = String(d.getDate()).padStart(2, '0');
    return `${d.getFullYear()}-${mm}-${jj}`;
}
</script>

<template>
    <Dialog
        v-model:visible="localVisible"
        modal
        header="Marquer comme payés"
        :closable="!processing"
        :style="{ width: '640px', maxWidth: 'calc(100vw - 2rem)' }"
        :draggable="false"
    >
        <div class="space-y-5 py-1" data-testid="paiement-groupe">
            <p class="text-sm text-muted-foreground">
                Enregistre le paiement du reste de chaque fiche, sorti du compte
                choisi. À utiliser une fois les dépôts effectués.
            </p>

            <div
                class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 rounded-lg bg-muted px-4 py-3"
            >
                <p class="text-sm font-medium text-foreground">
                    {{ fiches.length }} bénéficiaire{{
                        fiches.length > 1 ? 's' : ''
                    }}
                </p>
                <p
                    class="text-xl font-bold tracking-tight text-foreground tabular-nums"
                    data-testid="paiement-groupe-total"
                >
                    {{ formatGNF(total) }}
                </p>
            </div>

            <p
                v-if="plusieursAgences"
                class="rounded-md border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm leading-relaxed text-destructive"
                data-testid="paiement-groupe-agences"
            >
                La sélection est payée par plusieurs agences : un paiement
                groupé sort d'un seul compte. Filtrez par agence, puis payez
                agence par agence.
            </p>
            <p
                v-else-if="refusTresorerie"
                class="rounded-md border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm leading-relaxed text-destructive"
            >
                {{ refusTresorerie }}
            </p>

            <template v-if="!bloque">
                <div class="grid gap-4 sm:grid-cols-2">
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
                            :disabled="processing"
                        >
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
                        <p
                            v-if="soldeSupport !== null"
                            class="mt-1.5 text-xs"
                            :class="
                                soldeInsuffisant
                                    ? 'text-destructive'
                                    : 'text-muted-foreground'
                            "
                            data-testid="paiement-groupe-solde"
                        >
                            <template v-if="soldeInsuffisant">
                                Solde insuffisant :
                                {{ formatGNF(soldeSupport) }} disponible.
                            </template>
                            <template v-else>
                                Disponible : {{ formatGNF(soldeSupport) }}
                            </template>
                        </p>
                    </div>
                    <div>
                        <Label
                            for="paiement-groupe-date"
                            class="mb-1.5 block text-sm"
                            >Date de paiement
                            <span class="text-destructive">*</span></Label
                        >
                        <input
                            id="paiement-groupe-date"
                            v-model="datePaiement"
                            type="date"
                            :max="aujourdhui()"
                            :disabled="processing"
                            class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                        />
                    </div>
                </div>

                <div v-if="referenceRequise">
                    <Label
                        for="paiement-groupe-reference"
                        class="mb-1.5 block text-sm"
                        >Référence commune</Label
                    >
                    <InputText
                        id="paiement-groupe-reference"
                        v-model="referenceCommune"
                        :placeholder="
                            modeActif?.referencePlaceholder ??
                            'Numéro ou référence de la transaction'
                        "
                        class="w-full"
                        :disabled="processing"
                    />
                    <p class="mt-1 text-xs text-muted-foreground">
                        Appliquée à chaque ligne sans référence propre.
                    </p>
                </div>

                <div class="max-h-72 overflow-auto rounded-lg border">
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 bg-muted">
                            <tr>
                                <th
                                    class="px-3 py-2 text-left font-semibold text-foreground/70"
                                >
                                    Bénéficiaire
                                </th>
                                <th
                                    class="px-3 py-2 text-left font-semibold text-foreground/70"
                                >
                                    Période
                                </th>
                                <th
                                    class="px-3 py-2 text-right font-semibold text-foreground/70"
                                >
                                    Reste à payer
                                </th>
                                <th
                                    v-if="referenceRequise"
                                    class="px-3 py-2 text-left font-semibold text-foreground/70"
                                >
                                    Référence
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="f in lignes" :key="f.id">
                                <td class="px-3 py-2 font-medium">
                                    {{ f.beneficiaire_nom }}
                                </td>
                                <td class="px-3 py-2 text-muted-foreground">
                                    {{ codePeriode(f.periode_reference) }}
                                </td>
                                <td
                                    class="px-3 py-2 text-right whitespace-nowrap tabular-nums"
                                >
                                    {{ formatGNF(f.montant_restant) }}
                                </td>
                                <td v-if="referenceRequise" class="px-3 py-1.5">
                                    <InputText
                                        v-model="references[f.id]"
                                        :placeholder="
                                            referenceCommune || 'Référence'
                                        "
                                        :aria-label="`Référence pour ${f.beneficiaire_nom}`"
                                        class="h-8 w-full text-sm"
                                        :disabled="processing"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p
                    v-if="lignesSansReference > 0"
                    class="text-xs text-amber-700 dark:text-amber-400"
                    data-testid="paiement-groupe-references"
                >
                    Référence obligatoire : saisissez une référence commune ou
                    une référence pour chacune des {{ lignesSansReference }}
                    ligne(s) restante(s).
                </p>
            </template>

            <p
                v-if="erreur"
                class="rounded-md border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm leading-relaxed text-destructive"
                data-testid="paiement-groupe-erreur"
            >
                Aucun paiement enregistré : {{ erreur }}
            </p>
        </div>

        <template #footer>
            <div class="flex w-full items-center justify-between gap-3">
                <Button
                    variant="outline"
                    :disabled="processing"
                    @click="localVisible = false"
                >
                    Annuler
                </Button>
                <Button
                    :disabled="!peutEnvoyer"
                    :aria-busy="processing"
                    data-testid="paiement-groupe-confirmer"
                    @click="submit"
                >
                    <template v-if="processing">
                        <Spinner class="mr-1.5 h-4 w-4" />
                        Enregistrement… {{ progression }} %
                    </template>
                    <template v-else>
                        <HandCoins class="mr-1.5 h-4 w-4" />
                        {{
                            fiches.length > 1
                                ? `Marquer les ${fiches.length} comme payés`
                                : 'Marquer comme payé'
                        }}
                    </template>
                </Button>
            </div>
        </template>
    </Dialog>
</template>
