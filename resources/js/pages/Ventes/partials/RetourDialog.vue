<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/vue3';
import { RotateCcw } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import InputNumber from 'primevue/inputnumber';
import Select from 'primevue/select';
import Textarea from 'primevue/textarea';
import { useToast } from 'primevue/usetoast';
import { computed, ref, watch } from 'vue';

interface LigneProp {
    id: string;
    produit_nom: string | null;
    quantite_chargee: number | null;
    quantite_retournee: number;
    quantite_retournable: number;
    prix_unitaire: number;
}

interface MotifOption {
    value: string;
    label: string;
}

interface RetourLigne {
    id: string;
    produit_nom: string;
    quantite_chargee: number;
    deja_retournee: number;
    restant: number;
    prix_unitaire: number;
    quantite: number;
}

const props = defineProps<{
    visible: boolean;
    commandeId: string;
    lignes: LigneProp[];
    motifs: MotifOption[];
    /** Montant net de la facture avant ce retour — sert à prévisualiser le nouveau montant. */
    montantFacture: number | null;
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
                deja_retournee: l.quantite_retournee,
                restant: l.quantite_retournable,
                prix_unitaire: l.prix_unitaire,
                quantite: 0,
            }));
        }
    },
    { immediate: true },
);

function formatGNF(val: number): string {
    const n = Math.round(val);
    return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' GNF';
}

function livreeApresRetour(l: RetourLigne): number {
    return l.quantite_chargee - l.deja_retournee - (l.quantite ?? 0);
}

const quantiteTotale = computed(() =>
    retourLignes.value.reduce((sum, l) => sum + (l.quantite ?? 0), 0),
);

const montantRetourne = computed(() =>
    retourLignes.value.reduce(
        (sum, l) => sum + (l.quantite ?? 0) * l.prix_unitaire,
        0,
    ),
);

const nouveauMontantFacture = computed(() =>
    props.montantFacture === null
        ? null
        : Math.max(0, props.montantFacture - montantRetourne.value),
);

// Retour total : plus rien de livré sur aucune ligne une fois ce retour appliqué.
const retourTotal = computed(
    () =>
        quantiteTotale.value > 0 &&
        retourLignes.value.every((l) => livreeApresRetour(l) === 0),
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
    // Fermeture bloquée tant que la requête est en cours : un clic à côté ou Échap ne doit jamais
    // laisser croire à un retour annulé alors qu'il est peut-être déjà enregistré.
    if (!value && processing.value) return;
    emit('update:visible', value);
}

function submit(): void {
    if (processing.value || !peutValider.value) return;
    processing.value = true;
    dialogErrors.value = [];

    router.post(
        `/backoffice/ventes/${props.commandeId}/retour`,
        {
            motif: motif.value,
            commentaire: commentaire.value.trim() || null,
            lignes: retourLignes.value
                .filter((l) => (l.quantite ?? 0) > 0)
                .map((l) => ({ id: l.id, quantite: l.quantite })),
        },
        {
            preserveScroll: true,
            onSuccess: (page) => {
                emit('update:visible', false);
                emit('confirmed');
                // Retour valide mais écriture comptable en échec : à régulariser, jamais silencieux
                // (cf. CommandeVenteRetourService::comptabiliserRetour()).
                const avertissement = (
                    page.props as { flash?: { warning?: string } }
                ).flash?.warning;
                if (avertissement) {
                    toast.add({
                        severity: 'warn',
                        summary: 'Comptabilité à régulariser',
                        detail: avertissement,
                        life: 10000,
                    });
                }
                toast.add({
                    severity: 'success',
                    summary: retourTotal.value
                        ? 'Retour total enregistré'
                        : 'Retour enregistré',
                    detail: retourTotal.value
                        ? 'La commande est retournée et sa facture annulée.'
                        : 'Facture recalculée, marchandise remise en stock.',
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
        header="Enregistrer un retour de livraison"
        :style="{ width: 'min(900px, 94vw)' }"
        :draggable="true"
        :resizable="false"
        :closable="!processing"
        :close-on-escape="!processing"
        @update:visible="onUpdateVisible"
        @hide="dialogErrors = []"
    >
        <p class="mb-4 text-sm text-muted-foreground">
            Le livreur est revenu avec tout ou partie de la marchandise, avant
            tout encaissement. Indiquez la quantité retournée par produit : la
            facture est recalculée sur les quantités réellement livrées, la
            marchandise retournée est remise en stock et la commission est
            réajustée.
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

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-xs text-muted-foreground">
                        <th class="pb-3 text-left font-medium">Produit</th>
                        <th class="pb-3 text-center font-medium">Chargée</th>
                        <th class="pb-3 text-center font-medium">
                            Déjà retournée
                        </th>
                        <th class="px-2 pb-3 text-center font-medium">
                            Retour
                        </th>
                        <th class="pb-3 text-center font-medium">Livrée</th>
                        <th class="pb-3 text-right font-medium">
                            Montant retourné
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="l in retourLignes"
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
                        <td
                            class="py-3 text-center text-muted-foreground tabular-nums"
                        >
                            {{ l.deja_retournee }}
                        </td>
                        <td class="px-2 py-3" style="width: 130px">
                            <InputNumber
                                v-model="l.quantite"
                                :min="0"
                                :max="l.restant"
                                :use-grouping="false"
                                :disabled="processing || l.restant === 0"
                                class="w-full"
                                input-class="w-full text-center"
                                @update:model-value="onQuantiteChanged(l)"
                            />
                        </td>
                        <td class="py-3 text-center font-semibold tabular-nums">
                            {{ livreeApresRetour(l) }}
                        </td>
                        <td class="py-3 text-right tabular-nums">
                            {{ formatGNF((l.quantite ?? 0) * l.prix_unitaire) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <div>
                <label
                    class="mb-1 block text-xs font-medium text-muted-foreground"
                    for="retour-motif"
                >
                    Motif du retour *
                </label>
                <Select
                    v-model="motif"
                    input-id="retour-motif"
                    :options="motifs"
                    option-label="label"
                    option-value="value"
                    placeholder="Choisir un motif"
                    :disabled="processing"
                    class="w-full"
                />
            </div>
            <div>
                <label
                    class="mb-1 block text-xs font-medium text-muted-foreground"
                    for="retour-commentaire"
                >
                    Commentaire{{ commentaireRequis ? ' *' : ' (optionnel)' }}
                </label>
                <Textarea
                    id="retour-commentaire"
                    v-model="commentaire"
                    rows="2"
                    maxlength="1000"
                    :disabled="processing"
                    class="w-full"
                />
            </div>
        </div>

        <div class="mt-4 rounded-lg border bg-muted/30 px-4 py-3 text-sm">
            <div class="flex items-center justify-between">
                <span class="text-muted-foreground">Quantité retournée</span>
                <span class="font-semibold tabular-nums">{{
                    quantiteTotale
                }}</span>
            </div>
            <div class="mt-1 flex items-center justify-between">
                <span class="text-muted-foreground">Montant retourné</span>
                <span class="font-semibold tabular-nums">{{
                    formatGNF(montantRetourne)
                }}</span>
            </div>
            <div
                v-if="nouveauMontantFacture !== null"
                class="mt-1 flex items-center justify-between"
            >
                <span class="text-muted-foreground">Nouvelle facture</span>
                <span class="font-semibold tabular-nums">{{
                    formatGNF(nouveauMontantFacture)
                }}</span>
            </div>
        </div>

        <div
            v-if="retourTotal"
            class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-300"
        >
            Retour total : la commande passera en « Retournée », sa facture sera
            annulée et sa commission annulée.
        </div>

        <template #footer>
            <Button
                variant="outline"
                :disabled="processing"
                @click="onUpdateVisible(false)"
            >
                Annuler
            </Button>
            <Button :disabled="processing || !peutValider" @click="submit">
                <RotateCcw v-if="!processing" class="mr-2 h-4 w-4" />
                <span
                    v-if="processing"
                    class="mr-2 inline-block h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent"
                />
                {{ processing ? 'Enregistrement…' : 'Enregistrer le retour' }}
            </Button>
        </template>
    </Dialog>
</template>
