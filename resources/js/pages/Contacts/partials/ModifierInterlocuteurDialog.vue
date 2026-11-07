<script setup lang="ts">
/**
 * Modification d'un interlocuteur (ADR 0060, lot 4) : fonction dans l'entreprise et notes. Ses
 * coordonnées appartiennent à sa fiche Personne (modification prévue au lot 7).
 */
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/vue3';
import { Loader2 } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Textarea from 'primevue/textarea';
import { watch } from 'vue';
import type { InterlocuteurFiche } from './types';

const props = defineProps<{
    visible: boolean;
    entrepriseId: string;
    interlocuteur: InterlocuteurFiche | null;
}>();

const emit = defineEmits<{ 'update:visible': [boolean] }>();

const form = useForm({ fonction: '', notes: '' });

watch(
    () => props.interlocuteur,
    (i) => {
        form.fonction = i?.fonction ?? '';
        form.notes = i?.notes ?? '';
        form.clearErrors();
    },
    { immediate: true },
);

function fermer(visible: boolean) {
    if (!form.processing) emit('update:visible', visible);
}

function enregistrer() {
    if (form.processing || !props.interlocuteur) return;
    form.patch(
        `/backoffice/contacts/entreprises/${props.entrepriseId}/interlocuteurs/${props.interlocuteur.id}`,
        {
            preserveScroll: true,
            onSuccess: () => emit('update:visible', false),
        },
    );
}
</script>

<template>
    <Dialog
        :visible="visible"
        modal
        :header="`Modifier — ${interlocuteur?.nom ?? ''}`"
        :closable="!form.processing"
        :close-on-escape="!form.processing"
        :draggable="false"
        :style="{ width: 'min(32rem, 94vw)' }"
        @update:visible="fermer"
    >
        <form
            id="form-modifier-interlocuteur"
            class="space-y-4 text-sm"
            @submit.prevent="enregistrer"
        >
            <p class="text-muted-foreground">
                Les coordonnées (téléphone, e-mail, adresse) se modifient sur sa
                fiche personne.
            </p>
            <div>
                <Label for="m-fonction" class="mb-1.5 block"
                    >Fonction dans l'entreprise</Label
                >
                <InputText
                    id="m-fonction"
                    v-model="form.fonction"
                    class="w-full"
                    :class="{ 'p-invalid': form.errors.fonction }"
                />
                <p
                    v-if="form.errors.fonction"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ form.errors.fonction }}
                </p>
            </div>
            <div>
                <Label for="m-notes" class="mb-1.5 block">Notes</Label>
                <Textarea
                    id="m-notes"
                    v-model="form.notes"
                    rows="3"
                    auto-resize
                    class="w-full"
                />
            </div>
        </form>

        <template #footer>
            <Button
                variant="outline"
                :disabled="form.processing"
                @click="fermer(false)"
            >
                Annuler
            </Button>
            <Button
                type="submit"
                form="form-modifier-interlocuteur"
                :disabled="form.processing"
                :aria-busy="form.processing"
                data-testid="interlocuteur-modifier-enregistrer"
            >
                <Loader2
                    v-if="form.processing"
                    class="mr-1.5 h-4 w-4 animate-spin"
                />
                {{ form.processing ? 'Enregistrement…' : 'Enregistrer' }}
            </Button>
        </template>
    </Dialog>
</template>
