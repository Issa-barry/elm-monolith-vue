<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import InputText from 'primevue/inputtext';
import { computed, reactive, ref, watch } from 'vue';

export interface MembreFormData {
    livreur_id: string | null;
    nom: string;
    prenom: string;
    telephone: string;
    role: string;
    ordre: number;
    montant_par_pack: number;
}

const GUINEA_PREFIX = '+224';
const GUINEA_LOCAL_LENGTH = 9;

const roles = [
    { value: 'chauffeur', label: 'Chauffeur' },
    { value: 'convoyeur', label: 'Convoyeur' },
];

const props = defineProps<{
    visible: boolean;
    membre?: MembreFormData | null;
    telephoneError?: string | null;
}>();

const emit = defineEmits<{
    'update:visible': [boolean];
    confirm: [MembreFormData];
}>();

const isEdit = computed(() => !!props.membre);
const title = computed(() =>
    isEdit.value ? 'Modifier le membre' : 'Nouveau membre',
);
const canSubmit = computed(
    () =>
        form.prenom.trim().length > 0 &&
        form.nom.trim().length > 0 &&
        /^\d{9}$/.test(phoneLocal.value),
);

const form = reactive<MembreFormData>({
    livreur_id: null,
    nom: '',
    prenom: '',
    telephone: '',
    role: 'chauffeur',
    ordre: 0,
    montant_par_pack: 0,
});

const phoneLocal = ref('');

const errors = reactive<Partial<Record<keyof MembreFormData, string>>>({});

function extractLocalDigits(phone: string): string {
    if (phone.startsWith(GUINEA_PREFIX)) {
        return phone.slice(GUINEA_PREFIX.length);
    }
    return phone.replace(/\D/g, '').slice(-GUINEA_LOCAL_LENGTH);
}

watch(
    () => props.visible,
    (val) => {
        if (!val) return;
        (Object.keys(errors) as (keyof MembreFormData)[]).forEach(
            (k) => delete errors[k],
        );

        if (props.membre) {
            Object.assign(form, props.membre);
            phoneLocal.value = extractLocalDigits(props.membre.telephone);
        } else {
            Object.assign(form, {
                livreur_id: null,
                nom: '',
                prenom: '',
                telephone: '',
                role: 'chauffeur',
                ordre: 0,
                montant_par_pack: 0,
            });
            phoneLocal.value = '';
        }
    },
);

function handlePhoneKeydown(e: KeyboardEvent) {
    const pass = [
        'Backspace',
        'Delete',
        'Tab',
        'Escape',
        'Enter',
        'ArrowLeft',
        'ArrowRight',
        'ArrowUp',
        'ArrowDown',
        'Home',
        'End',
    ];
    if (pass.includes(e.key)) return;
    if (
        (e.ctrlKey || e.metaKey) &&
        ['a', 'c', 'v', 'x'].includes(e.key.toLowerCase())
    )
        return;
    if (!/^\d$/.test(e.key)) e.preventDefault();
}

function onPhoneInput(e: Event) {
    const raw = (e.target as HTMLInputElement).value.replace(/\D/g, '');
    phoneLocal.value = raw.slice(0, GUINEA_LOCAL_LENGTH);
    (e.target as HTMLInputElement).value = phoneLocal.value;
}

function validate(): boolean {
    (Object.keys(errors) as (keyof MembreFormData)[]).forEach(
        (k) => delete errors[k],
    );

    if (!form.prenom.trim()) errors.prenom = 'Le prénom est obligatoire.';
    if (!form.nom.trim()) errors.nom = 'Le nom est obligatoire.';

    if (!phoneLocal.value.trim()) {
        errors.telephone = 'Le téléphone est obligatoire.';
    } else if (!/^\d{9}$/.test(phoneLocal.value)) {
        errors.telephone = `Le téléphone doit comporter exactement ${GUINEA_LOCAL_LENGTH} chiffres.`;
    }

    return Object.keys(errors).length === 0;
}

function handleConfirm() {
    if (!validate()) return;
    emit('confirm', {
        ...form,
        telephone: `${GUINEA_PREFIX}${phoneLocal.value}`,
    });
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
                <div
                    class="flex h-10 overflow-hidden rounded-md border"
                    :class="
                        errors.telephone ? 'border-destructive' : 'border-input'
                    "
                >
                    <span
                        class="flex items-center gap-1.5 border-r bg-muted px-3 text-sm text-muted-foreground select-none"
                    >
                        <img
                            src="https://flagcdn.com/20x15/gn.png"
                            width="20"
                            height="15"
                            alt="Guinée"
                        />
                        +224
                    </span>
                    <input
                        id="membre-telephone"
                        type="tel"
                        inputmode="numeric"
                        :maxlength="9"
                        :value="phoneLocal"
                        placeholder="9 chiffres"
                        class="flex-1 bg-background px-3 text-sm outline-none placeholder:text-muted-foreground"
                        @input="onPhoneInput"
                        @keydown="handlePhoneKeydown"
                        @keyup.enter="handleConfirm"
                    />
                </div>
                <p
                    v-if="errors.telephone || telephoneError"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ errors.telephone || telephoneError }}
                </p>
            </div>

            <!-- Rôle -->
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
                <Button
                    type="button"
                    size="sm"
                    :disabled="!canSubmit"
                    @click="handleConfirm"
                >
                    {{ isEdit ? 'Enregistrer' : 'Ajouter' }}
                </Button>
            </div>
        </template>
    </Dialog>
</template>
