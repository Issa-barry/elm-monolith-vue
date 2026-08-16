<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import InputError from '@/components/InputError.vue';
import PhoneCountryInput from '@/components/PhoneCountryInput.vue';
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
import { Clock, Eye, EyeOff, MailWarning, ShieldX } from 'lucide-vue-next';
import { ref } from 'vue';

defineProps<{
    status?: string;
    canResetPassword: boolean;
    canRegister: boolean;
    orgBranding?: { name: string; logo_url: string | null } | null;
}>();

const phoneIsValid = ref(false);
const showPassword = ref(false);
</script>

<template>
    <AuthBase>
        <Head title="Connexion" />

        <Card
            class="mx-auto flex min-h-[calc(100dvh-2rem)] w-full max-w-lg flex-col border-0 bg-transparent shadow-none md:min-h-0 md:rounded-2xl md:border md:border-border/80 md:bg-card/95 md:shadow-2xl md:shadow-foreground/8 md:dark:shadow-foreground/30"
        >
            <CardHeader
                class="px-4 pt-10 pb-2 text-center sm:px-6 md:px-8 md:pt-8 md:pb-0"
            >
                <Link
                    :href="home()"
                    class="mx-auto mb-2 flex h-10 w-12 items-center justify-center rounded-md"
                >
                    <img
                        v-if="orgBranding?.logo_url"
                        :src="orgBranding.logo_url"
                        :alt="orgBranding.name"
                        class="size-10 object-contain"
                    />
                    <AppLogoIcon
                        v-else
                        class="size-10 fill-current text-primary"
                    />
                    <span class="sr-only">Accueil</span>
                </Link>
                <CardTitle class="text-2xl font-semibold">Connexion</CardTitle>
                <CardDescription class="text-sm">{{
                    orgBranding?.name ?? 'Eau la maman'
                }}</CardDescription>
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
                    <!-- Email non vérifié -->
                    <div
                        v-if="
                            errors.telephone?.includes(
                                'vérifier votre adresse email',
                            )
                        "
                        class="mb-5 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-900/40 dark:bg-amber-950/40"
                    >
                        <MailWarning
                            class="mt-0.5 h-5 w-5 shrink-0 text-amber-500"
                        />
                        <div>
                            <p
                                class="text-sm font-semibold text-amber-700 dark:text-amber-400"
                            >
                                Email non vérifié
                            </p>
                            <p
                                class="mt-0.5 text-sm text-amber-600 dark:text-amber-300"
                            >
                                Veuillez vérifier votre adresse email pour
                                activer votre compte. Consultez votre boîte de
                                réception.
                            </p>
                        </div>
                    </div>

                    <!-- Compte bloqué -->
                    <div
                        v-if="errors.telephone?.includes('bloqué')"
                        class="mb-5 flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 dark:border-red-900/40 dark:bg-red-950/40"
                    >
                        <ShieldX class="mt-0.5 h-5 w-5 shrink-0 text-red-500" />
                        <div>
                            <p
                                class="text-sm font-semibold text-red-700 dark:text-red-400"
                            >
                                Compte bloqué
                            </p>
                            <p
                                class="mt-0.5 text-sm text-red-600 dark:text-red-300"
                            >
                                Votre compte a été bloqué. Veuillez contacter
                                notre service client pour plus d'informations.
                            </p>
                        </div>
                    </div>

                    <!-- Compte en attente de validation -->
                    <div
                        v-if="
                            errors.telephone?.includes(
                                'en attente de validation',
                            )
                        "
                        class="mb-5 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-900/40 dark:bg-amber-950/40"
                    >
                        <Clock class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" />
                        <div>
                            <p
                                class="text-sm font-semibold text-amber-700 dark:text-amber-400"
                            >
                                Compte en attente de validation
                            </p>
                            <p
                                class="mt-0.5 text-sm text-amber-600 dark:text-amber-300"
                            >
                                Votre compte a bien été créé. Il est en attente
                                de validation par un administrateur.
                            </p>
                        </div>
                    </div>

                    <div class="space-y-5">
                        <!-- Sélecteur pays + numéro -->
                        <div class="space-y-2">
                            <Label
                                >Numéro de téléphone
                                <span class="text-destructive">*</span></Label
                            >
                            <PhoneCountryInput
                                id="telephone"
                                name="telephone"
                                :tabindex-country="1"
                                :tabindex-input="2"
                                autofocus
                                @update:valid="phoneIsValid = $event"
                            />
                            <InputError
                                :message="
                                    errors.telephone?.includes('bloqué') ||
                                    errors.telephone?.includes(
                                        'vérifier votre adresse email',
                                    ) ||
                                    errors.telephone?.includes(
                                        'en attente de validation',
                                    )
                                        ? undefined
                                        : errors.telephone
                                "
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
