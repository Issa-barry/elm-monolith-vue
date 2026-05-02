<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import Dialog from 'primevue/dialog';
import InputNumber from 'primevue/inputnumber';
import { computed, ref, watch } from 'vue';

export interface LignePartage {
    id: string;
    label: string;
    montant: number;
    taux: number;
}

export interface PartageResult {
    commission_unitaire_par_pack: number;
    montant_par_pack_proprietaire: number | null;
    membres_montants: number[];
}

interface MembreRef {
    prenom: string;
    nom: string;
    role: string;
}

const props = defineProps<{
    visible: boolean;
    membres: MembreRef[];
    proprietaireNom: string | null;
    commissionInitiale: number;
    montantsInitiaux: {
        montant_proprietaire: number | null;
        montants_membres: number[];
    };
}>();

const emit = defineEmits<{
    'update:visible': [boolean];
    confirm: [PartageResult];
}>();

const commission = ref(0);
const lignes = ref<LignePartage[]>([]);

function montantToTaux(montant: number, comm: number): number {
    if (!comm || comm <= 0) return 0;
    return parseFloat(((montant / comm) * 100).toFixed(2));
}

function tauxToMontant(taux: number, comm: number): number {
    return Math.round((taux / 100) * comm);
}

// Reconstruit les lignes à chaque ouverture de la modale
watch(
    () => props.visible,
    (val) => {
        if (!val) return;
        commission.value = props.commissionInitiale > 0 ? props.commissionInitiale : 200;

        const newLignes: LignePartage[] = [];

        // Ligne propriétaire (uniquement si véhicule externe)
        if (props.proprietaireNom !== null) {
            const montant = props.montantsInitiaux.montant_proprietaire ?? 0;
            newLignes.push({
                id: 'proprietaire',
                label: `Propriétaire — ${props.proprietaireNom}`,
                montant,
                taux: montantToTaux(montant, commission.value),
            });
        }

        // Lignes membres avec numérotation par rôle
        const roleCounts: Record<string, number> = {};
        props.membres.forEach((m, i) => {
            roleCounts[m.role] = (roleCounts[m.role] ?? 0) + 1;
            const roleLabel =
                m.role === 'chauffeur'
                    ? `Chauffeur ${roleCounts[m.role]}`
                    : `Convoyeur ${roleCounts[m.role]}`;
            const montant = props.montantsInitiaux.montants_membres[i] ?? 0;
            newLignes.push({
                id: `membre-${i}`,
                label: `${roleLabel} — ${m.prenom} ${m.nom}`,
                montant,
                taux: montantToTaux(montant, commission.value),
            });
        });

        lignes.value = newLignes;
    },
);

// Quand la commission change, recalcule les taux (les montants restent fixes)
watch(commission, (newComm) => {
    lignes.value.forEach((l) => {
        l.taux = montantToTaux(l.montant, newComm);
    });
});

function onMontantChange(ligne: LignePartage, val: number | null) {
    ligne.montant = val ?? 0;
    ligne.taux = montantToTaux(ligne.montant, commission.value);
}

function onTauxChange(ligne: LignePartage, val: number | null) {
    ligne.taux = val ?? 0;
    ligne.montant = tauxToMontant(ligne.taux, commission.value);
}

const total = computed(() =>
    lignes.value.reduce((s, l) => s + (l.montant || 0), 0),
);

const isValid = computed(
    () =>
        commission.value > 0 &&
        Math.abs(total.value - commission.value) < 0.01,
);

function handleConfirm() {
    if (!isValid.value) return;

    const propLigne = props.proprietaireNom !== null
        ? lignes.value.find((l) => l.id === 'proprietaire')
        : null;

    const membreMontants = props.membres.map((_, i) => {
        const ligne = lignes.value.find((l) => l.id === `membre-${i}`);
        return ligne?.montant ?? 0;
    });

    emit('confirm', {
        commission_unitaire_par_pack: commission.value,
        montant_par_pack_proprietaire: propLigne ? propLigne.montant : null,
        membres_montants: membreMontants,
    });
    emit('update:visible', false);
}
</script>

<template>
    <Dialog
        :visible="visible"
        modal
        header="Configurer le partage"
        :style="{ width: 'min(720px, 95vw)' }"
        :dismissable-mask="true"
        :pt="{ content: { style: 'overflow: visible' } }"
        @update:visible="emit('update:visible', $event)"
    >
        <div class="space-y-5 pt-2 pb-1">
            <!-- Commission par pack -->
            <div>
                <Label
                    for="commission-par-pack"
                    class="mb-1.5 block text-xs font-medium"
                >
                    Commission unitaire par pack (GNF)
                    <span class="text-destructive">*</span>
                </Label>
                <InputNumber
                    v-model="commission"
                    input-id="commission-par-pack"
                    :min="1"
                    :max-fraction-digits="0"
                    suffix=" GNF"
                    class="w-full"
                    :input-style="{ textAlign: 'right', width: '100%' }"
                />
                <p class="mt-1 text-xs text-muted-foreground">
                    Montant total à répartir entre tous les bénéficiaires.
                </p>
            </div>

            <!-- Tableau des bénéficiaires -->
            <div v-if="lignes.length > 0" class="rounded-lg border">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b bg-muted/40">
                            <th class="px-3 py-2 text-left font-medium text-muted-foreground">
                                Bénéficiaire
                            </th>
                            <th class="px-3 py-2 text-right font-medium text-muted-foreground">
                                Montant (GNF)
                            </th>
                            <th class="w-28 px-3 py-2 text-right font-medium text-muted-foreground">
                                %
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="ligne in lignes"
                            :key="ligne.id"
                            class="border-b last:border-b-0"
                        >
                            <td class="px-3 py-2 text-sm">
                                {{ ligne.label }}
                            </td>
                            <td class="px-3 py-2">
                                <InputNumber
                                    :model-value="ligne.montant"
                                    :min="0"
                                    :max="commission"
                                    :max-fraction-digits="0"
                                    class="w-full"
                                    :input-style="{ textAlign: 'right', width: '100%' }"
                                    @update:model-value="onMontantChange(ligne, $event)"
                                />
                            </td>
                            <td class="px-3 py-2">
                                <InputNumber
                                    :model-value="ligne.taux"
                                    :min="0"
                                    :max="100"
                                    :max-fraction-digits="2"
                                    suffix=" %"
                                    :disabled="!commission || commission <= 0"
                                    class="w-full"
                                    :input-style="{ textAlign: 'right', width: '100%' }"
                                    @update:model-value="onTauxChange(ligne, $event)"
                                />
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t bg-muted/20">
                            <td class="px-3 py-2.5 text-sm font-semibold">
                                Total
                            </td>
                            <td
                                class="px-3 py-2.5 text-right font-mono text-sm font-semibold"
                                :class="isValid ? 'text-emerald-600' : 'text-destructive'"
                            >
                                {{ total }} GNF
                            </td>
                            <td class="px-3 py-2.5 text-right font-mono text-xs">
                                <span
                                    v-if="isValid"
                                    class="font-semibold text-emerald-600"
                                >
                                    ✓ 100 %
                                </span>
                                <span v-else class="text-destructive">
                                    ≠ {{ commission }} GNF
                                </span>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div
                v-else
                class="rounded-lg border border-dashed py-8 text-center text-sm text-muted-foreground"
            >
                Ajoutez au moins un membre pour configurer le partage.
            </div>

            <!-- Message d'erreur -->
            <p
                v-if="!isValid && lignes.length > 0 && commission > 0"
                class="text-xs text-destructive"
            >
                La somme des montants ({{ total }} GNF) doit être égale à la
                commission par pack ({{ commission }} GNF). Différence :
                {{ Math.abs(total - commission).toFixed(0) }} GNF.
            </p>
        </div>

        <template #footer>
            <div class="flex w-full items-center justify-between">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="emit('update:visible', false)"
                >
                    Annuler
                </Button>
                <Button
                    type="button"
                    size="sm"
                    :disabled="!isValid"
                    @click="handleConfirm"
                >
                    Valider le partage
                </Button>
            </div>
        </template>
    </Dialog>
</template>
