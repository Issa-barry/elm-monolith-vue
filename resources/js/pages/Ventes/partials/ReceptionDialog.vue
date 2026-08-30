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
    id: string;
    produit_nom: string | null;
    quantite_chargee: number | null;
    quantite_demandee: number;
    quantite_livree: number | null;
    type_ecart_reception: string | null;
    commentaire_ecart_reception: string | null;
}

interface TypeEcartOption {
    value: string;
    label: string;
}

interface ReceptionLigne {
    id: string;
    produit_nom: string;
    quantite_chargee: number;
    quantite_livree: number;
    type_ecart_reception: string;
    commentaire_ecart_reception: string;
}

const props = defineProps<{
    visible: boolean;
    commandeId: string;
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
                const chargee = l.quantite_chargee ?? l.quantite_demandee;

                return {
                    id: l.id,
                    produit_nom: l.produit_nom ?? '—',
                    quantite_chargee: chargee,
                    quantite_livree: l.quantite_livree ?? chargee,
                    type_ecart_reception: l.type_ecart_reception ?? 'conforme',
                    commentaire_ecart_reception:
                        l.commentaire_ecart_reception ?? '',
                };
            });
        }
    },
    { immediate: true },
);

function ecartReception(idx: number): number {
    const l = receptionLignes.value[idx];
    return (l.quantite_livree ?? 0) - (l.quantite_chargee ?? 0);
}

function onQuantiteChanged(idx: number): void {
    const ecart = ecartReception(idx);
    if (ecart === 0) {
        receptionLignes.value[idx].type_ecart_reception = 'conforme';
    } else if (ecart < 0) {
        receptionLignes.value[idx].type_ecart_reception = 'manquant';
    } else {
        receptionLignes.value[idx].type_ecart_reception = 'surplus';
    }
}

function submit(): void {
    processing.value = true;
    dialogErrors.value = [];

    router.post(
        `/backoffice/ventes/${props.commandeId}/statut/avancer`,
        {
            lignes: receptionLignes.value.map((l) => ({
                id: l.id,
                quantite_livree: l.quantite_livree,
                type_ecart_reception: l.type_ecart_reception,
                commentaire_ecart_reception:
                    l.commentaire_ecart_reception || null,
            })),
        },
        {
            onSuccess: () => {
                emit('update:visible', false);
                emit('confirmed');
                toast.add({
                    severity: 'success',
                    summary: 'Réception validée',
                    detail: 'La commande est maintenant livrée.',
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
            Renseignez les quantités effectivement acceptées par le
            distributeur et les écarts constatés. La facture sera recalculée
            sur la base de ces quantités — le client n'est jamais facturé
            au-delà de ce qu'il a accepté.
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
                <!-- Chargée -->
                <col style="width: 130px" />
                <!-- Reçue -->
                <col style="width: 70px" />
                <!-- Écart -->
                <col style="width: 180px" />
                <!-- Type -->
                <col style="width: 220px" />
                <!-- Commentaire -->
            </colgroup>
            <thead>
                <tr class="border-b text-xs text-muted-foreground">
                    <th class="pb-3 text-left font-medium">Produit</th>
                    <th class="pb-3 text-center font-medium">Chargée</th>
                    <th class="pb-3 text-center font-medium">Reçue</th>
                    <th class="pb-3 text-center font-medium">Écart</th>
                    <th class="px-2 pb-3 text-left font-medium">Type</th>
                    <th class="px-2 pb-3 text-left font-medium">Commentaire</th>
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
                            v-model="receptionLignes[idx].quantite_livree"
                            :min="0"
                            :use-grouping="false"
                            class="w-full"
                            input-class="w-full text-center"
                            @update:model-value="onQuantiteChanged(idx)"
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
                            v-model="
                                receptionLignes[idx].type_ecart_reception
                            "
                            :options="typesEcart"
                            option-label="label"
                            option-value="value"
                            class="w-full"
                        />
                    </td>
                    <td class="px-2 py-3">
                        <InputText
                            v-model="
                                receptionLignes[idx].commentaire_ecart_reception
                            "
                            placeholder="Commentaire (optionnel)…"
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
            >
                Annuler
            </Button>
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
