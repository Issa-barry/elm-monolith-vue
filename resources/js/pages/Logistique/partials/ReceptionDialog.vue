<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/vue3';
import { PackageCheck } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import { useToast } from 'primevue/usetoast';
import { computed, ref, watch } from 'vue';
import '../../../../css/logistique-mobile.css';

interface LigneProp {
    id: number;
    produit_nom: string;
    quantite_chargee: number | null;
    quantite_recue: number | null;
    /** Déjà revenu au site source (ADR 0029) — absent ou 0 sans retour. */
    quantite_retournee?: number;
    /** Plafond de réception une fois les retours déduits ; null sans retour (surplus permis). */
    quantite_recevable_max?: number | null;
    ecart_type: string | null;
    ecart_motif: string | null;
}

interface TypeEcartOption {
    value: string;
    label: string;
}

interface ReceptionLigne {
    id: number;
    produit_nom: string;
    quantite_chargee: number;
    quantite_retournee: number;
    /** Quantité attendue à destination : chargé − déjà revenu au site source. */
    quantite_attendue: number;
    quantite_recevable_max: number | null;
    quantite_recue: number;
    ecart_type: string;
    ecart_motif: string;
}

const props = defineProps<{
    visible: boolean;
    transfertId: number;
    lignes: LigneProp[];
    typesEcart: TypeEcartOption[];
}>();

const emit = defineEmits<{
    'update:visible': [value: boolean];
    confirmed: [];
}>();

const toast = useToast();
const processing = ref(false);
const dialogErrors = ref<string[]>([]);
const receptionLignes = ref<ReceptionLigne[]>([]);

watch(
    () => props.visible,
    (open) => {
        if (open) {
            dialogErrors.value = [];
            receptionLignes.value = props.lignes.map((l) => {
                const chargee = l.quantite_chargee ?? 0;
                const retournee = l.quantite_retournee ?? 0;
                const attendue = Math.max(0, chargee - retournee);
                const max = l.quantite_recevable_max ?? null;
                const recue = l.quantite_recue ?? attendue;

                return {
                    id: l.id,
                    produit_nom: l.produit_nom,
                    quantite_chargee: chargee,
                    quantite_retournee: retournee,
                    quantite_attendue: attendue,
                    quantite_recevable_max: max,
                    // Une saisie antérieure au retour (brouillon, réception invalidée) ne doit pas
                    // dépasser ce qui reste réceptionnable.
                    quantite_recue: max === null ? recue : Math.min(recue, max),
                    ecart_type: l.ecart_type ?? 'conforme',
                    ecart_motif: l.ecart_motif ?? '',
                };
            });
        }
    },
    { immediate: true },
);

const avecRetours = computed(() =>
    receptionLignes.value.some((l) => l.quantite_retournee > 0),
);

/** Écart = reçu + revenu au site source − chargé (ADR 0029) : un retour n'est ni manquant ni surplus. */
function ecartReception(idx: number): number {
    const l = receptionLignes.value[idx];
    return (l.quantite_recue ?? 0) - l.quantite_attendue;
}

function qualifierEcart(idx: number): void {
    const l = receptionLignes.value[idx];
    const ecart = ecartReception(idx);
    l.ecart_type =
        ecart === 0 ? 'conforme' : ecart < 0 ? 'manquant' : 'surplus';
}

function submit() {
    processing.value = true;
    dialogErrors.value = [];
    router.post(
        `/backoffice/logistique/${props.transfertId}/statut/avancer`,
        {
            lignes: receptionLignes.value.map((l) => ({
                id: l.id,
                quantite_recue: l.quantite_recue,
                ecart_type: l.ecart_type,
                ecart_motif: l.ecart_motif,
            })),
        },
        {
            onSuccess: () => {
                emit('update:visible', false);
                emit('confirmed');
                toast.add({
                    severity: 'success',
                    summary: 'Réception validée',
                    detail: 'Le transfert est maintenant réceptionné.',
                    life: 4000,
                });
            },
            onError: (errors) => {
                dialogErrors.value = Object.values(errors).flat() as string[];
            },
            onFinish: () => {
                processing.value = false;
            },
        },
    );
}
</script>

<template>
    <Dialog
        class="logistique-dialog"
        :visible="visible"
        modal
        header="Valider la réception"
        :style="{ width: 'min(1050px, 94vw)' }"
        :draggable="false"
        :resizable="false"
        @update:visible="emit('update:visible', $event)"
        @hide="dialogErrors = []"
    >
        <p class="mb-4 text-sm text-muted-foreground">
            Renseignez les quantités reçues et les écarts constatés à
            destination.
            <template v-if="avecRetours">
                La marchandise déjà revenue au site source est déduite : elle ne
                peut plus être réceptionnée.
            </template>
        </p>
        <div
            v-if="dialogErrors.length"
            class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 dark:border-red-800 dark:bg-red-950"
        >
            <p
                v-for="err in dialogErrors"
                :key="err"
                class="text-sm text-red-700 dark:text-red-400"
            >
                {{ err }}
            </p>
        </div>
        <table data-mobile-cards class="w-full text-sm">
            <colgroup>
                <col />
                <!-- Produit : flexible -->
                <col style="width: 90px" />
                <!-- Chargé -->
                <col v-if="avecRetours" style="width: 90px" />
                <!-- Retourné -->
                <col style="width: 130px" />
                <!-- Reçu -->
                <col style="width: 70px" />
                <!-- Écart -->
                <col style="width: 180px" />
                <!-- Type -->
                <col style="width: 220px" />
                <!-- Motif -->
            </colgroup>
            <thead>
                <tr class="border-b text-xs text-muted-foreground">
                    <th class="pb-3 text-left font-medium">Produit</th>
                    <th class="pb-3 text-center font-medium">Chargé</th>
                    <th v-if="avecRetours" class="pb-3 text-center font-medium">
                        Retourné
                    </th>
                    <th class="pb-3 text-center font-medium">Reçu</th>
                    <th class="pb-3 text-center font-medium">Écart</th>
                    <th class="px-2 pb-3 text-left font-medium">Type</th>
                    <th class="px-2 pb-3 text-left font-medium">Motif</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <tr
                    v-for="(l, idx) in receptionLignes"
                    :key="l.id"
                    class="align-middle"
                >
                    <td data-label="Produit" class="py-3 pr-4 font-medium">
                        {{ l.produit_nom }}
                    </td>
                    <td
                        data-label="Chargé"
                        class="py-3 text-center text-muted-foreground tabular-nums"
                    >
                        {{ l.quantite_chargee }}
                    </td>
                    <td
                        v-if="avecRetours"
                        data-label="Retourné"
                        class="py-3 text-center text-muted-foreground tabular-nums"
                    >
                        {{ l.quantite_retournee }}
                    </td>
                    <td data-label="Reçu" class="px-2 py-3">
                        <InputNumber
                            :input-id="`reception-qte-${l.id}`"
                            :aria-label="`Quantité reçue : ${l.produit_nom}`"
                            v-model="receptionLignes[idx].quantite_recue"
                            :min="0"
                            :max="l.quantite_recevable_max ?? undefined"
                            :use-grouping="false"
                            class="w-full"
                            input-class="w-full text-center"
                            @update:model-value="qualifierEcart(idx)"
                        />
                    </td>
                    <td
                        data-label="Écart"
                        class="py-3 text-center font-semibold tabular-nums"
                        :class="
                            ecartReception(idx) === 0
                                ? 'text-muted-foreground'
                                : ecartReception(idx) < 0
                                  ? 'text-red-600'
                                  : 'text-amber-600'
                        "
                    >
                        {{ ecartReception(idx) > 0 ? '+' : ''
                        }}{{ ecartReception(idx) }}
                    </td>
                    <td data-label="Type" data-wide class="px-2 py-3">
                        <Select
                            v-model="receptionLignes[idx].ecart_type"
                            :options="typesEcart"
                            option-label="label"
                            option-value="value"
                            append-to="self"
                            :overlay-style="{
                                width: '100%',
                                maxWidth: 'calc(100vw - 2rem)',
                            }"
                            :pt="{
                                optionLabel: {
                                    class: 'whitespace-normal [overflow-wrap:anywhere]',
                                },
                            }"
                            class="w-full"
                        />
                    </td>
                    <td data-label="Motif" data-wide class="px-2 py-3">
                        <InputText
                            :aria-label="`Motif : ${l.produit_nom}`"
                            v-model="receptionLignes[idx].ecart_motif"
                            placeholder="Motif (optionnel)…"
                            class="w-full"
                        />
                    </td>
                </tr>
            </tbody>
        </table>
        <template #footer>
            <Button
                variant="outline"
                :disabled="processing"
                @click="emit('update:visible', false)"
                >Annuler</Button
            >
            <Button :disabled="processing" @click="submit">
                <PackageCheck v-if="!processing" class="mr-2 h-4 w-4" />
                <span
                    v-if="processing"
                    class="mr-2 inline-block h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent"
                />
                {{ processing ? 'Validation…' : 'Valider la réception' }}
            </Button>
        </template>
    </Dialog>
</template>
