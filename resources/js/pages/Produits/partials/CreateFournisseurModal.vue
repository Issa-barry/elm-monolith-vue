<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { paysOptionsByCode } from '@/lib/pays';
import { useForm, usePage } from '@inertiajs/vue3';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import InputText from 'primevue/inputtext';
import { computed } from 'vue';

// Même liste pays/indicatif que Prestataires/Proprietaires (resources/js/lib/pays.ts) —
// pas de deuxième logique téléphone, cf. PrestataireForm.vue pour le patron d'origine.
const PAYS_OPTIONS = paysOptionsByCode;

function flagUrl(code: string) {
    return `https://flagcdn.com/20x15/${code.toLowerCase()}.png`;
}

const props = defineProps<{
    visible: boolean;
}>();

const emit = defineEmits<{
    'update:visible': [boolean];
    /** Émis avec l'id du fournisseur tout juste créé, pour sélection automatique. */
    created: [string];
}>();

const localVisible = computed({
    get: () => props.visible,
    set: (val: boolean) => emit('update:visible', val),
});

const form = useForm({
    raison_sociale: '',
    code_pays: 'GN',
    code_phone_pays: '+224',
    phone: null as string | null,
    ville: null as string | null,
    adresse: null as string | null,
});

const selectedCountry = computed(() =>
    PAYS_OPTIONS.find((c) => c.code === form.code_pays),
);
const selectedPhoneLength = computed(
    () => selectedCountry.value?.localLength ?? 9,
);

function onPaysChange(codePays: string) {
    const country = PAYS_OPTIONS.find((c) => c.value === codePays);
    if (!country) return;
    const currentDigits = String(form.phone ?? '').replace(/\D/g, '');
    const max = currentDigits.startsWith('0')
        ? country.localLength + 1
        : country.localLength;
    form.code_pays = country.code;
    form.code_phone_pays = country.dial;
    form.phone = currentDigits.slice(0, max) || null;
}

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

function onPhoneInput(value: string | null | undefined) {
    const raw = String(value ?? '').replace(/\D/g, '');
    const max = raw.startsWith('0')
        ? selectedPhoneLength.value + 1
        : selectedPhoneLength.value;
    form.phone = raw.slice(0, max) || null;
}

function close() {
    localVisible.value = false;
    form.reset();
    form.clearErrors();
}

function submit() {
    form.post('/backoffice/produits/fournisseurs', {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            // Flashé par PrestataireController::storeRapide().
            const page = usePage();
            const createdId = (page.props as any).flash
                ?.created_fournisseur_id as string | undefined;
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
        header="Nouveau fournisseur"
        :style="{ width: '28rem' }"
        :draggable="false"
        @hide="
            form.reset();
            form.clearErrors();
        "
    >
        <div class="space-y-4">
            <div class="space-y-1.5">
                <Label for="fournisseur-nom"
                    >Nom entreprise / Nom complet
                    <span class="text-destructive">*</span></Label
                >
                <InputText
                    id="fournisseur-nom"
                    v-model="form.raison_sociale"
                    class="w-full"
                    :class="form.errors.raison_sociale ? 'p-invalid' : ''"
                    autofocus
                    @keyup.enter="submit"
                />
                <p
                    v-if="form.errors.raison_sociale"
                    class="text-xs text-destructive"
                >
                    {{ form.errors.raison_sociale }}
                </p>
            </div>

            <div class="space-y-1.5">
                <Label for="fournisseur-pays"
                    >Pays <span class="text-destructive">*</span></Label
                >
                <Dropdown
                    input-id="fournisseur-pays"
                    :model-value="form.code_pays"
                    @update:model-value="onPaysChange($event)"
                    :options="PAYS_OPTIONS"
                    option-label="label"
                    option-value="value"
                    class="w-full"
                    :class="{ 'p-invalid': form.errors.code_pays }"
                >
                    <template #value="{ value }">
                        <div v-if="value" class="flex items-center gap-2">
                            <img
                                :src="flagUrl(value)"
                                class="h-4 w-auto rounded-sm shadow-sm"
                            />
                            <span>{{
                                PAYS_OPTIONS.find((c) => c.value === value)
                                    ?.label
                            }}</span>
                        </div>
                    </template>
                    <template #option="{ option }">
                        <div class="flex items-center gap-2">
                            <img
                                :src="flagUrl(option.code)"
                                :alt="option.label"
                                class="h-4 w-auto rounded-sm shadow-sm"
                            />
                            <span>{{ option.label }}</span>
                        </div>
                    </template>
                </Dropdown>
                <p
                    v-if="form.errors.code_pays"
                    class="text-xs text-destructive"
                >
                    {{ form.errors.code_pays }}
                </p>
            </div>

            <div class="space-y-1.5">
                <Label for="fournisseur-phone"
                    >Téléphone <span class="text-destructive">*</span></Label
                >
                <div class="flex gap-2">
                    <div
                        class="flex h-10 w-24 shrink-0 items-center justify-center gap-1.5 rounded-md border bg-muted/40 px-2 font-mono text-sm text-muted-foreground"
                    >
                        <img
                            v-if="selectedCountry"
                            :src="flagUrl(selectedCountry.code)"
                            :alt="selectedCountry.label"
                            class="h-4 w-auto rounded-sm shadow-sm"
                        />
                        <span>{{ form.code_phone_pays }}</span>
                    </div>
                    <InputText
                        id="fournisseur-phone"
                        :model-value="form.phone ?? ''"
                        @update:model-value="onPhoneInput($event)"
                        @keydown="handlePhoneKeydown"
                        :placeholder="`${selectedPhoneLength} chiffres`"
                        inputmode="numeric"
                        class="w-full font-mono"
                        :class="{ 'p-invalid': form.errors.phone }"
                        @keyup.enter="submit"
                    />
                </div>
                <p v-if="form.errors.phone" class="text-xs text-destructive">
                    {{ form.errors.phone }}
                </p>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-1.5">
                    <Label for="fournisseur-ville">Ville</Label>
                    <InputText
                        id="fournisseur-ville"
                        v-model="form.ville"
                        class="w-full"
                        @keyup.enter="submit"
                    />
                </div>
                <div class="space-y-1.5">
                    <Label for="fournisseur-adresse">Adresse / Quartier</Label>
                    <InputText
                        id="fournisseur-adresse"
                        v-model="form.adresse"
                        class="w-full"
                        @keyup.enter="submit"
                    />
                </div>
            </div>
        </div>

        <template #footer>
            <div class="flex justify-end gap-2">
                <Button variant="outline" @click="close">Annuler</Button>
                <Button
                    :disabled="
                        form.processing ||
                        !form.raison_sociale.trim() ||
                        !form.phone
                    "
                    @click="submit"
                >
                    Créer le fournisseur
                </Button>
            </div>
        </template>
    </Dialog>
</template>
