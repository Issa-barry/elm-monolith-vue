<script setup lang="ts">
// Confirmation de l'annulation d'une absence : elle reste enregistrée avec le statut « Annulée »
// (jamais supprimée) et l'annulation est tracée dans son historique.
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { BASE_ABSENCES, type AbsenceRow } from '@/types/absence';
import { useForm } from '@inertiajs/vue3';
import { Loader2 } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import { computed, watch } from 'vue';

const props = defineProps<{
    visible: boolean;
    absence: AbsenceRow | null;
}>();

const emit = defineEmits<{
    'update:visible': [value: boolean];
    annulee: [];
}>();

const form = useForm({ motif_annulation: '' });

watch(
    () => props.visible,
    (visible) => {
        if (visible) {
            form.reset();
            form.clearErrors();
        }
    },
);

const erreur = computed(() => {
    const e = form.errors as Record<string, string | undefined>;
    return e.absence ?? e.motif_annulation ?? null;
});

function fermer() {
    if (!form.processing) emit('update:visible', false);
}

function confirmer() {
    if (!props.absence) return;
    form.patch(`${BASE_ABSENCES}/${props.absence.id}/annuler`, {
        preserveScroll: true,
        onSuccess: () => {
            emit('update:visible', false);
            emit('annulee');
        },
    });
}
</script>

<template>
    <Dialog
        :visible="visible"
        modal
        header="Annuler l’absence"
        :style="{ width: 'min(28rem, 95vw)' }"
        :draggable="false"
        :closable="!form.processing"
        data-testid="absence-annulation"
        @update:visible="(v: boolean) => (v ? null : fermer())"
    >
        <form class="space-y-4 pt-1" @submit.prevent="confirmer">
            <p class="text-sm text-muted-foreground">
                L’absence de
                <strong class="text-foreground">{{ absence?.salarie }}</strong>
                ({{ absence?.motif }}, du {{ absence?.date_debut_label }} au
                {{ absence?.date_fin_label }}) sera annulée. Elle reste
                consultable dans l’historique avec le statut « Annulée ».
            </p>
            <div class="space-y-1.5">
                <Label for="absence-motif-annulation"
                    >Raison de l’annulation</Label
                >
                <textarea
                    id="absence-motif-annulation"
                    v-model="form.motif_annulation"
                    rows="2"
                    maxlength="255"
                    class="flex min-h-[60px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    placeholder="Ex : saisie en double (facultatif)"
                />
            </div>
            <p v-if="erreur" class="text-sm text-destructive">{{ erreur }}</p>
            <div class="flex justify-end gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="form.processing"
                    @click="fermer"
                >
                    Retour
                </Button>
                <Button
                    type="submit"
                    variant="destructive"
                    size="sm"
                    :disabled="form.processing"
                    data-testid="absence-confirmer-annulation"
                >
                    <Loader2
                        v-if="form.processing"
                        class="mr-1.5 h-3.5 w-3.5 animate-spin"
                    />
                    Annuler l’absence
                </Button>
            </div>
        </form>
    </Dialog>
</template>
