<script setup lang="ts">
/**
 * Confirmation d'activation / désactivation d'un livreur depuis sa fiche
 * (LivreurController::approuver() / desactiver()). Les conséquences affichées sont celles
 * réellement appliquées par le backend — cf. docblock de desactiver().
 */
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/vue3';
import { Loader2 } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import { ref, watch } from 'vue';

const props = defineProps<{
    visible: boolean;
    livreurId: string;
    isActive: boolean;
    hasAccount: boolean;
    aUneEquipe: boolean;
}>();

const emit = defineEmits<{
    'update:visible': [boolean];
}>();

const enCours = ref(false);
// Figé à l'ouverture : après succès, isActive change avant la fin de l'animation de fermeture.
const desactivation = ref(props.isActive);
watch(
    () => props.visible,
    (visible) => {
        if (visible) desactivation.value = props.isActive;
    },
);

function fermer(visible: boolean): void {
    if (enCours.value) return;
    emit('update:visible', visible);
}

function confirmer(): void {
    enCours.value = true;
    const action = desactivation.value ? 'desactiver' : 'approuver';
    router.patch(
        `/backoffice/livreurs/${props.livreurId}/${action}`,
        {},
        {
            preserveScroll: true,
            onSuccess: () => emit('update:visible', false),
            onFinish: () => {
                enCours.value = false;
            },
        },
    );
}
</script>

<template>
    <Dialog
        :visible="visible"
        modal
        :header="
            desactivation ? 'Désactiver ce livreur ?' : 'Activer ce livreur ?'
        "
        :closable="!enCours"
        :draggable="false"
        :style="{ width: 'min(30rem, 94vw)' }"
        @update:visible="fermer"
    >
        <div
            v-if="desactivation"
            class="space-y-3 text-sm"
            data-testid="livreur-desactivation-message"
        >
            <p>
                Le livreur ne sera plus considéré comme actif. Rien n'est
                supprimé : ses équipes, commissions, factures et historiques
                sont conservés.
            </p>
            <ul class="list-disc space-y-1 pl-5 text-muted-foreground">
                <li v-if="aUneEquipe">
                    Il reste rattaché à son équipe, mais n'est plus exigé dans
                    le partage de commission de celle-ci.
                </li>
                <li v-if="hasAccount">
                    Son compte application n'aura plus accès à l'espace livreur
                    tant qu'il n'est pas réactivé.
                </li>
                <li>Vous pourrez le réactiver à tout moment.</li>
            </ul>
        </div>
        <p v-else class="text-sm" data-testid="livreur-activation-message">
            Le livreur pourra de nouveau être utilisé dans les opérations de
            livraison<template v-if="hasAccount">
                et retrouvera l'accès à son espace livreur</template
            >.
        </p>

        <template #footer>
            <div class="flex justify-end gap-2">
                <Button
                    type="button"
                    variant="outline"
                    :disabled="enCours"
                    @click="fermer(false)"
                >
                    Annuler
                </Button>
                <Button
                    type="button"
                    :disabled="enCours"
                    data-testid="livreur-statut-confirmer"
                    @click="confirmer"
                >
                    <Loader2
                        v-if="enCours"
                        class="mr-1.5 h-4 w-4 animate-spin"
                    />
                    {{ desactivation ? 'Désactiver' : 'Activer' }}
                </Button>
            </div>
        </template>
    </Dialog>
</template>
