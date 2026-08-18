<script setup lang="ts">
/**
 * Saisie téléphone avec sélecteur de pays — partagée entre Login.vue et Install/Wizard.vue pour
 * qu'ils n'aient plus chacun leur propre implémentation (liste de pays dupliquée, filtrage
 * clavier, gestion du "0" initial...). Liste complète par défaut (des comptes existants —
 * démo "elm" comprise — utilisent des numéros hors Guinée/Sierra Leone, donc la restreindre
 * casse la connexion pour ces comptes). Un appelant peut passer une liste plus courte via la
 * prop `countries` s'il a besoin de restreindre la couverture pays (ex. Install/Wizard.vue pour
 * le lancement initial). La validation/normalisation serveur (PhoneCountryInfo, libphonenumber)
 * reste, elle, la référence et n'est pas limitée à cette liste.
 *
 * Deux modes d'usage :
 * - v-model (Wizard.vue, formulaire Inertia réactif via useForm) : `v-model="form.telephone"`.
 * - `name` (Login.vue, formulaire natif via <Form>) : rend un <input type="hidden" :name>
 *   interne avec le numéro complet, pas besoin de gérer l'état côté parent.
 */
import Select from 'primevue/select';
import { computed, ref, watch } from 'vue';

interface CountryOption {
    label: string;
    code: string;
    prefix: string;
    localLength: number;
}

const DEFAULT_PAYS: CountryOption[] = [
    { label: 'Guinée', code: 'GN', prefix: '+224', localLength: 9 },
    { label: 'Guinée-Bissau', code: 'GW', prefix: '+245', localLength: 7 },
    { label: 'Sénégal', code: 'SN', prefix: '+221', localLength: 9 },
    { label: 'Mali', code: 'ML', prefix: '+223', localLength: 8 },
    { label: "Côte d'Ivoire", code: 'CI', prefix: '+225', localLength: 10 },
    { label: 'Liberia', code: 'LR', prefix: '+231', localLength: 8 },
    { label: 'Sierra Leone', code: 'SL', prefix: '+232', localLength: 8 },
    { label: 'France', code: 'FR', prefix: '+33', localLength: 9 },
    { label: 'Chine', code: 'CN', prefix: '+86', localLength: 11 },
    {
        label: 'Émirats arabes unis',
        code: 'AE',
        prefix: '+971',
        localLength: 9,
    },
    { label: 'Inde', code: 'IN', prefix: '+91', localLength: 10 },
];

const props = withDefaults(
    defineProps<{
        modelValue?: string;
        name?: string;
        id?: string;
        autofocus?: boolean;
        tabindexCountry?: number;
        tabindexInput?: number;
        countries?: CountryOption[];
    }>(),
    {
        modelValue: '',
        name: undefined,
        id: 'telephone',
        autofocus: false,
        tabindexCountry: undefined,
        tabindexInput: undefined,
        // Pas de `() => DEFAULT_PAYS` ici : le compilateur <script setup> interdit à la valeur
        // par défaut de defineProps() de référencer une variable du scope module (hoisting) —
        // paysOptions ci-dessous retombe déjà sur DEFAULT_PAYS quand countries est absent/vide.
        countries: undefined,
    },
);

const paysOptions = computed(() =>
    props.countries?.length ? props.countries : DEFAULT_PAYS,
);

const emit = defineEmits<{
    'update:modelValue': [string];
    'update:valid': [boolean];
}>();

const STORAGE_KEY = 'login_country_code';
const savedCode =
    globalThis.localStorage?.getItem(STORAGE_KEY) ?? paysOptions.value[0].code;
const selectedCountryCode = ref(
    paysOptions.value.some((p) => p.code === savedCode)
        ? savedCode
        : paysOptions.value[0].code,
);

watch(selectedCountryCode, (code) => {
    globalThis.localStorage?.setItem(STORAGE_KEY, code);
});

const selectedPays = computed(
    () =>
        paysOptions.value.find((p) => p.code === selectedCountryCode.value) ??
        paysOptions.value[0],
);

const phoneDigits = ref('');
const phoneTouched = ref(false);

// Réhydratation depuis modelValue (ex: erreur de validation renvoyée avec le formulaire déjà
// rempli) — best-effort : si le préfixe correspond à un des pays supportés, on en repart.
if (props.modelValue) {
    const match = paysOptions.value.find((p) =>
        props.modelValue!.startsWith(p.prefix),
    );
    if (match) {
        selectedCountryCode.value = match.code;
        phoneDigits.value = props.modelValue!.slice(match.prefix.length);
    }
}

const fullPhone = computed(() => {
    if (!phoneDigits.value) return '';

    return `${selectedPays.value.prefix}${phoneDigits.value.replace(/^0/, '')}`;
});

const phoneIsValid = computed(() => {
    const digits = phoneDigits.value.replace(/^0/, '');

    return digits.length >= selectedPays.value.localLength;
});

const phoneClientError = computed<string | null>(() => {
    if (!phoneTouched.value) return null;
    if (!phoneDigits.value) return 'Numéro de téléphone requis.';
    if (!phoneIsValid.value) {
        return `Numéro trop court (${selectedPays.value.localLength} chiffres attendus).`;
    }

    return null;
});

watch(fullPhone, (value) => emit('update:modelValue', value));
watch(phoneIsValid, (value) => emit('update:valid', value), {
    immediate: true,
});

function flagUrl(code: string): string {
    return `https://flagcdn.com/20x15/${code.toLowerCase()}.png`;
}

function handlePhoneInput(e: Event) {
    const input = e.target as HTMLInputElement;
    const raw = input.value.replace(/\D/g, '');
    const max = raw.startsWith('0')
        ? selectedPays.value.localLength + 1
        : selectedPays.value.localLength;
    const digits = raw.slice(0, max);
    phoneDigits.value = digits;
    input.value = digits;
}

function handlePhoneBlur() {
    phoneTouched.value = true;
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

defineExpose({ phoneIsValid, phoneClientError });
</script>

<template>
    <div>
        <input v-if="name" type="hidden" :name="name" :value="fullPhone" />

        <div class="flex gap-2">
            <Select
                v-model="selectedCountryCode"
                :options="paysOptions"
                option-label="label"
                option-value="code"
                :tabindex="tabindexCountry"
                class="shrink-0"
                :pt="{
                    root: { class: 'h-10' },
                    label: { class: 'flex items-center py-0 h-10' },
                }"
            >
                <template #value="{ value }">
                    <div v-if="value" class="flex items-center gap-2">
                        <img
                            :src="flagUrl(value)"
                            class="h-4 w-auto rounded-sm shadow-sm"
                        />
                        <span class="font-mono text-sm">{{
                            selectedPays.prefix
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
                        <span class="ml-auto text-xs text-muted-foreground">{{
                            option.prefix
                        }}</span>
                    </div>
                </template>
            </Select>

            <input
                :id="id"
                :value="phoneDigits"
                @keydown="handlePhoneKeydown"
                @input="handlePhoneInput"
                @blur="handlePhoneBlur"
                type="tel"
                :tabindex="tabindexInput"
                autocomplete="tel-national"
                inputmode="numeric"
                :autofocus="autofocus"
                required
                :maxlength="
                    phoneDigits.startsWith('0')
                        ? selectedPays.localLength + 1
                        : selectedPays.localLength
                "
                :placeholder="`${selectedPays.localLength} chiffres`"
                class="flex h-10 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
            />
        </div>
        <p v-if="phoneClientError" class="mt-1.5 text-xs text-destructive">
            {{ phoneClientError }}
        </p>
    </div>
</template>
