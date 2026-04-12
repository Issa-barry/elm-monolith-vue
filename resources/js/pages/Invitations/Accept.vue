<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { AlertCircle, CheckCircle, Lock, MailCheck } from 'lucide-vue-next';
import Select from 'primevue/select';
import { computed, ref } from 'vue';

// ── Props ─────────────────────────────────────────────────────────────────────

const props = defineProps<{
    token?: string;
    email?: string;
    role?: string;
    site_nom?: string;
    error?: 'not_found' | 'already_accepted' | 'revoked' | 'expired';
}>();

// ── Types ─────────────────────────────────────────────────────────────────────

interface CountryOption {
    label: string;
    code: string;
    prefix: string;
    localLength: number;
}

type Step = 'phone' | 'otp' | 'identity' | 'password';

// ── Pays ──────────────────────────────────────────────────────────────────────

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

// ── Téléphone ─────────────────────────────────────────────────────────────────

const selectedCountryCode = ref(PAYS[0].code);
const phoneDigits = ref('');

const selectedPays = computed(
    () => PAYS.find((p) => p.code === selectedCountryCode.value) ?? PAYS[0],
);

const fullPhone = computed(() => {
    if (!phoneDigits.value) return '';
    return `${selectedPays.value.prefix}${phoneDigits.value.replace(/^0/, '')}`;
});

const isPhoneValid = computed(() => {
    const digits = phoneDigits.value.replace(/^0/, '');
    return digits.length === selectedPays.value.localLength;
});

function flagUrl(code: string): string {
    return `https://flagcdn.com/20x15/${code.toLowerCase()}.png`;
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

// ── État multi-étapes ─────────────────────────────────────────────────────────

const step = ref<Step>('phone');
const loading = ref(false);

const lookupError = ref('');
const otpCode = ref('');
const otpError = ref('');
const formPrenom = ref('');
const formNom = ref('');
const isPrefilled = ref(false);

const form = useForm({
    telephone: '',
    code_pays: '',
    prenom: '',
    nom: '',
    password: '',
});

// ── Helpers API ───────────────────────────────────────────────────────────────

function getCsrfToken(): string {
    return decodeURIComponent(
        document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
    );
}

async function apiFetch<T>(
    url: string,
    body: Record<string, string>,
): Promise<T> {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': getCsrfToken(),
        },
        body: JSON.stringify(body),
    });

    const json = await response.json();

    if (!response.ok) {
        const msg =
            json?.errors?.telephone?.[0] ??
            json?.errors?.code?.[0] ??
            json?.error ??
            json?.message ??
            'Une erreur est survenue.';
        throw new Error(msg);
    }

    return json as T;
}

// ── Étape 1 : téléphone ───────────────────────────────────────────────────────

async function submitPhoneLookup() {
    if (!isPhoneValid.value) return;
    loading.value = true;
    lookupError.value = '';

    try {
        const data = await apiFetch<{
            status: string;
            prefill?: { prenom: string; nom: string };
        }>(`/invitations/accept/${props.token}/phone`, {
            telephone: fullPhone.value,
        });

        if (data.status === 'user_exists') {
            lookupError.value =
                'Ce numéro est déjà associé à un compte. Connectez-vous à votre espace pour accéder à cette invitation.';
            return;
        }

        if (data.prefill) {
            formPrenom.value = data.prefill.prenom;
            formNom.value = data.prefill.nom;
            isPrefilled.value = true;
        } else {
            formPrenom.value = '';
            formNom.value = '';
            isPrefilled.value = false;
        }

        step.value = 'otp';
    } catch (e: unknown) {
        lookupError.value =
            e instanceof Error ? e.message : 'Une erreur est survenue.';
    } finally {
        loading.value = false;
    }
}

// ── Étape 2 : OTP ─────────────────────────────────────────────────────────────

async function submitOtp() {
    loading.value = true;
    otpError.value = '';

    try {
        await apiFetch(`/invitations/accept/${props.token}/otp`, {
            telephone: fullPhone.value,
            code: otpCode.value,
        });

        step.value = 'identity';
    } catch (e: unknown) {
        otpError.value = e instanceof Error ? e.message : 'Code incorrect.';
    } finally {
        loading.value = false;
    }
}

function backToPhone() {
    step.value = 'phone';
    otpCode.value = '';
    otpError.value = '';
}

// ── Étape 4 : soumission finale ───────────────────────────────────────────────

function submitAccept() {
    form.telephone = fullPhone.value;
    form.code_pays = selectedCountryCode.value;
    form.prenom = formPrenom.value;
    form.nom = formNom.value;

    form.post(`/invitations/accept/${props.token}`, {
        preserveState: true,
        onError: () => {
            // Les erreurs restent dans form.errors, on reste sur l'étape password
        },
    });
}
</script>

<template>
    <AuthBase
        :title="
            error ? 'Invitation invalide' : `Rejoindre ${site_nom ?? 'le site'}`
        "
        :description="
            error
                ? ''
                : `Créez votre compte pour rejoindre ${site_nom} en tant que ${role}.`
        "
    >
        <Head title="Accepter l'invitation" />

        <!-- ── États d'erreur ──────────────────────────────────────────────── -->
        <div v-if="error" class="flex flex-col items-center gap-4 py-4">
            <AlertCircle class="h-12 w-12 text-destructive opacity-70" />

            <div class="text-center">
                <p
                    v-if="error === 'not_found'"
                    class="text-sm text-muted-foreground"
                >
                    Ce lien d'invitation est invalide ou introuvable.
                </p>
                <p
                    v-else-if="error === 'already_accepted'"
                    class="text-sm text-muted-foreground"
                >
                    Cette invitation a déjà été acceptée. Connectez-vous pour
                    accéder à votre compte.
                </p>
                <p
                    v-else-if="error === 'revoked'"
                    class="text-sm text-muted-foreground"
                >
                    Cette invitation a été révoquée. Contactez l'administrateur
                    pour obtenir une nouvelle invitation.
                </p>
                <p
                    v-else-if="error === 'expired'"
                    class="text-sm text-muted-foreground"
                >
                    Ce lien d'invitation a expiré (valable 24 h). Demandez un
                    renvoi à l'administrateur.
                </p>
            </div>
        </div>

        <!-- ── Formulaire multi-étapes ────────────────────────────────────── -->
        <div v-else class="flex flex-col gap-6">
            <!-- Bandeau email invitation -->
            <div
                class="flex items-center gap-2 rounded-md border border-border bg-muted/50 px-4 py-3 text-sm"
            >
                <MailCheck class="h-4 w-4 shrink-0 text-emerald-500" />
                <span class="text-muted-foreground">Invitation pour</span>
                <span class="ml-1 font-medium text-foreground">{{
                    email
                }}</span>
            </div>

            <!-- ── Étape 1 : Téléphone ────────────────────────────────────── -->
            <div v-if="step === 'phone'" class="grid gap-6">
                <div class="grid gap-2">
                    <Label>
                        Téléphone <span class="text-destructive">*</span>
                    </Label>

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
                            type="tel"
                            :tabindex="2"
                            autocomplete="tel-national"
                            inputmode="numeric"
                            pattern="[0-9]*"
                            :maxlength="
                                phoneDigits.startsWith('0')
                                    ? selectedPays.localLength + 1
                                    : selectedPays.localLength
                            "
                            :placeholder="`${selectedPays.localLength} chiffres`"
                            class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm"
                        />
                    </div>

                    <p class="text-xs text-muted-foreground">
                        Saisissez uniquement les chiffres, sans indicatif.
                    </p>
                    <InputError :message="lookupError" />
                </div>

                <Button
                    type="button"
                    class="mt-2 w-full"
                    :tabindex="3"
                    :disabled="loading || !isPhoneValid"
                    @click="submitPhoneLookup"
                >
                    <Spinner v-if="loading" />
                    Continuer
                </Button>
            </div>

            <!-- ── Étape 2 : OTP ──────────────────────────────────────────── -->
            <div v-else-if="step === 'otp'" class="grid gap-6">
                <p class="text-sm text-muted-foreground">
                    Un code de vérification a été envoyé au
                    <span class="font-medium text-foreground">{{
                        fullPhone
                    }}</span
                    >.
                </p>

                <div class="grid gap-2">
                    <Label for="otp">Code de vérification</Label>
                    <Input
                        id="otp"
                        v-model="otpCode"
                        type="text"
                        inputmode="numeric"
                        pattern="[0-9]*"
                        maxlength="5"
                        :tabindex="1"
                        autocomplete="one-time-code"
                        placeholder="12345"
                    />
                    <InputError :message="otpError" />
                </div>

                <Button
                    type="button"
                    class="w-full"
                    :tabindex="2"
                    :disabled="loading || otpCode.length !== 5"
                    @click="submitOtp"
                >
                    <Spinner v-if="loading" />
                    Vérifier
                </Button>

                <button
                    type="button"
                    class="text-center text-sm text-muted-foreground underline underline-offset-4"
                    :tabindex="3"
                    @click="backToPhone"
                >
                    Modifier le numéro
                </button>
            </div>

            <!-- ── Étape 3 : Identité ─────────────────────────────────────── -->
            <div v-else-if="step === 'identity'" class="grid gap-6">
                <div
                    v-if="isPrefilled"
                    class="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
                >
                    <Lock class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>Informations trouvées dans notre système.</span>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="identity-prenom">
                            Prénom <span class="text-destructive">*</span>
                        </Label>
                        <div class="relative">
                            <Input
                                id="identity-prenom"
                                v-model="formPrenom"
                                type="text"
                                :readonly="isPrefilled"
                                autofocus
                                :tabindex="isPrefilled ? -1 : 1"
                                autocomplete="given-name"
                                minlength="2"
                                placeholder="Prénom"
                                :class="
                                    isPrefilled
                                        ? 'cursor-not-allowed bg-muted pr-9 text-muted-foreground'
                                        : ''
                                "
                            />
                            <Lock
                                v-if="isPrefilled"
                                class="pointer-events-none absolute top-1/2 right-3 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                            />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="identity-nom">
                            Nom <span class="text-destructive">*</span>
                        </Label>
                        <div class="relative">
                            <Input
                                id="identity-nom"
                                v-model="formNom"
                                type="text"
                                :readonly="isPrefilled"
                                :tabindex="isPrefilled ? -1 : 2"
                                autocomplete="family-name"
                                minlength="2"
                                placeholder="Nom"
                                :class="
                                    isPrefilled
                                        ? 'cursor-not-allowed bg-muted pr-9 text-muted-foreground'
                                        : ''
                                "
                            />
                            <Lock
                                v-if="isPrefilled"
                                class="pointer-events-none absolute top-1/2 right-3 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                            />
                        </div>
                    </div>
                </div>

                <Button
                    type="button"
                    class="mt-2 w-full"
                    :tabindex="3"
                    :disabled="
                        formPrenom.trim().length < 2 ||
                        formNom.trim().length < 2
                    "
                    @click="step = 'password'"
                >
                    Continuer
                </Button>
            </div>

            <!-- ── Étape 4 : Mot de passe ─────────────────────────────────── -->
            <div v-else-if="step === 'password'" class="grid gap-6">
                <div
                    class="rounded-md border border-border bg-muted/50 px-4 py-3 text-sm"
                >
                    <p class="text-muted-foreground">Compte pour</p>
                    <p class="mt-0.5 font-medium text-foreground">
                        {{ formPrenom }} {{ formNom.toUpperCase() }} &middot;
                        <span class="font-mono">{{ fullPhone }}</span>
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="password">
                        Mot de passe <span class="text-destructive">*</span>
                    </Label>
                    <Input
                        id="password"
                        v-model="form.password"
                        type="password"
                        required
                        autofocus
                        :tabindex="1"
                        autocomplete="new-password"
                        placeholder="Mot de passe"
                    />
                    <p class="text-xs text-muted-foreground">
                        8 caractères minimum, avec majuscule et chiffre.
                    </p>
                    <InputError
                        :message="
                            form.errors.password ??
                            form.errors.telephone ??
                            form.errors.prenom ??
                            form.errors.nom
                        "
                    />
                </div>

                <Button
                    type="button"
                    class="mt-2 w-full"
                    :tabindex="2"
                    :disabled="form.processing"
                    @click="submitAccept"
                >
                    <Spinner v-if="form.processing" />
                    <CheckCircle v-else class="mr-2 h-4 w-4" />
                    Créer mon compte et rejoindre {{ site_nom }}
                </Button>
            </div>
        </div>
    </AuthBase>
</template>
