<script setup lang="ts">
/**
 * Choix de la fonction à créer (lot 3 : Fournisseur ou Prestataire) et de ses données propres.
 * Les options viennent du serveur : seules les fonctions que l'utilisateur peut créer.
 */
import { Label } from '@/components/ui/label';
import Dropdown from 'primevue/dropdown';
import Textarea from 'primevue/textarea';
import type { OptionFiltre } from './types';

defineProps<{
    fonctions: OptionFiltre[];
    typesPrestataire: OptionFiltre[];
    errors: Partial<Record<'fonction' | 'type' | 'notes', string>>;
}>();

const fonction = defineModel<string | null>('fonction', { required: true });
const type = defineModel<string | null>('type', { required: true });
const notes = defineModel<string | null>('notes', { required: true });
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
            <Label for="type" class="mb-1.5 block"
                >Qualification <span class="text-destructive">*</span></Label
            >
            <Dropdown
                v-model="type"
                input-id="type"
                :options="typesPrestataire"
                option-label="label"
                option-value="value"
                placeholder="Machiniste, mécanicien, consultant…"
                class="w-full"
                :class="{ 'p-invalid': errors.type }"
                data-testid="contact-type-prestataire"
            />
            <p v-if="errors.type" class="mt-1 text-xs text-destructive">
                {{ errors.type }}
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
