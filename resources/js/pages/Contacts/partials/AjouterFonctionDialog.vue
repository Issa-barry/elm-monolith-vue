<script setup lang="ts">
/**
 * Ajout explicite d'une fonction à une fiche existante (lot 3). C'est le geste de confirmation :
 * rien n'est rattaché tant que l'utilisateur n'a pas validé ce formulaire.
 */
import { Button } from '@/components/ui/button';
import { useForm } from '@inertiajs/vue3';
import { Loader2 } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import ChampsFonction from './ChampsFonction.vue';
import type { OptionFiltre } from './types';

const props = defineProps<{
    visible: boolean;
    /** URL POST d'ajout de fonction de la fiche. */
    url: string;
    nomFiche: string;
    fonctions: OptionFiltre[];
    typesPrestataire: OptionFiltre[];
    sousCategoriesFournisseur?: OptionFiltre[];
}>();

const emit = defineEmits<{ 'update:visible': [boolean] }>();

const form = useForm({
    fonction: (props.fonctions.length === 1
        ? props.fonctions[0].value
        : null) as string | null,
    qualification_ids: [] as string[],
    sous_categorie_ids: [] as string[],
    notes: null as string | null,
});

function fermer(visible: boolean) {
    if (!form.processing) {
        form.clearErrors();
        emit('update:visible', visible);
    }
}

function confirmer() {
    if (form.processing) return;
    form.post(props.url, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            emit('update:visible', false);
        },
    });
}
</script>

<template>
    <Dialog
        :visible="visible"
        modal
        header="Ajouter une fonction"
        :closable="!form.processing"
        :close-on-escape="!form.processing"
        :draggable="false"
        :style="{ width: 'min(36rem, 94vw)' }"
        @update:visible="fermer"
    >
        <div class="space-y-4 text-sm">
            <p class="text-muted-foreground">
                La fonction est ajoutée à
                <span class="font-medium text-foreground">{{ nomFiche }}</span
                >, sans ressaisir son identité.
            </p>
            <ChampsFonction
                v-model:fonction="form.fonction"
                v-model:qualification-ids="form.qualification_ids"
                v-model:sous-categorie-ids="form.sous_categorie_ids"
                v-model:notes="form.notes"
                :fonctions="fonctions"
                :types-prestataire="typesPrestataire"
                :sous-categories-fournisseur="sousCategoriesFournisseur ?? []"
                :errors="form.errors"
            />
        </div>

        <template #footer>
            <Button
                variant="outline"
                :disabled="form.processing"
                @click="fermer(false)"
            >
                Annuler
            </Button>
            <Button
                data-testid="ajouter-fonction-confirmer"
                :disabled="form.processing || !form.fonction"
                :aria-busy="form.processing"
                @click="confirmer"
            >
                <Loader2
                    v-if="form.processing"
                    class="mr-1.5 h-4 w-4 animate-spin"
                />
                {{ form.processing ? 'Ajout…' : 'Ajouter la fonction' }}
            </Button>
        </template>
    </Dialog>
</template>
