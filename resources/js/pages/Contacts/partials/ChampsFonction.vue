<script setup lang="ts">
/**
 * Choix de la fonction à créer (lot 3 : Fournisseur ou Prestataire) et de ses données propres.
 * Les options viennent du serveur : seules les fonctions que l'utilisateur peut créer.
 */
import { Label } from '@/components/ui/label';
import Dropdown from 'primevue/dropdown';
import MultiSelect from 'primevue/multiselect';
import Textarea from 'primevue/textarea';
import type { OptionFiltre } from './types';

withDefaults(
    defineProps<{
        fonctions: OptionFiltre[];
        /** Lot 6b : qualifications du référentiel (valeur = id), plusieurs possibles. */
        typesPrestataire: OptionFiltre[];
        /** Lot 8 : sous-catégories Fournisseur (comme à l'écran Fournisseur), sans effet métier. */
        sousCategoriesFournisseur?: OptionFiltre[];
        errors: Partial<
            Record<
                | 'fonction'
                | 'qualification_ids'
                | 'sous_categorie_ids'
                | 'type'
                | 'notes',
                string
            >
        >;
    }>(),
    { sousCategoriesFournisseur: () => [] },
);

const fonction = defineModel<string | null>('fonction', { required: true });
const qualificationIds = defineModel<string[]>('qualificationIds', {
    required: true,
});
const notes = defineModel<string | null>('notes', { required: true });
const sousCategorieIds = defineModel<string[]>('sousCategorieIds', {
    default: () => [],
});
</script>

<template>
    <div class="grid gap-5 sm:grid-cols-2">
        <div>
            <Label for="fonction" class="mb-1.5 block"
                >Fonction <span class="text-destructive">*</span></Label
            >
            <Dropdown
                v-model="fonction"
                input-id="fonction"
                :options="fonctions"
                option-label="label"
                option-value="value"
                placeholder="Choisir la fonction"
                class="w-full"
                :class="{ 'p-invalid': errors.fonction }"
                data-testid="contact-fonction-choix"
            />
            <p v-if="errors.fonction" class="mt-1 text-xs text-destructive">
                {{ errors.fonction }}
            </p>
        </div>
        <div v-if="fonction === 'prestataire'">
            <Label for="qualification_ids" class="mb-1.5 block"
                >Qualifications <span class="text-destructive">*</span></Label
            >
            <MultiSelect
                v-model="qualificationIds"
                input-id="qualification_ids"
                :options="typesPrestataire"
                option-label="label"
                option-value="value"
                display="chip"
                placeholder="Machiniste, mécanicien, consultant…"
                class="w-full"
                :class="{
                    'p-invalid': errors.qualification_ids || errors.type,
                }"
                data-testid="contact-type-prestataire"
            />
            <p
                v-if="errors.qualification_ids || errors.type"
                class="mt-1 text-xs text-destructive"
            >
                {{ errors.qualification_ids ?? errors.type }}
            </p>
        </div>
        <div
            v-if="
                fonction === 'fournisseur' &&
                sousCategoriesFournisseur.length > 0
            "
        >
            <Label for="sous_categorie_ids" class="mb-1.5 block"
                >Sous-catégories</Label
            >
            <MultiSelect
                v-model="sousCategorieIds"
                input-id="sous_categorie_ids"
                :options="sousCategoriesFournisseur"
                option-label="label"
                option-value="value"
                display="chip"
                placeholder="Aucune"
                class="w-full"
                :class="{ 'p-invalid': errors.sous_categorie_ids }"
                data-testid="contact-sous-categories-fournisseur"
            />
            <p
                v-if="errors.sous_categorie_ids"
                class="mt-1 text-xs text-destructive"
            >
                {{ errors.sous_categorie_ids }}
            </p>
        </div>
        <div class="sm:col-span-2">
            <Label for="notes" class="mb-1.5 block">Notes</Label>
            <Textarea
                id="notes"
                v-model="notes"
                rows="3"
                auto-resize
                class="w-full"
            />
            <p v-if="errors.notes" class="mt-1 text-xs text-destructive">
                {{ errors.notes }}
            </p>
        </div>
    </div>
</template>
