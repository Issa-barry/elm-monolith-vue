<script setup lang="ts">
import FonctionRhSelect, {
    type FonctionRhOption,
} from '@/components/rh/FonctionRhSelect.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { useForm } from '@inertiajs/vue3';
import Dropdown from 'primevue/dropdown';
import { useToast } from 'primevue/usetoast';
import { watch } from 'vue';

interface Option {
    value: string | number;
    label: string;
}

const props = defineProps<{
    open: boolean;
    userId: string | number | null;
    userName: string;
    roleOptions: Option[];
    siteOptions: Option[];
    fonctionOptions: FonctionRhOption[];
    typeEmployeOptions: Option[];
    statutOptions: Option[];
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
}>();

const toast = useToast();

const form = useForm<{
    is_staff_avec_fiche_employe: boolean;
    role_id: number | null;
    site_id: string | null;
    fonction_rh_id: string | null;
    type_employe: string | null;
    statut: string | null;
}>({
    is_staff_avec_fiche_employe: true,
    role_id: null,
    site_id: null,
    fonction_rh_id: null,
    type_employe: null,
    statut: null,
});

// Réinitialise le formulaire à chaque ouverture — jamais de valeurs laissées d'une
// précédente validation (pour un autre utilisateur) dans le formulaire.
watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            form.reset();
            form.clearErrors();
            form.is_staff_avec_fiche_employe = true;
        }
    },
);

function close() {
    emit('update:open', false);
}

function submit() {
    form.patch(`/backoffice/users/${props.userId}/validate`, {
        preserveScroll: true,
        onSuccess: () => {
            toast.add({
                group: 'top',
                severity: 'success',
                summary: 'Compte validé',
                detail: `${props.userName} peut désormais se connecter.`,
                life: 4000,
            });
            close();
        },
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent class="max-w-lg">
            <DialogHeader>
                <DialogTitle>Valider le compte de {{ userName }}</DialogTitle>
            </DialogHeader>

            <form class="space-y-5" @submit.prevent="submit">
                <div
                    class="flex items-center justify-between rounded-lg border p-3"
                >
                    <div>
                        <p class="text-sm font-medium">Membre du personnel</p>
                        <p class="text-xs text-muted-foreground">
                            Crée ou rattache une fiche employé (fonction RH
                            obligatoire).
                        </p>
                    </div>
                    <Switch v-model="form.is_staff_avec_fiche_employe" />
                </div>

                <div>
                    <Label class="mb-1.5 block"
                        >Profil d'accès
                        <span class="text-destructive">*</span></Label
                    >
                    <Dropdown
                        v-model="form.role_id"
                        :options="roleOptions"
                        option-label="label"
                        option-value="value"
                        filter
                        placeholder="Sélectionner un profil"
                        class="w-full"
                        :class="{ 'p-invalid': form.errors.role_id }"
                    />
                    <p
                        v-if="form.errors.role_id"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ form.errors.role_id }}
                    </p>
                    <p v-else class="mt-1 text-xs text-muted-foreground">
                        Indépendant de la fonction ci-dessous — aucune
                        suggestion automatique.
                    </p>
                </div>

                <div>
                    <Label class="mb-1.5 block"
                        >Site <span class="text-destructive">*</span></Label
                    >
                    <Dropdown
                        v-model="form.site_id"
                        :options="siteOptions"
                        option-label="label"
                        option-value="value"
                        filter
                        placeholder="Sélectionner un site"
                        class="w-full"
                        :class="{ 'p-invalid': form.errors.site_id }"
                    />
                    <p
                        v-if="form.errors.site_id"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ form.errors.site_id }}
                    </p>
                </div>

                <template v-if="form.is_staff_avec_fiche_employe">
                    <div>
                        <Label class="mb-1.5 block"
                            >Fonction RH
                            <span class="text-destructive">*</span></Label
                        >
                        <FonctionRhSelect
                            v-model="form.fonction_rh_id"
                            :fonctions="fonctionOptions"
                            :invalid="!!form.errors.fonction_rh_id"
                            placeholder="Sélectionner une fonction"
                        />
                        <p
                            v-if="form.errors.fonction_rh_id"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ form.errors.fonction_rh_id }}
                        </p>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <Label class="mb-1.5 block"
                                >Type d'employé
                                <span class="text-destructive">*</span></Label
                            >
                            <Dropdown
                                v-model="form.type_employe"
                                :options="typeEmployeOptions"
                                option-label="label"
                                option-value="value"
                                class="w-full"
                                :class="{
                                    'p-invalid': form.errors.type_employe,
                                }"
                            />
                            <p
                                v-if="form.errors.type_employe"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.type_employe }}
                            </p>
                        </div>
                        <div>
                            <Label class="mb-1.5 block"
                                >Statut
                                <span class="text-destructive">*</span></Label
                            >
                            <Dropdown
                                v-model="form.statut"
                                :options="statutOptions"
                                option-label="label"
                                option-value="value"
                                class="w-full"
                                :class="{ 'p-invalid': form.errors.statut }"
                            />
                            <p
                                v-if="form.errors.statut"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.statut }}
                            </p>
                        </div>
                    </div>
                </template>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="close"
                        >Annuler</Button
                    >
                    <Button type="submit" :disabled="form.processing">
                        {{
                            form.processing
                                ? 'Validation…'
                                : 'Valider le compte'
                        }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
