import { computed, ref } from 'vue';

export interface CountryOption {
    label: string;
    code: string;
    prefix: string;
    localLength: number;
}

export const PAYS: CountryOption[] = [
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

export type RegisterStep = 'phone' | 'otp' | 'identity' | 'password';

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

export function usePhoneOtpForm() {
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

    const step = ref<RegisterStep>('phone');
    const loading = ref(false);
    const lookupError = ref('');
    const otpCode = ref('');
    const otpError = ref('');
    const formPrenom = ref('');
    const formNom = ref('');
    const isPrefilled = ref(false);

    async function submitPhoneLookup() {
        if (!isPhoneValid.value) return;
        loading.value = true;
        lookupError.value = '';

        try {
            const data = await apiFetch<{
                status: string;
                prefill?: { prenom: string; nom: string };
            }>('/register/lookup', { telephone: fullPhone.value });

            if (data.status === 'user_exists') {
                lookupError.value =
                    'Ce numéro est déjà associé à un compte. Veuillez vous connecter ou réinitialiser votre mot de passe.';
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

    async function submitOtp() {
        loading.value = true;
        otpError.value = '';

        try {
            await apiFetch('/register/otp/verify', {
                telephone: fullPhone.value,
                code: otpCode.value,
            });
            step.value = 'identity';
        } catch (e: unknown) {
            otpError.value =
                e instanceof Error ? e.message : 'Code incorrect ou expiré.';
        } finally {
            loading.value = false;
        }
    }

    function backToPhone() {
        step.value = 'phone';
        otpCode.value = '';
        otpError.value = '';
    }

    return {
        PAYS,
        selectedCountryCode,
        phoneDigits,
        selectedPays,
        fullPhone,
        isPhoneValid,
        step,
        loading,
        lookupError,
        otpCode,
        otpError,
        formPrenom,
        formNom,
        isPrefilled,
        flagUrl,
        handlePhoneKeydown,
        handlePhoneInput,
        submitPhoneLookup,
        submitOtp,
        backToPhone,
    };
}
