<script setup lang="ts">
/**
 * Détachement du compte d'un parrain (ADR 0050, COMPTE-005) : l'accès est coupé immédiatement, le
 * compte et ses autres activités restent intacts, et l'opération est tracée dans le journal d'audit.
 */
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/vue3';
import { Loader2 } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import { ref } from 'vue';

const props = defineProps<{
    visible: boolean;
    parrainId: string;
    nomCompte: string | null;
}>();

const emit = defineEmits<{
    'update:visible': [boolean];
}>();

const enCours = ref(false);
const erreur = ref<string | null>(null);

function fermer(visible: boolean) {
    if (!enCours.value) {
        erreur.value = null;
        emit('update:visible', visible);
    }
}

function confirmer() {
    if (enCours.value) return;

    enCours.value = true;
    erreur.value = null;

    router.delete(`/backoffice/parrains/${props.parrainId}/compte`, {
        preserveScroll: true,
        onSuccess: () => emit('update:visible', false),
        onError: () => {
            erreur.value = 'Le détachement a échoué.';
        },
        onFinish: () => {
            enCours.value = false;
        },
    });
}
</script>

<template>
    <Dialog
        :visible="visible"
        modal
        header="Détacher ce compte ?"
        :closable="!enCours"
        :close-on-escape="!enCours"
        :draggable="false"
        :style="{ width: 'min(30rem, 94vw)' }"
        @update:visible="fermer"
    >
        <div class="space-y-3 text-sm">
            <p>
                <span class="font-medium">{{ nomCompte ?? 'Ce compte' }}</span>
                n'aura plus accès aux commissions et aux paiements de ce
                parrain, dès maintenant.
            </p>
            <p class="text-muted-foreground">
                Le compte n'est pas supprimé et garde ses autres activités.
                Cette opération est enregistrée dans le journal.
            </p>
            <p
                v-if="erreur"
                class="rounded-md bg-destructive/10 px-3 py-2 text-destructive"
            >
                {{ erreur }}
            </p>
        </div>

        <template #footer>
            <Button
                variant="outline"
                :disabled="enCours"
                @click="fermer(false)"
            >
                Annuler
            </Button>
            <Button
                variant="destructive"
                data-testid="detachement-confirmer"
                :disabled="enCours"
                :aria-busy="enCours"
                @click="confirmer"
            >
                <Loader2 v-if="enCours" class="mr-1.5 h-4 w-4 animate-spin" />
                {{ enCours ? 'Détachement…' : 'Détacher le compte' }}
            </Button>
        </template>
    </Dialog>
</template>
