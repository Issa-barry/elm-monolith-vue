<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useForm, usePage } from '@inertiajs/vue3';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import { computed } from 'vue';

const props = defineProps<{
    visible: boolean;
}>();

const emit = defineEmits<{
    'update:visible': [boolean];
    /** Émis avec l'id de la fonction tout juste créée, pour sélection automatique. */
    created: [string];
}>();

const localVisible = computed({
    get: () => props.visible,
    set: (val: boolean) => emit('update:visible', val),
});

const form = useForm({
    libelle: '',
});

function close() {
    localVisible.value = false;
    form.reset();
    form.clearErrors();
}

function submit() {
    form.post('/backoffice/fonctions-rh', {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            // Flashée par FonctionRhController::store() — cf. HandleInertiaRequests::share().
            const page = usePage();
            const createdId = (page.props as any).flash
                ?.created_fonction_rh_id as string | undefined;
            close();
            if (createdId) emit('created', createdId);
        },
    });
}
</script>

<template>
    <Dialog
        v-model:visible="localVisible"
        modal
        header="Créer une fonction RH"
        :style="{ width: 'min(26rem, 95vw)' }"
        :draggable="false"
        @hide="
            form.reset();
            form.clearErrors();
        "
    >
        <div class="space-y-4">
            <div class="space-y-1.5">
                <Label for="fonction-rh-libelle"
                    >Libellé <span class="text-destructive">*</span></Label
                >
                <InputText
                    id="fonction-rh-libelle"
                    v-model="form.libelle"
                    class="w-full"
                    :class="form.errors.libelle ? 'p-invalid' : ''"
                    placeholder="Ex : Gérant de dépôt"
                    autofocus
                    @keyup.enter="submit"
                />
                <p
                    v-if="form.errors.libelle"
                    class="text-xs text-destructive"
                >
                    {{ form.errors.libelle }}
                </p>
                <p v-else class="text-xs text-muted-foreground">
                    Le métier de la personne — indépendant de son profil
                    d'accès.
                </p>
            </div>
        </div>

        <template #footer>
            <div class="flex justify-end gap-2">
                <Button variant="outline" @click="close">Annuler</Button>
                <Button
                    :disabled="form.processing || !form.libelle.trim()"
                    @click="submit"
                >
                    Créer
                </Button>
            </div>
        </template>
    </Dialog>
</template>
