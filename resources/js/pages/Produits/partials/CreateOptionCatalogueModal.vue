<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useForm, usePage } from '@inertiajs/vue3';
import Chips from 'primevue/chips';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import { computed } from 'vue';

const props = defineProps<{
    visible: boolean;
}>();

const emit = defineEmits<{
    'update:visible': [boolean];
    /** Émis avec l'id de l'option tout juste créée, pour sélection automatique. */
    created: [string];
}>();

const localVisible = computed({
    get: () => props.visible,
    set: (val: boolean) => emit('update:visible', val),
});

const form = useForm({
    nom: '',
    valeurs: [] as string[],
});

function close() {
    localVisible.value = false;
    form.reset();
    form.clearErrors();
}

function submit() {
    form.post('/backoffice/produits/options', {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            // Flashée par OptionCatalogueController::store() — cf. HandleInertiaRequests::share().
            const page = usePage();
            const createdId = (page.props as any).flash
                ?.created_option_catalogue_id as string | undefined;
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
        header="Créer une option"
        :style="{ width: '28rem' }"
        :draggable="false"
        @hide="
            form.reset();
            form.clearErrors();
        "
    >
        <div class="space-y-4">
            <div class="space-y-1.5">
                <Label for="option-nom"
                    >Nom <span class="text-destructive">*</span></Label
                >
                <InputText
                    id="option-nom"
                    v-model="form.nom"
                    placeholder="Ex : Couleur, Taille, Pointure…"
                    class="w-full"
                    :class="form.errors.nom ? 'p-invalid' : ''"
                    autofocus
                    @keyup.enter="submit"
                />
                <p v-if="form.errors.nom" class="text-xs text-destructive">
                    {{ form.errors.nom }}
                </p>
            </div>

            <div class="space-y-1.5">
                <Label for="option-valeurs">Valeurs proposées</Label>
                <Chips
                    id="option-valeurs"
                    v-model="form.valeurs"
                    placeholder="Tapez une valeur puis Entrée"
                    class="w-full"
                    :class="form.errors.valeurs ? 'p-invalid' : ''"
                />
                <p class="text-xs text-muted-foreground">
                    Facultatif — vous pourrez en ajouter d'autres plus tard,
                    depuis un produit ou depuis Produits &gt; Options.
                </p>
            </div>
        </div>

        <template #footer>
            <div class="flex justify-end gap-2">
                <Button variant="outline" @click="close">Annuler</Button>
                <Button
                    :disabled="form.processing || !form.nom.trim()"
                    @click="submit"
                >
                    Créer
                </Button>
            </div>
        </template>
    </Dialog>
</template>
