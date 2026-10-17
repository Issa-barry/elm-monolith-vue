<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/vue3';
import { PackageMinus } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import InputNumber from 'primevue/inputnumber';
import Select from 'primevue/select';
import Textarea from 'primevue/textarea';
import { useToast } from 'primevue/usetoast';
import { computed, ref, watch } from 'vue';
import '../../../../css/logistique-mobile.css';

interface LigneProp {
    id: number | string;
    produit_nom: string | null;
    quantite_chargee: number | null;
    quantite_recue: number | null;
    quantite_retournee: number;
    quantite_retournable: number;
}

interface MotifOption {
    value: string;
    label: string;
}

interface RetourLigne {
    id: number | string;
    produit_nom: string;
    quantite_chargee: number;
    quantite_recue: number | null;
    deja_retournee: number;
    restant: number;
    quantite: number;
}

const props = defineProps<{
    visible: boolean;
    transfertId: number | string;
    /** `transit` : rien n'est encore réceptionné ; `reception` : seul le non-reçu peut revenir. */
    statut: string;
    siteSourceNom: string | null;
    lignes: LigneProp[];
    motifs: MotifOption[];
}>();

const emit = defineEmits<{
    'update:visible': [value: boolean];
    confirmed: [];
}>();

const toast = useToast();
const processing = ref(false);
const dialogErrors = ref<string[]>([]);
const retourLignes = ref<RetourLigne[]>([]);
const motif = ref<string | null>(null);
const commentaire = ref('');

const enReception = computed(() => props.statut === 'reception');

watch(
    () => props.visible,
    (open) => {
        if (open) {
            dialogErrors.value = [];
            motif.value = null;
            commentaire.value = '';
            retourLignes.value = props.lignes.map((l) => ({
                id: l.id,
                produit_nom: l.produit_nom ?? '—',
                quantite_chargee: l.quantite_chargee ?? 0,
                quantite_recue: l.quantite_recue,
                deja_retournee: l.quantite_retournee,
                restant: l.quantite_retournable,
                quantite: 0,
            }));
        }
    },
    { immediate: true },
);

/** Ce qui reste attendu à destination (Transit) ou non justifié (Réception) après ce retour. */
function resteApresRetour(l: RetourLigne): number {
    return l.restant - (l.quantite ?? 0);
}

const quantiteTotale = computed(() =>
    retourLignes.value.reduce((sum, l) => sum + (l.quantite ?? 0), 0),
);

// Retour total : en livraison, toute la marchandise chargée revient — le transfert passe alors
// en « Retourné » (cf. TransfertRetourService). Jamais en Réception, où le statut ne change pas.
const retourTotal = computed(
    () =>
        !enReception.value &&
        quantiteTotale.value > 0 &&
        retourLignes.value.every((l) => resteApresRetour(l) === 0),
);

const commentaireRequis = computed(() => motif.value === 'autre');

const peutValider = computed(
    () =>
        quantiteTotale.value > 0 &&
        motif.value !== null &&
        (!commentaireRequis.value || commentaire.value.trim() !== ''),
);

function toutRetourner(): void {
    retourLignes.value.forEach((l) => {
        l.quantite = l.restant;
    });
}

function onQuantiteChanged(l: RetourLigne): void {
    if (l.quantite === null || l.quantite === undefined) {
        l.quantite = 0;
    }
}

function onUpdateVisible(value: boolean): void {
    // Fermeture bloquée pendant l'envoi : un clic à côté ou Échap ne doit jamais laisser croire à
    // un retour abandonné alors qu'il est peut-être déjà enregistré.
    if (!value && processing.value) return;
    emit('update:visible', value);
}

function submit(): void {
    if (processing.value || !peutValider.value) return;
    processing.value = true;
    dialogErrors.value = [];
    const total = retourTotal.value;

    router.post(
        `/backoffice/logistique/${props.transfertId}/retour`,
        {
            motif: motif.value,
            commentaire: commentaire.value.trim() || null,
            lignes: retourLignes.value
                .filter((l) => (l.quantite ?? 0) > 0)
                .map((l) => ({ id: l.id, quantite: l.quantite })),
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                emit('update:visible', false);
                emit('confirmed');
                toast.add({
                    severity: 'success',
                    summary: total
                        ? 'Retour total enregistré'
                        : 'Retour enregistré',
                    detail: total
                        ? 'Toute la marchandise est réintégrée au site source : le transfert est retourné.'
                        : 'La marchandise est réintégrée au stock du site source.',
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
        header="Enregistrer un retour au site source"
        :style="{ width: 'min(900px, 94vw)' }"
        :draggable="false"
        :resizable="false"
        :closable="!processing"
        :close-on-escape="!processing"
        @update:visible="onUpdateVisible"
        @hide="dialogErrors = []"
    >
        <p class="mb-4 text-sm text-muted-foreground">
            Indiquez la marchandise physiquement revenue
            <template v-if="siteSourceNom">à {{ siteSourceNom }}</template>
            <template v-else>au site source</template>. Elle est réintégrée à
            son stock ; les quantités chargées
            <template v-if="enReception">et reçues</template> ne changent pas.
            <template v-if="enReception">
                Seule la marchandise non reçue à destination peut revenir.
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

        <div class="mb-2 flex justify-end">
            <Button
                variant="ghost"
                size="sm"
                :disabled="processing"
                @click="toutRetourner"
            >
                Tout retourner
            </Button>
        </div>

        <table data-mobile-cards class="w-full text-sm">
            <thead>
                <tr class="border-b text-xs text-muted-foreground">
                    <th class="pb-3 text-left font-medium">Produit</th>
                    <th class="pb-3 text-center font-medium">Chargé</th>
                    <th v-if="enReception" class="pb-3 text-center font-medium">
                        Reçu
                    </th>
                    <th class="pb-3 text-center font-medium">Déjà retourné</th>
                    <th class="px-2 pb-3 text-center font-medium">Retour</th>
                    <th class="pb-3 text-center font-medium">
                        {{ enReception ? 'Non justifié' : 'Reste à livrer' }}
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <tr v-for="l in retourLignes" :key="l.id" class="align-middle">
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
                        v-if="enReception"
                        data-label="Reçu"
                        class="py-3 text-center text-muted-foreground tabular-nums"
                    >
                        {{ l.quantite_recue ?? '—' }}
                    </td>
                    <td
                        data-label="Déjà retourné"
                        class="py-3 text-center text-muted-foreground tabular-nums"
                    >
                        {{ l.deja_retournee }}
                    </td>
                    <td
                        data-label="Retour"
                        class="px-2 py-3"
                        style="width: 130px"
                    >
                        <InputNumber
                            v-model="l.quantite"
                            :input-id="`retour-qte-${l.id}`"
                            :aria-label="`Quantité retournée : ${l.produit_nom}`"
                            :min="0"
                            :max="l.restant"
                            :use-grouping="false"
                            :disabled="processing || l.restant === 0"
                            class="w-full"
                            input-class="w-full text-center"
                            @update:model-value="onQuantiteChanged(l)"
                        />
                    </td>
                    <td
                        :data-label="
                            enReception ? 'Non justifié' : 'Reste à livrer'
                        "
                        class="py-3 text-center font-semibold tabular-nums"
                    >
                        {{ resteApresRetour(l) }}
                    </td>
                </tr>
            </tbody>
        </table>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <div>
                <label
                    class="mb-1 block text-xs font-medium text-muted-foreground"
                    for="retour-transfert-motif"
                >
                    Motif du retour *
                </label>
                <Select
                    v-model="motif"
                    input-id="retour-transfert-motif"
                    :options="motifs"
                    option-label="label"
                    option-value="value"
                    placeholder="Choisir un motif"
                    append-to="self"
                    :disabled="processing"
                    class="w-full"
                />
            </div>
            <div>
                <label
                    class="mb-1 block text-xs font-medium text-muted-foreground"
                    for="retour-transfert-commentaire"
                >
                    Commentaire{{ commentaireRequis ? ' *' : ' (optionnel)' }}
                </label>
                <Textarea
                    id="retour-transfert-commentaire"
                    v-model="commentaire"
                    rows="2"
                    maxlength="1000"
                    :disabled="processing"
                    class="w-full"
                />
            </div>
        </div>

        <div
            class="mt-4 flex items-center justify-between rounded-lg border bg-muted/30 px-4 py-3 text-sm"
        >
            <span class="text-muted-foreground">Quantité retournée</span>
            <span class="font-semibold tabular-nums">{{ quantiteTotale }}</span>
        </div>

        <div
            v-if="retourTotal"
            class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-300"
        >
            Retour total : toute la marchandise revient au site source, le
            transfert passera en « Retourné » et ne pourra plus être
            réceptionné.
        </div>

        <p class="mt-3 text-xs text-muted-foreground">
            Marchandise abîmée : enregistrez le retour puis sortez-la du stock
            par un ajustement « Casse ». Un retour enregistré ne peut pas être
            annulé.
        </p>

        <template #footer>
            <Button
                variant="outline"
                :disabled="processing"
                @click="onUpdateVisible(false)"
            >
                Annuler
            </Button>
            <Button :disabled="processing || !peutValider" @click="submit">
                <PackageMinus v-if="!processing" class="mr-2 h-4 w-4" />
                <span
                    v-if="processing"
                    class="mr-2 inline-block h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent"
                />
                {{ processing ? 'Enregistrement…' : 'Enregistrer le retour' }}
            </Button>
        </template>
    </Dialog>
</template>
