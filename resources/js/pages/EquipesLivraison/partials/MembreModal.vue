<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import { computed, reactive, watch } from 'vue';

export interface MembreFormData {
    livreur_id: number | null;
    nom: string;
    prenom: string;
    telephone: string;
    role: string;
    taux_commission: number;
    ordre: number;
}

const props = defineProps<{
    visible: boolean;
    membre?: MembreFormData | null;
    hasPrincipal?: boolean;
    maxTaux?: number;
}>();

const emit = defineEmits<{
    'update:visible': [boolean];
    confirm: [MembreFormData];
}>();

const roles = [
    { value: 'principal', label: 'Principal' },
    { value: 'assistant', label: 'Assistant' },
];

const isEdit = computed(() => !!props.membre);
const title = computed(() =>
    isEdit.value ? 'Modifier le membre' : 'Nouveau membre',
);
const maxTauxSafe = computed(() => {
    const raw = Number(props.maxTaux ?? 100);
    if (!Number.isFinite(raw)) return 100;
    return Math.max(0, Math.min(100, raw));
});

const form = reactive<MembreFormData>({
    livreur_id: null,
    nom: '',
    prenom: '',
    telephone: '',
    role: 'assistant',
    taux_commission: 0,
    ordre: 0,
});

const errors = reactive<Partial<Record<keyof MembreFormData, string>>>({});

watch(
    () => props.visible,
    (val) => {
        if (!val) return;
        (Object.keys(errors) as (keyof MembreFormData)[]).forEach(
            (k) => delete errors[k],
        );

        if (props.membre) {
            Object.assign(form, props.membre);
        } else {
            Object.assign(form, {
                livreur_id: null,
                nom: '',
                prenom: '',
                telephone: '',
                role: props.hasPrincipal ? 'assistant' : 'principal',
                taux_commission: 0,
                ordre: 0,
            });
        }
    },
);

function validate(): boolean {
    (Object.keys(errors) as (keyof MembreFormData)[]).forEach(
        (k) => delete errors[k],
    );

    if (!form.prenom.trim()) errors.prenom = 'Le prénom est obligatoire.';
    if (!form.nom.trim()) errors.nom = 'Le nom est obligatoire.';
    if (!form.telephone.trim())
        errors.telephone = 'Le téléphone est obligatoire.';
    const taux = Number(form.taux_commission);
    if (!Number.isFinite(taux)) {
        errors.taux_commission = 'Le taux est obligatoire.';
    } else if (taux < 0) {
        errors.taux_commission = 'Le taux ne peut pas être négatif.';
    } else if (taux > maxTauxSafe.value) {
        errors.taux_commission = `Le taux ne peut pas dépasser ${maxTauxSafe.value} %.`;
    }

    return Object.keys(errors).length === 0;
}

function handleConfirm() {
    if (!validate()) return;
    emit('confirm', { ...form });
    emit('update:visible', false);
}
</script>

<template>
    <Dialog
        :visible="visible"
        modal
        :header="title"
        :style="{ width: 'min(480px, 95vw)' }"
        :dismissable-mask="true"
        :pt="{
            content: { style: 'overflow: visible' },
        }"
        @update:visible="emit('update:visible', $event)"
    >
        <div class="space-y-4 pt-2 pb-1">
            <!-- Prénom + Nom -->
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <Label
                        for="membre-prenom"
                        class="mb-1 block text-xs font-medium"
                    >
                        Prénom <span class="text-destructive">*</span>
                    </Label>
                    <InputText
                        id="membre-prenom"
                        v-model="form.prenom"
                        class="w-full"
                        :class="{ 'p-invalid': errors.prenom }"
                        autofocus
                        @keyup.enter="handleConfirm"
                    />
                    <p
                        v-if="errors.prenom"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errors.prenom }}
                    </p>
                </div>
                <div>
                    <Label
                        for="membre-nom"
                        class="mb-1 block text-xs font-medium"
                    >
                        Nom <span class="text-destructive">*</span>
                    </Label>
                    <InputText
                        id="membre-nom"
                        v-model="form.nom"
                        class="w-full"
                        :class="{ 'p-invalid': errors.nom }"
                        @keyup.enter="handleConfirm"
                    />
                    <p v-if="errors.nom" class="mt-1 text-xs text-destructive">
                        {{ errors.nom }}
                    </p>
                </div>
            </div>

            <!-- Téléphone -->
            <div>
                <Label
                    for="membre-telephone"
                    class="mb-1 block text-xs font-medium"
                >
                    Téléphone <span class="text-destructive">*</span>
                </Label>
                <InputText
                    id="membre-telephone"
                    v-model="form.telephone"
                    class="w-full"
                    :class="{ 'p-invalid': errors.telephone }"
                    @keyup.enter="handleConfirm"
                />
                <p
                    v-if="errors.telephone"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ errors.telephone }}
                </p>
            </div>

            <!-- Rôle + Taux -->
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <Label
                        for="membre-role"
                        class="mb-1 block text-xs font-medium"
                        >Rôle</Label
                    >
                    <Dropdown
                        v-model="form.role"
                        input-id="membre-role"
                        :options="roles"
                        option-label="label"
                        option-value="value"
                        class="w-full"
                    />
                </div>
                <div>
                    <Label
                        for="membre-taux"
                        class="mb-1 block text-xs font-medium"
                    >
                        Taux (%) <span class="text-destructive">*</span>
                    </Label>
                    <InputNumber
                        v-model="form.taux_commission"
                        input-id="membre-taux"
                        :min="0"
                        :max="maxTauxSafe"
                        :max-fraction-digits="2"
                        suffix=" %"
                        class="w-full"
                        :input-style="{ textAlign: 'right', width: '100%' }"
                    />
                    <p
                        v-if="maxTauxSafe < 100"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        Maximum disponible : {{ maxTauxSafe }}%
                    </p>
                    <p
                        v-if="errors.taux_commission"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errors.taux_commission }}
                    </p>
                </div>
            </div>
        </div>

        <template #footer>
            <div class="flex justify-end gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="emit('update:visible', false)"
                >
                    Annuler
                </Button>
                <Button type="button" size="sm" @click="handleConfirm">
                    {{ isEdit ? 'Enregistrer' : 'Ajouter' }}
                </Button>
            </div>
        </template>
    </Dialog>
</template>
