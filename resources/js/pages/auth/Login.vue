<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthBase from '@/layouts/AuthLayout.vue';
import { home, register } from '@/routes';
import { store } from '@/routes/login';
import { Form, Head, Link } from '@inertiajs/vue3';
import { Eye, EyeOff } from 'lucide-vue-next';
import Select from 'primevue/select';
import { computed, ref, watch } from 'vue';

defineProps<{
    status?: string;
    canResetPassword: boolean;
    canRegister: boolean;
}>();

interface CountryOption {
    label: string;
    code: string;
    prefix: string;
    localLength: number;
}

const PAYS: CountryOption[] = [
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

const STORAGE_KEY = 'login_country_code';
const savedCode = globalThis.localStorage?.getItem(STORAGE_KEY) ?? PAYS[0].code;
const selectedCountryCode = ref(
    PAYS.some((p) => p.code === savedCode) ? savedCode : PAYS[0].code,
);

watch(selectedCountryCode, (code) => {
    globalThis.localStorage?.setItem(STORAGE_KEY, code);
});
const phoneDigits = ref('');
const phoneTouched = ref(false);
const showPassword = ref(false);

const selectedPays = computed(
    () => PAYS.find((p) => p.code === selectedCountryCode.value) ?? PAYS[0],
);

// Si l'utilisateur tape 0xxxxxxxx (format local avec 0 initial), on le strip pour l'international
const fullPhone = computed(() => {
    if (!phoneDigits.value) return '';
    // Strip leading 0 for international format (visible dans l'input, supprimé techniquement)
    return `${selectedPays.value.prefix}${phoneDigits.value.replace(/^0/, '')}`;
});

// Le numéro est valide quand la partie locale (sans le 0 initial) atteint localLength
const phoneIsValid = computed(() => {
    const digits = phoneDigits.value.replace(/^0/, '');
    return digits.length >= selectedPays.value.localLength;
});

// Message client uniquement après interaction avec le champ
const phoneClientError = computed<string | null>(() => {
    if (!phoneTouched.value) return null;
    if (!phoneDigits.value) return 'Numéro de téléphone requis.';
    if (!phoneIsValid.value) {
        return `Numéro trop court (${selectedPays.value.localLength} chiffres attendus).`;
    }
    return null;
});

function flagUrl(code: string): string {
    return `https://flagcdn.com/20x15/${code.toLowerCase()}.png`;
}

function handlePhoneInput(e: Event) {
    const input = e.target as HTMLInputElement;
    const raw = input.value.replace(/\D/g, '');
    // +1 digit autorisé si commence par 0 (ex: 0758855039 = 10 chiffres France)
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
</script>

<template>
    <AuthBase>
        <Head title="Connexion" />

        <Card
            class="mx-auto flex min-h-[calc(100dvh-2rem)] w-full max-w-lg flex-col border-0 bg-transparent shadow-none md:min-h-0 md:rounded-2xl md:border md:border-border/80 md:bg-card/95 md:shadow-2xl md:shadow-black/8 md:dark:shadow-black/35"
        >
            <CardHeader
                class="px-4 pt-10 pb-2 text-center sm:px-6 md:px-8 md:pt-8 md:pb-0"
            >
                <Link
                    :href="home()"
                    class="mx-auto mb-2 flex h-10 w-12 items-center justify-center rounded-md"
                >
                    <AppLogoIcon class="size-10 fill-current text-foreground" />
                    <span class="sr-only">Accueil</span>
                </Link>
                <CardTitle class="text-2xl font-semibold">Connexion</CardTitle>
                <CardDescription class="text-sm">Eau la maman</CardDescription>
            </CardHeader>

            <CardContent
                class="flex flex-1 flex-col px-3 pt-2 pb-[max(1rem,env(safe-area-inset-bottom))] sm:px-4 md:px-10 md:pt-3 md:pb-6"
            >
                <div
                    v-if="status"
                    class="mb-4 text-center text-sm font-medium text-green-600"
                >
                    {{ status }}
                </div>

                <Form
                    v-bind="store.form()"
                    :reset-on-success="['password']"
                    v-slot="{ errors, processing }"
                    class="flex flex-1 flex-col"
                >
                    <!-- Champ téléphone caché (valeur complète) -->
                    <input type="hidden" name="telephone" :value="fullPhone" />

                    <div class="space-y-5">
                        <!-- Sélecteur pays + numéro -->
                        <div class="space-y-2">
                            <Label
                                >Numéro de téléphone
                                <span class="text-destructive">*</span></Label
                            >
                            <div class="flex gap-2">
                                <Select
                                    v-model="selectedCountryCode"
                                    :options="PAYS"
                                    option-label="label"
                                    option-value="code"
                                    :tabindex="1"
                                    class="shrink-0"
                                    :pt="{
                                        root: { class: 'h-10' },
                                        label: {
                                            class: 'flex items-center py-0 h-10',
                                        },
                                    }"
                                >
                                    <template #value="{ value }">
                                        <div
                                            v-if="value"
                                            class="flex items-center gap-2"
                                        >
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
                                            <span
                                                class="ml-auto text-xs text-muted-foreground"
                                                >{{ option.prefix }}</span
                                            >
                                        </div>
                                    </template>
                                </Select>

                                <input
                                    :value="phoneDigits"
                                    @keydown="handlePhoneKeydown"
                                    @input="handlePhoneInput"
                                    @blur="handlePhoneBlur"
                                    type="tel"
                                    :tabindex="2"
                                    autocomplete="tel-national"
                                    inputmode="numeric"
                                    autofocus
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
                            <InputError
                                :message="phoneClientError ?? errors.telephone"
                            />
                        </div>

                        <!-- Mot de passe -->
                        <div class="space-y-2">
                            <Label for="password">
                                Mot de passe
                                <span class="text-destructive">*</span>
                            </Label>
                            <div class="relative">
                                <input
                                    id="password"
                                    :type="showPassword ? 'text' : 'password'"
                                    name="password"
                                    required
                                    :tabindex="3"
                                    autocomplete="current-password"
                                    placeholder="Mot de passe"
                                    class="flex h-10 w-full rounded-md border border-input bg-transparent px-3 py-1 pr-10 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                />
                                <button
                                    type="button"
                                    class="absolute inset-y-0 right-0 inline-flex w-10 items-center justify-center text-muted-foreground transition-colors hover:text-foreground"
                                    @click="showPassword = !showPassword"
                                    :aria-label="
                                        showPassword
                                            ? 'Masquer le mot de passe'
                                            : 'Afficher le mot de passe'
                                    "
                                >
                                    <component
                                        :is="showPassword ? EyeOff : Eye"
                                        class="h-4 w-4"
                                    />
                                </button>
                            </div>
                            <InputError :message="errors.password" />
                        </div>
                    </div>

                    <div
                        class="mt-5 flex items-center justify-between gap-3 text-sm"
                    >
                        <Label
                            for="remember"
                            class="flex items-center space-x-2"
                        >
                            <Checkbox
                                id="remember"
                                name="remember"
                                :tabindex="4"
                            />
                            <span>Se souvenir de moi</span>
                        </Label>
                    </div>

                    <div class="mt-auto space-y-4 pt-6">
                        <Button
                            type="submit"
                            class="h-10 w-full rounded-xl text-base font-semibold"
                            :tabindex="5"
                            :disabled="processing || !phoneIsValid"
                            data-test="login-button"
                        >
                            <Spinner v-if="processing" />
                            Se connecter
                        </Button>

                        <div
                            class="text-center text-sm text-muted-foreground"
                            v-if="canRegister"
                        >
                            Pas encore de compte ?
                            <TextLink :href="register()" :tabindex="6"
                                >S'inscrire</TextLink
                            >
                        </div>
                    </div>
                </Form>
            </CardContent>
        </Card>
    </AuthBase>
</template>
