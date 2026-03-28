<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthBase from '@/layouts/AuthLayout.vue';
import { login } from '@/routes';
import { store } from '@/routes/register';
import { Form, Head } from '@inertiajs/vue3';
import Select from 'primevue/select';
import { computed, ref } from 'vue';

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
    { label: "Côte d\'Ivoire", code: 'CI', prefix: '+225', localLength: 10 },
    { label: 'Liberia', code: 'LR', prefix: '+231', localLength: 8 },
    { label: 'Sierra Leone', code: 'SL', prefix: '+232', localLength: 8 },
    { label: 'France', code: 'FR', prefix: '+33', localLength: 9 },
    { label: 'Chine', code: 'CN', prefix: '+86', localLength: 11 },
    { label: 'Émirats arabes unis', code: 'AE', prefix: '+971', localLength: 9 },
    { label: 'Inde', code: 'IN', prefix: '+91', localLength: 10 },
];

const selectedCountryCode = ref(PAYS[0].code);
const phoneDigits = ref('');

const selectedPays = computed(() =>
    PAYS.find((pays) => pays.code === selectedCountryCode.value) ?? PAYS[0],
);

const fullPhone = computed(() =>
    phoneDigits.value ? `${selectedPays.value.prefix}${phoneDigits.value}` : '',
);

function flagUrl(code: string): string {
    return `https://flagcdn.com/20x15/${code.toLowerCase()}.png`;
}

function handlePhoneKeydown(e: KeyboardEvent) {
    const pass = ['Backspace','Delete','Tab','Escape','Enter','ArrowLeft','ArrowRight','ArrowUp','ArrowDown','Home','End'];
    if (pass.includes(e.key)) return;
    if ((e.ctrlKey || e.metaKey) && ['a','c','v','x'].includes(e.key.toLowerCase())) return;
    if (!/^\d$/.test(e.key)) e.preventDefault();
}

function handlePhoneInput(e: Event) {
    const input = e.target as HTMLInputElement;
    const digits = input.value.replace(/\D/g, '').slice(0, selectedPays.value.localLength);
    phoneDigits.value = digits;
    input.value = digits;
}
</script>

<template>
    <AuthBase
        title="Créer un compte"
        description="Renseignez vos informations pour créer votre compte"
    >
        <Head title="Inscription" />

        <Form
            v-bind="store.form()"
            :reset-on-success="['password', 'password_confirmation']"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-6"
        >
            <div class="grid gap-6">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="prenom">Prénom <span class="text-destructive">*</span></Label>
                        <Input
                            id="prenom"
                            type="text"
                            required
                            autofocus
                            :tabindex="1"
                            autocomplete="given-name"
                            name="prenom"
                            minlength="2"
                            placeholder="Prénom"
                        />
                        <InputError :message="errors.prenom" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="nom">Nom <span class="text-destructive">*</span></Label>
                        <Input
                            id="nom"
                            type="text"
                            required
                            :tabindex="2"
                            autocomplete="family-name"
                            name="nom"
                            minlength="2"
                            placeholder="Nom"
                        />
                        <InputError :message="errors.nom" />
                    </div>
                </div>

               
                <div class="grid gap-2">
                    <Label>Téléphone</Label>
                    <input type="hidden" name="telephone" :value="fullPhone" />
                    <input type="hidden" name="telephone_country" :value="selectedPays.code" />
                    <input type="hidden" name="telephone_local" :value="phoneDigits" />

                    <div class="flex gap-2">
                        <Select
                            v-model="selectedCountryCode"
                            :options="PAYS"
                            option-label="label"
                            option-value="code"
                            :tabindex="3"
                            class="shrink-0"
                        >
                            <template #value="{ value }">
                                <div v-if="value" class="flex items-center gap-2">
                                    <img :src="flagUrl(value)" class="h-4 w-auto rounded-sm shadow-sm" />
                                    <span class="font-mono text-sm">{{ selectedPays.prefix }}</span>
                                </div>
                            </template>
                            <template #option="{ option }">
                                <div class="flex items-center gap-2">
                                    <img :src="flagUrl(option.code)" :alt="option.label" class="h-4 w-auto rounded-sm shadow-sm" />
                                    <span>{{ option.label }}</span>
                                    <span class="ml-auto text-xs text-muted-foreground">{{ option.prefix }}</span>
                                </div>
                            </template>
                        </Select>

                        <input
                            :value="phoneDigits"
                            @keydown="handlePhoneKeydown"
                            @input="handlePhoneInput"
                            type="tel"
                            :tabindex="4"
                            autocomplete="tel-national"
                            inputmode="numeric"
                            pattern="[0-9]*"
                            :maxlength="selectedPays.localLength"
                            :placeholder="`${selectedPays.localLength} chiffres`"
                            class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] md:text-sm"
                        />
                    </div>
                    <p class="text-xs text-muted-foreground">Saisissez uniquement les chiffres, sans indicatif.</p>
                    <InputError :message="errors.telephone || errors.telephone_local" />
                </div>


                <div class="grid gap-2">
                    <Label for="password">Mot de passe <span class="text-destructive">*</span></Label>
                    <Input
                        id="password"
                        type="password"
                        required
                        :tabindex="5"
                        autocomplete="new-password"
                        name="password"
                        placeholder="Mot de passe"
                    />
                    <InputError :message="errors.password" />
                </div>


<Button
                    type="submit"
                    class="mt-2 w-full"
                    tabindex="7"
                    :disabled="processing"
                    data-test="register-user-button"
                >
                    <Spinner v-if="processing" />
                    Créer un compte
                </Button>
            </div>

            <div class="text-center text-sm text-muted-foreground">
                Déjà un compte ?
                <TextLink
                    :href="login()"
                    class="underline underline-offset-4"
                    :tabindex="8"
                >Se connecter</TextLink>
            </div>
        </Form>
    </AuthBase>
</template>
