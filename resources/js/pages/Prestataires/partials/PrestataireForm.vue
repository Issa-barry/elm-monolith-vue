<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { paysOptionsByName } from '@/lib/pays';
import { Save } from 'lucide-vue-next';
import Dropdown from 'primevue/dropdown';
import InputText from 'primevue/inputtext';
import Textarea from 'primevue/textarea';
import { computed } from 'vue';

interface Option {
    value: string;
    label: string;
}

const PAYS_OPTIONS = paysOptionsByName;

function flagUrl(code: string) {
    return `https://flagcdn.com/20x15/${code.toLowerCase()}.png`;
}

interface FormData {
    nom: string | null;
    prenom: string | null;
    raison_sociale: string | null;
    email: string | null;
    phone: string | null;
    code_phone_pays: string;
    code_pays: string;
    pays: string;
    ville: string | null;
    adresse: string | null;
    type: string;
    notes: string | null;
    is_active: boolean;
}

const props = defineProps<{
    form: FormData;
    errors: Partial<Record<keyof FormData, string>>;
    types: Option[];
    processing: boolean;
    reference?: string | null;
}>();

const emit = defineEmits<{ submit: []; 'update:form': [FormData] }>();

// Identité — obligatoire dynamique
const raisonSocialeRequired = computed(
    () => !props.form.nom && !props.form.prenom,
);
const nomRequired = computed(() => !props.form.raison_sociale);
const prenomRequired = computed(() => !props.form.raison_sociale);

const selectedCountry = computed(() =>
    PAYS_OPTIONS.find((c) => c.code === props.form.code_pays),
);
const selectedPhoneLength = computed(
    () => selectedCountry.value?.localLength ?? 9,
);
const phoneMaxLength = computed(() => {
    const digits = String(props.form.phone ?? '').replace(/\D/g, '');
    return digits.startsWith('0')
        ? selectedPhoneLength.value + 1
        : selectedPhoneLength.value;
});

function onPaysChange(pays: string) {
    const country = PAYS_OPTIONS.find((c) => c.value === pays);
    if (country) {
        const currentDigits = String(props.form.phone ?? '').replace(/\D/g, '');
        const max = currentDigits.startsWith('0')
            ? country.localLength + 1
            : country.localLength;
        emit('update:form', {
            ...props.form,
            pays: country.value,
            code_pays: country.code,
            code_phone_pays: country.dial,
            phone: currentDigits.slice(0, max) || null,
        });
    }
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
    const digits = raw.slice(0, max);
    emit('update:form', { ...props.form, phone: digits || null });
}
</script>

<template>
    <form
        id="prestataire-form"
        class="space-y-4 sm:space-y-6"
        @submit.prevent="emit('submit')"
    >
        <!-- Identification -->
        <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
            <h3
                class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase sm:mb-5"
            >
                Identification
            </h3>

            <div class="grid gap-5 sm:grid-cols-2">
                <!-- Référence (lecture seule) -->
                <div v-if="reference" class="sm:col-span-2">
                    <Label class="mb-1.5 block">Référence</Label>
                    <div
                        class="flex h-10 items-center rounded-md border bg-muted/40 px-3 font-mono text-sm tracking-widest text-muted-foreground select-all"
                    >
                        {{ reference }}
                    </div>
                </div>

                <!-- Type -->
                <div class="sm:col-span-2">
                    <Label class="mb-1.5 block"
                        >Type <span class="text-destructive">*</span></Label
                    >
                    <Dropdown
                        :model-value="form.type"
                        @update:model-value="
                            $emit('update:form', { ...form, type: $event })
                        "
                        :options="types"
                        option-label="label"
                        option-value="value"
                        class="w-full"
                        :class="{ 'p-invalid': errors.type }"
                    />
                    <p v-if="errors.type" class="mt-1 text-xs text-destructive">
                        {{ errors.type }}
                    </p>
                </div>

                <!-- Raison sociale -->
                <div class="sm:col-span-2">
                    <Label for="raison_sociale" class="mb-1.5 block"
                        >Raison sociale
                        <span
                            v-if="raisonSocialeRequired"
                            class="text-destructive"
                            >*</span
                        >
                        <span class="text-xs text-muted-foreground"
                            >(personne morale)</span
                        ></Label
                    >
                    <InputText
                        id="raison_sociale"
                        :model-value="form.raison_sociale ?? ''"
                        @update:model-value="
                            $emit('update:form', {
                                ...form,
                                raison_sociale: $event || null,
                            })
                        "
                        class="w-full"
                        :class="{ 'p-invalid': errors.raison_sociale }"
                    />
                    <p
                        v-if="errors.raison_sociale"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errors.raison_sociale }}
                    </p>
                </div>

                <!-- Prénom -->
                <div>
                    <Label for="prenom" class="mb-1.5 block"
                        >Prénom
                        <span v-if="prenomRequired" class="text-destructive"
                            >*</span
                        ></Label
                    >
                    <InputText
                        id="prenom"
                        :model-value="form.prenom ?? ''"
                        @update:model-value="
                            $emit('update:form', {
                                ...form,
                                prenom: $event || null,
                            })
                        "
                        class="w-full"
                        :class="{ 'p-invalid': errors.prenom }"
                    />
                    <p
                        v-if="errors.prenom"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errors.prenom }}
                    </p>
                </div>

                <!-- Nom -->
                <div>
                    <Label for="nom" class="mb-1.5 block"
                        >Nom
                        <span v-if="nomRequired" class="text-destructive"
                            >*</span
                        ></Label
                    >
                    <InputText
                        id="nom"
                        :model-value="form.nom ?? ''"
                        @update:model-value="
                            $emit('update:form', {
                                ...form,
                                nom: $event || null,
                            })
                        "
                        class="w-full"
                        :class="{ 'p-invalid': errors.nom }"
                    />
                    <p v-if="errors.nom" class="mt-1 text-xs text-destructive">
                        {{ errors.nom }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Localisation -->
        <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
            <h3
                class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase sm:mb-5"
            >
                Localisation
            </h3>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <Label class="mb-1.5 block"
                        >Pays <span class="text-destructive">*</span></Label
                    >
                    <Dropdown
                        :model-value="form.pays"
                        @update:model-value="onPaysChange($event)"
                        :options="PAYS_OPTIONS"
                        option-label="label"
                        option-value="value"
                        class="w-full"
                        :class="{ 'p-invalid': errors.code_pays }"
                    >
                        <template #value="{ value }">
                            <div v-if="value" class="flex items-center gap-2">
                                <img
                                    :src="
                                        flagUrl(
                                            PAYS_OPTIONS.find(
                                                (c) => c.value === value,
                                            )?.code ?? '',
                                        )
                                    "
                                    class="h-4 w-auto rounded-sm shadow-sm"
                                />
                                <span>{{ value }}</span>
                            </div>
                            <span v-else class="text-muted-foreground"
                                >Sélectionner…</span
                            >
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
                        v-if="errors.code_pays"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errors.code_pays }}
                    </p>
                </div>
                <div>
                    <Label for="ville" class="mb-1.5 block"
                        >Ville <span class="text-destructive">*</span></Label
                    >
                    <InputText
                        id="ville"
                        :model-value="form.ville ?? ''"
                        @update:model-value="
                            $emit('update:form', {
                                ...form,
                                ville: $event || null,
                            })
                        "
                        class="w-full"
                        :class="{ 'p-invalid': errors.ville }"
                    />
                    <p
                        v-if="errors.ville"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errors.ville }}
                    </p>
                </div>
                <div class="sm:col-span-2">
                    <Label for="adresse" class="mb-1.5 block">Adresse</Label>
                    <InputText
                        id="adresse"
                        :model-value="form.adresse ?? ''"
                        @update:model-value="
                            $emit('update:form', {
                                ...form,
                                adresse: $event || null,
                            })
                        "
                        class="w-full"
                    />
                </div>
            </div>
        </div>

        <!-- Contact -->
        <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
            <h3
                class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase sm:mb-5"
            >
                Contact
            </h3>

            <div class="grid gap-5 sm:grid-cols-2">
                <!-- Téléphone -->
                <div>
                    <Label for="phone" class="mb-1.5 block"
                        >Téléphone
                        <span class="text-destructive">*</span></Label
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
                            id="phone"
                            :model-value="form.phone ?? ''"
                            @update:model-value="onPhoneInput($event)"
                            @keydown="handlePhoneKeydown"
                            :placeholder="`${selectedPhoneLength} chiffres`"
                            inputmode="numeric"
                            pattern="[0-9]*"
                            autocomplete="tel-national"
                            :maxlength="phoneMaxLength"
                            class="w-full"
                            :class="{ 'p-invalid': errors.phone }"
                        />
                    </div>
                    <p
                        v-if="errors.phone"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errors.phone }}
                    </p>
                    <p v-else class="mt-1 text-xs text-muted-foreground">
                        Saisissez les chiffres sans indicatif
                    </p>
                </div>

                <!-- Email -->
                <div>
                    <Label for="email" class="mb-1.5 block">Email</Label>
                    <InputText
                        id="email"
                        :model-value="form.email ?? ''"
                        @update:model-value="
                            $emit('update:form', {
                                ...form,
                                email: $event || null,
                            })
                        "
                        type="email"
                        class="w-full"
                        :class="{ 'p-invalid': errors.email }"
                    />
                    <p
                        v-if="errors.email"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errors.email }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Notes & statut -->
        <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
            <h3
                class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase sm:mb-5"
            >
                Notes & Statut
            </h3>

            <div class="grid gap-5">
                <div class="flex items-center gap-3">
                    <Checkbox
                        id="is_active"
                        :model-value="Boolean(form.is_active)"
                        @update:model-value="
                            $emit('update:form', {
                                ...form,
                                is_active: $event === true,
                            })
                        "
                    />
                    <div>
                        <Label
                            for="is_active"
                            class="cursor-pointer font-medium"
                            >Prestataire actif</Label
                        >
                        <p class="text-xs text-muted-foreground">
                            Décochez pour désactiver
                        </p>
                    </div>
                </div>

                <div>
                    <Label for="notes" class="mb-1.5 block">Notes</Label>
                    <Textarea
                        id="notes"
                        :model-value="form.notes ?? ''"
                        @update:model-value="
                            $emit('update:form', {
                                ...form,
                                notes: $event || null,
                            })
                        "
                        :rows="3"
                        class="w-full resize-none"
                    />
                </div>
            </div>
        </div>

        <!-- Pied -->
        <div class="hidden items-center justify-between sm:flex">
            <a href="/prestataires">
                <Button type="button" variant="outline"> Retour </Button>
            </a>
            <Button type="submit" :disabled="processing">
                <Save class="mr-2 h-4 w-4" />
                {{ processing ? 'Enregistrement…' : 'Enregistrer' }}
            </Button>
        </div>
        <div class="h-20 sm:hidden" />
    </form>
</template>
