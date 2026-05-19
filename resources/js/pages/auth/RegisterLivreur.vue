<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { usePhoneOtpForm } from '@/composables/usePhoneOtpForm';
import AuthBase from '@/layouts/AuthLayout.vue';
import { login } from '@/routes';
import { Head, useForm } from '@inertiajs/vue3';
import { Lock, Truck } from 'lucide-vue-next';
import Select from 'primevue/select';

const {
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
} = usePhoneOtpForm();

const form = useForm({
    telephone: '',
    telephone_country: '',
    telephone_local: '',
    prenom: '',
    nom: '',
    password: '',
});

function submitRegistration() {
    form.telephone = fullPhone.value;
    form.telephone_country = selectedPays.value.code;
    form.telephone_local = phoneDigits.value;
    form.prenom = formPrenom.value;
    form.nom = formNom.value;

    form.post('/register/livreur', {
        preserveState: true,
        onSuccess: () => form.reset('password'),
    });
}
</script>

<template>
    <AuthBase
        title="Inscription livreur"
        description="Créez votre espace chauffeur / livreur"
    >
        <Head title="Inscription livreur" />

        <div class="flex flex-col gap-6">
            <!-- Bandeau informatif -->
            <div class="flex items-start gap-2 rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-sm text-blue-800 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-200">
                <Truck class="mt-0.5 h-4 w-4 shrink-0" />
                <span>Votre compte sera actif après validation par l'équipe.</span>
            </div>

            <!-- ── Étape 1 : Téléphone ────────────────────────────────────── -->
            <div v-if="step === 'phone'" class="grid gap-6">
                <div class="grid gap-2">
                    <Label>Téléphone <span class="text-destructive">*</span></Label>

                    <div class="flex gap-2">
                        <Select
                            v-model="selectedCountryCode"
                            :options="PAYS"
                            option-label="label"
                            option-value="code"
                            :tabindex="1"
                            class="shrink-0"
                            :pt="{ root: { class: 'h-10' }, label: { class: 'flex items-center py-0 h-10' } }"
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
                            :tabindex="2"
                            autocomplete="tel-national"
                            inputmode="numeric"
                            pattern="[0-9]*"
                            :maxlength="phoneDigits.startsWith('0') ? selectedPays.localLength + 1 : selectedPays.localLength"
                            :placeholder="`${selectedPays.localLength} chiffres`"
                            class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm"
                        />
                    </div>

                    <p class="text-xs text-muted-foreground">Saisissez uniquement les chiffres, sans indicatif.</p>
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

                <div class="text-center text-sm text-muted-foreground">
                    Déjà un compte ?
                    <TextLink :href="login()" class="underline underline-offset-4" :tabindex="4">
                        Se connecter
                    </TextLink>
                </div>
            </div>

            <!-- ── Étape 2 : Code OTP ─────────────────────────────────────── -->
            <div v-else-if="step === 'otp'" class="grid gap-6">
                <p class="text-sm text-muted-foreground">
                    Un code de vérification a été envoyé au
                    <span class="font-medium text-foreground">{{ fullPhone }}</span>.
                    Saisissez-le ci-dessous.
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
                    <span>Vos informations ont été trouvées dans notre système.</span>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="identity-prenom">Prénom <span class="text-destructive">*</span></Label>
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
                                :class="isPrefilled ? 'cursor-not-allowed bg-muted pr-9 text-muted-foreground' : ''"
                            />
                            <Lock v-if="isPrefilled" class="pointer-events-none absolute top-1/2 right-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="identity-nom">Nom <span class="text-destructive">*</span></Label>
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
                                :class="isPrefilled ? 'cursor-not-allowed bg-muted pr-9 text-muted-foreground' : ''"
                            />
                            <Lock v-if="isPrefilled" class="pointer-events-none absolute top-1/2 right-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        </div>
                    </div>
                </div>

                <Button
                    type="button"
                    class="mt-2 w-full"
                    :tabindex="3"
                    :disabled="formPrenom.trim().length < 2 || formNom.trim().length < 2"
                    @click="step = 'password'"
                >
                    Continuer
                </Button>
            </div>

            <!-- ── Étape 4 : Mot de passe + création ─────────────────────── -->
            <div v-else-if="step === 'password'" class="grid gap-6">
                <div class="rounded-md border border-border bg-muted/50 px-4 py-3 text-sm">
                    <p class="text-muted-foreground">Compte livreur pour</p>
                    <p class="mt-0.5 font-medium text-foreground">
                        {{ formPrenom }} {{ formNom.toUpperCase() }}
                        &middot;
                        <span class="font-mono">{{ fullPhone }}</span>
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="password">Mot de passe <span class="text-destructive">*</span></Label>
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
                    <p class="text-xs text-muted-foreground">8 caractères minimum, majuscule, minuscule et symbole.</p>
                    <InputError :message="form.errors.password ?? form.errors.telephone" />
                </div>

                <Button
                    type="button"
                    class="mt-2 w-full"
                    :tabindex="2"
                    :disabled="form.processing"
                    @click="submitRegistration"
                >
                    <Spinner v-if="form.processing" />
                    Créer mon compte livreur
                </Button>

                <div class="text-center text-sm text-muted-foreground">
                    Déjà un compte ?
                    <TextLink :href="login()" class="underline underline-offset-4" :tabindex="3">
                        Se connecter
                    </TextLink>
                </div>
            </div>
        </div>
    </AuthBase>
</template>
