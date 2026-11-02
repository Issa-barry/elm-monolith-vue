<script setup lang="ts">
import InfoTooltip from '@/components/InfoTooltip.vue';
import InputNumber from 'primevue/inputnumber';
import Select from 'primevue/select';

// Conditions reprises dans le contrat de travail généré (ADR 0048) : saisies une fois ici, jamais
// en texte libre. Nombres seulement (ni lettres ni valeurs négatives), revérifiés par le serveur.
const props = defineProps<{
    unites: { value: string; label: string }[];
    estCdd: boolean;
    requis: boolean;
    errors: Partial<Record<string, string>>;
}>();

const duree = defineModel<number | null>('duree', { required: true });
const unite = defineModel<string | null>('unite', { required: true });
const heures = defineModel<number | null>('heures', { required: true });
const motif = defineModel<string | null>('motif', { required: true });

function changerDuree(valeur: number | null) {
    duree.value = valeur;
    if (!valeur) unite.value = null;
    else if (!unite.value) unite.value = 'mois';
}
</script>

<template>
    <div class="space-y-4">
        <div>
            <div class="mb-1.5 flex items-center gap-1.5">
                <label for="contrat-essai" class="text-sm font-medium"
                    >Période d’essai
                    <span v-if="props.requis" class="text-destructive"
                        >*</span
                    ></label
                >
                <InfoTooltip label="Aide : période d'essai">
                    Saisissez un nombre puis choisissez Semaines ou Mois. Mettez
                    0 s’il n’y a pas de période d’essai : le contrat indiquera «
                    aucune ».
                </InfoTooltip>
            </div>
            <div class="flex gap-2">
                <InputNumber
                    :model-value="duree"
                    input-id="contrat-essai"
                    :min="0"
                    :max="99"
                    :use-grouping="false"
                    :max-fraction-digits="0"
                    placeholder="Ex : 3"
                    class="w-28"
                    input-class="w-full"
                    :invalid="!!errors.periode_essai_duree"
                    data-testid="periode-essai-duree"
                    @update:model-value="changerDuree"
                    @input="(e: any) => changerDuree(e.value)"
                />
                <Select
                    v-model="unite"
                    :options="unites"
                    option-label="label"
                    option-value="value"
                    placeholder="Semaines ou mois"
                    :disabled="!duree"
                    class="w-44"
                    :invalid="!!errors.periode_essai_unite"
                    data-testid="periode-essai-unite"
                />
            </div>
            <p v-if="duree === 0" class="mt-1 text-xs text-muted-foreground">
                Pas de période d’essai.
            </p>
            <p
                v-if="errors.periode_essai_duree || errors.periode_essai_unite"
                class="mt-1 text-xs text-destructive"
            >
                {{ errors.periode_essai_duree ?? errors.periode_essai_unite }}
            </p>
        </div>

        <div>
            <label for="contrat-heures" class="mb-1.5 block text-sm font-medium"
                >Nombre d’heures de travail par semaine
                <span v-if="props.requis" class="text-destructive"
                    >*</span
                ></label
            >
            <InputNumber
                v-model="heures"
                input-id="contrat-heures"
                :min="0"
                :max="168"
                :min-fraction-digits="0"
                :max-fraction-digits="2"
                locale="fr-FR"
                suffix=" heures/semaine"
                placeholder="Ex : 40 heures/semaine"
                class="w-full sm:w-72"
                input-class="w-full"
                :invalid="!!errors.heures_hebdomadaires"
                data-testid="heures-hebdomadaires"
                @input="(e: any) => (heures = e.value)"
            />
            <p
                v-if="errors.heures_hebdomadaires"
                class="mt-1 text-xs text-destructive"
            >
                {{ errors.heures_hebdomadaires }}
            </p>
        </div>

        <div v-if="estCdd">
            <label for="contrat-motif" class="mb-1.5 block text-sm font-medium"
                >Motif du CDD
                <span v-if="props.requis" class="text-destructive"
                    >*</span
                ></label
            >
            <input
                id="contrat-motif"
                v-model="motif"
                type="text"
                maxlength="255"
                placeholder="Ex : remplacement d’un salarié absent"
                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :class="{ 'border-destructive': errors.motif_cdd }"
            />
            <p v-if="errors.motif_cdd" class="mt-1 text-xs text-destructive">
                {{ errors.motif_cdd }}
            </p>
        </div>
    </div>
</template>
