<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Eye, EyeOff } from 'lucide-vue-next';
import { home, register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import { Form, Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps<{
    status?: string;
    canResetPassword: boolean;
    canRegister: boolean;
}>();

const showPassword = ref(false);
</script>

<template>
    <AuthBase>
        <Head title="Connexion" />

        <Card
            class="mx-auto flex min-h-[calc(100dvh-2rem)] w-full max-w-lg flex-col border-0 bg-transparent shadow-none md:min-h-0 md:rounded-2xl md:border md:border-border/80 md:bg-card/95 md:shadow-2xl md:shadow-black/8 md:dark:shadow-black/35"
        >
            <CardHeader class="px-4 pt-10 pb-2 text-center sm:px-6 md:px-8 md:pt-8 md:pb-0">
                <Link
                    :href="home()"
                    class="mx-auto mb-2 flex h-12 w-12 items-center justify-center rounded-md"
                >
                    <AppLogoIcon class="size-10 fill-current text-foreground" />
                    <span class="sr-only">Accueil</span>
                </Link>
                <CardTitle class="text-2xl font-semibold">
                    Connexion
                </CardTitle>
                <CardDescription class="text-sm">
                    Eau la maman
                </CardDescription>
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
                    <div class="space-y-5">
                        <div class="space-y-2">
                            <Label for="email"
                                >Adresse e-mail
                                <span class="text-red-500">*</span></Label
                            >
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                required
                                autofocus
                                :tabindex="1"
                                autocomplete="email"
                                placeholder="email@example.com"
                                class="h-12 text-base"
                            />
                            <InputError :message="errors.email" />
                        </div>

                        <div class="space-y-2">
                            <div class="flex items-center">
                                <Label for="password"
                                    >Mot de passe
                                    <span class="text-red-500">*</span></Label
                                >
                            </div>
                            <div class="relative">
                                <Input
                                    id="password"
                                    :type="showPassword ? 'text' : 'password'"
                                    name="password"
                                    required
                                    :tabindex="2"
                                    autocomplete="current-password"
                                    placeholder="Mot de passe"
                                    class="h-12 pr-10 text-base"
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

                    <div class="mt-5 flex items-center justify-between gap-3 text-sm">
                        <Label for="remember" class="flex items-center space-x-2">
                            <Checkbox id="remember" name="remember" :tabindex="3" />
                            <span>Se souvenir de moi</span>
                        </Label>
                        <TextLink
                            v-if="canResetPassword"
                            :href="request()"
                            class="text-sm"
                            :tabindex="5"
                        >
                            Mot de passe oublie ?
                        </TextLink>
                    </div>

                    <div class="mt-auto space-y-4 pt-6">
                        <Button
                            type="submit"
                            class="h-12 w-full rounded-xl text-base font-semibold"
                            :tabindex="4"
                            :disabled="processing"
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
                            <TextLink :href="register()" :tabindex="6">
                                S'inscrire
                            </TextLink>
                        </div>
                    </div>
                </Form>
            </CardContent>
        </Card>
    </AuthBase>
</template>
