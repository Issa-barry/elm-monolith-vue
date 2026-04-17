<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthBase from '@/layouts/AuthLayout.vue';
import { dashboard, home, login, logout } from '@/routes';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    AlertCircle,
    CheckCircle,
    Home,
    LayoutDashboard,
    Lock,
    LogIn,
    LogOut,
    MailCheck,
} from 'lucide-vue-next';
import Select from 'primevue/select';
import { computed, ref } from 'vue';

// ── Props ─────────────────────────────────────────────────────────────────────

type InvitationError =
    | 'not_found'
    | 'already_accepted'
    | 'revoked'
    | 'expired'
    | 'already_authenticated';

const props = defineProps<{
    token?: string;
    email?: string;
    role?: string;
    site_type_label?: string;
    site_nom?: string;
    error?: InvitationError;
}>();

const siteLabel = computed(() => {
    const typeLabel = props.site_type_label?.trim();
    const siteName = props.site_nom?.trim();

    if (typeLabel && siteName) {
        return `${typeLabel} ${siteName}`;
    }

    return siteName ?? '';
});

const roleLabel = computed(() => props.role ?? 'collaborateur');

const pageTitle = computed(() =>
    props.error
        ? 'Invitation indisponible'
        : `Rejoindre ${siteLabel.value || 'votre site'}`,
);

const pageDescription = computed(() =>
    props.error
        ? ''
        : `Créez votre compte pour rejoindre ${siteLabel.value || 'votre site'} en tant que ${roleLabel.value}.`,
);

const errorContent = computed(() => {
    switch (props.error) {
        case 'not_found':
            return {
                title: 'Invitation introuvable',
                message: "Ce lien d'invitation est invalide ou n'existe plus.",
            };
        case 'already_accepted':
            return {
                title: 'Invitation déjà acceptée',
                message:
                    'Cette invitation a déjà été utilisée. Connectez-vous pour accéder à votre espace.',
            };
        case 'revoked':
            return {
                title: 'Invitation révoquée',
                message:
                    "Cette invitation a été annulée. Contactez l'administrateur pour recevoir un nouveau lien.",
            };
        case 'expired':
            return {
                title: 'Invitation expirée',
                message:
                    "Ce lien d'invitation a expiré (validité de 24 heures). Demandez un nouveau lien à l'administrateur.",
            };
        case 'already_authenticated':
            return {
                title: 'Vous êtes déjà connecté',
                message:
                    "Déconnectez-vous puis reconnectez-vous avec le compte invité pour accepter cette invitation.",
            };
        default:
            return {
                title: 'Invitation indisponible',
                message:
                    "Une erreur est survenue avec ce lien d'invitation. Veuillez réessayer plus tard.",
            };
    }
});

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

function logoutAndGoToLogin() {
    router.post(logout().url, {}, {
        onFinish: () => router.visit(login().url),
    });
}
</script>

<template>
    <AuthBase :title="pageTitle" :description="pageDescription">
        <Head title="Accepter l'invitation" />

        <!-- ── États d'erreur ──────────────────────────────────────────────── -->
        <div v-if="error" class="space-y-6">
            <div
                class="rounded-xl border border-border bg-[radial-gradient(50%_120%_at_50%_0%,color-mix(in_srgb,var(--p-primary-500)_12%,transparent)_0%,rgba(255,255,255,0)_100%)] p-6 sm:p-8"
            >
                <div class="flex flex-col items-center gap-6 text-center">
                    <div
                        class="inline-flex items-center rounded-full bg-primary-100 px-3 py-1 text-xs font-semibold text-primary dark:bg-primary-900/60 dark:text-primary-100"
                    >
                        Invitation
                    </div>

                    <div
                        class="flex h-12 w-12 items-center justify-center rounded-full bg-primary-100 text-primary dark:bg-primary-900/60 dark:text-primary-100"
                    >
                        <AlertCircle class="h-6 w-6" />
                    </div>

                    <div class="space-y-2">
                        <h2 class="text-2xl font-bold text-surface-900 dark:text-surface-0">
                            {{ errorContent.title }}
                        </h2>
                        <p class="text-sm leading-relaxed text-muted-foreground">
                            {{ errorContent.message }}
                        </p>
                    </div>

                    <div class="flex w-full flex-col gap-3">
                        <Button :as-child="true" variant="outline" class="w-full">
                            <Link :href="home()">
                                <Home class="mr-2 h-4 w-4" />
                                Retour à l'accueil
                            </Link>
                        </Button>

                        <template v-if="error === 'already_authenticated'">
                            <Button :as-child="true" class="w-full">
                                <Link :href="dashboard()">
                                    <LayoutDashboard class="mr-2 h-4 w-4" />
                                    Aller au tableau de bord
                                </Link>
                            </Button>

                            <Button
                                type="button"
                                variant="secondary"
                                class="w-full"
                                @click="logoutAndGoToLogin"
                            >
                                <LogOut class="mr-2 h-4 w-4" />
                                Se connecter avec un autre compte
                            </Button>
                        </template>

                        <Button v-else :as-child="true" class="w-full">
                            <Link :href="login()">
                                <LogIn class="mr-2 h-4 w-4" />
                                Aller à la connexion
                            </Link>
                        </Button>
                    </div>
                </div>
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
                    Créer mon compte et rejoindre
                    {{ siteLabel || site_nom || 'votre site' }}
                </Button>
            </div>
        </div>
    </AuthBase>
</template>
