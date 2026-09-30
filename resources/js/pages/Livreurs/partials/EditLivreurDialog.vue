<script setup lang="ts">
/**
 * Modification de la désignation et du téléphone d'un livreur (UpdateLivreurController) —
 * même saisie du téléphone que les Équipes de livraison : +224 fixe + 9 chiffres locaux.
 */
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/vue3';
import { Info, Loader2 } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import { watch } from 'vue';

const GUINEA_PREFIX = '+224';

const props = defineProps<{
    visible: boolean;
    livreurId: string;
    nomComplet: string | null;
    telephone: string | null;
    hasAccount: boolean;
    telephoneObligatoire: boolean;
}>();

const emit = defineEmits<{
    'update:visible': [boolean];
}>();

function telephoneLocal(telephone: string | null): string {
    if (!telephone) return '';
    const chiffres = telephone.startsWith(GUINEA_PREFIX)
        ? telephone.slice(GUINEA_PREFIX.length)
        : telephone;

    return chiffres.replace(/\D/g, '').slice(-9);
}

const form = useForm({
    nom_complet: props.nomComplet ?? '',
    telephone: telephoneLocal(props.telephone),
});

watch(
    () => props.visible,
    (visible) => {
        if (!visible) return;
        // Valeurs de référence = état actuel du livreur (après un premier enregistrement, les
        // props ont changé) : « Enregistrer » ne s'active qu'en cas de vraie modification.
        form.defaults({
            nom_complet: props.nomComplet ?? '',
            telephone: telephoneLocal(props.telephone),
        });
        form.reset();
        form.clearErrors();
    },
);

function fermer(visible: boolean): void {
    if (form.processing) return;
    emit('update:visible', visible);
}

function onPhoneInput(e: Event): void {
    const local = (e.target as HTMLInputElement).value
        .replace(/\D/g, '')
        .slice(0, 9);
    form.telephone = local;
    (e.target as HTMLInputElement).value = local;
    form.clearErrors('telephone');
}

function enregistrer(): void {
    form.transform((data) => ({
        nom_complet: data.nom_complet.trim(),
        telephone: data.telephone ? `${GUINEA_PREFIX}${data.telephone}` : null,
    })).put(`/backoffice/livreurs/${props.livreurId}`, {
        preserveScroll: true,
        onSuccess: () => emit('update:visible', false),
    });
}
</script>

<template>
    <Dialog
        :visible="visible"
        modal
        header="Modifier le livreur"
        :closable="!form.processing"
        :draggable="false"
        :style="{ width: 'min(28rem, 94vw)' }"
        @update:visible="fermer"
    >
        <form
            id="edit-livreur-form"
            class="space-y-4"
            @submit.prevent="enregistrer"
        >
            <div>
                <Label for="livreur-nom" class="mb-1.5 block text-sm">
                    Nom complet <span class="text-destructive">*</span>
                </Label>
                <InputText
                    id="livreur-nom"
                    v-model="form.nom_complet"
                    class="w-full"
                    maxlength="150"
                    :invalid="!!form.errors.nom_complet"
                    data-testid="livreur-edit-nom"
                    @update:model-value="form.clearErrors('nom_complet')"
                />
                <p
                    v-if="form.errors.nom_complet"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ form.errors.nom_complet }}
                </p>
            </div>

            <div>
                <Label for="livreur-telephone" class="mb-1.5 block text-sm">
                    Téléphone
                    <span v-if="telephoneObligatoire" class="text-destructive"
                        >*</span
                    >
                </Label>
                <div
                    class="flex h-9 overflow-hidden rounded-md border"
                    :class="form.errors.telephone ? 'border-destructive' : ''"
                >
                    <span
                        class="flex shrink-0 items-center gap-1 border-r bg-muted px-2 text-xs text-muted-foreground select-none"
                    >
                        <img
                            src="https://flagcdn.com/16x12/gn.png"
                            width="16"
                            height="12"
                            alt="GN"
                        />
                        +224
                    </span>
                    <input
                        id="livreur-telephone"
                        type="tel"
                        inputmode="numeric"
                        maxlength="9"
                        :value="form.telephone"
                        :placeholder="
                            telephoneObligatoire
                                ? '9 chiffres'
                                : '9 chiffres (optionnel)'
                        "
                        class="min-w-0 flex-1 bg-background px-2 text-sm outline-none placeholder:text-muted-foreground"
                        data-testid="livreur-edit-telephone"
                        @input="onPhoneInput"
                    />
                </div>
                <p
                    v-if="form.errors.telephone"
                    class="mt-1 text-xs text-destructive"
                    data-testid="livreur-edit-telephone-error"
                >
                    {{ form.errors.telephone }}
                </p>
                <p
                    v-else-if="telephoneObligatoire"
                    class="mt-1 text-xs text-muted-foreground"
                >
                    Obligatoire : ce livreur est chauffeur.
                </p>
            </div>

            <p
                v-if="hasAccount"
                class="flex items-start gap-2 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-200"
            >
                <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                Ce livreur a un compte application : son numéro de connexion
                n'est pas modifié ici.
            </p>
        </form>

        <template #footer>
            <div class="flex justify-end gap-2">
                <Button
                    type="button"
                    variant="outline"
                    :disabled="form.processing"
                    @click="fermer(false)"
                >
                    Annuler
                </Button>
                <Button
                    type="submit"
                    form="edit-livreur-form"
                    :disabled="form.processing || !form.isDirty"
                    data-testid="livreur-edit-submit"
                >
                    <Loader2
                        v-if="form.processing"
                        class="mr-1.5 h-4 w-4 animate-spin"
                    />
                    {{ form.processing ? 'Enregistrement…' : 'Enregistrer' }}
                </Button>
            </div>
        </template>
    </Dialog>
</template>
