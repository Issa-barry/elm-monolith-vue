<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/vue3';
import { PackageCheck } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import { useToast } from 'primevue/usetoast';
import { ref, watch } from 'vue';

interface LigneProp {
    id: number;
    produit_nom: string;
    quantite_chargee: number | null;
    quantite_recue: number | null;
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
            receptionLignes.value = props.lignes.map((l) => ({
                id: l.id,
                produit_nom: l.produit_nom,
                quantite_chargee: l.quantite_chargee ?? 0,
                quantite_recue: l.quantite_recue ?? l.quantite_chargee ?? 0,
                ecart_type: l.ecart_type ?? 'conforme',
                ecart_motif: l.ecart_motif ?? '',
            }));
        }
    },
    { immediate: true },
);

function ecartReception(idx: number): number {
    const l = receptionLignes.value[idx];
    return (l.quantite_recue ?? 0) - (l.quantite_chargee ?? 0);
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
        :visible="visible"
        modal
        header="Valider la réception"
        :style="{ width: 'min(1050px, 94vw)' }"
        :draggable="true"
        :resizable="false"
        @update:visible="emit('update:visible', $event)"
        @hide="dialogErrors = []"
    >
        <p class="mb-4 text-sm text-muted-foreground">
            Renseignez les quantités reçues et les écarts constatés à
            destination.
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
        <table class="w-full text-sm">
            <colgroup>
                <col />
                <!-- Produit : flexible -->
                <col style="width: 90px" />
                <!-- Chargé -->
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
                    <td class="py-3 pr-4 font-medium">
                        {{ l.produit_nom }}
                    </td>
                    <td
                        class="py-3 text-center text-muted-foreground tabular-nums"
                    >
                        {{ l.quantite_chargee }}
                    </td>
                    <td class="px-2 py-3">
                        <InputNumber
                            v-model="receptionLignes[idx].quantite_recue"
                            :min="0"
                            :use-grouping="false"
                            class="w-full"
                            input-class="w-full text-center"
                            @update:model-value="
                                () => {
                                    if (
                                        receptionLignes[idx].quantite_recue ===
                                        l.quantite_chargee
                                    )
                                        receptionLignes[idx].ecart_type =
                                            'conforme';
                                    else if (
                                        (receptionLignes[idx].quantite_recue ??
                                            0) < (l.quantite_chargee ?? 0)
                                    )
                                        receptionLignes[idx].ecart_type =
                                            'manquant';
                                    else
                                        receptionLignes[idx].ecart_type =
                                            'surplus';
                                }
                            "
                        />
                    </td>
                    <td
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
                    <td class="px-2 py-3">
                        <Dropdown
                            v-model="receptionLignes[idx].ecart_type"
                            :options="typesEcart"
                            option-label="label"
                            option-value="value"
                            class="w-full"
                        />
                    </td>
                    <td class="px-2 py-3">
                        <InputText
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
