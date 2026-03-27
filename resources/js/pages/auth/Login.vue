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
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import { Form, Head } from '@inertiajs/vue3';

defineProps<{
    status?: string;
    canResetPassword: boolean;
    canRegister: boolean;
}>();
</script>

<template>
    <AuthBase>
        <Head title="Connexion" />

        <Card
            class="mx-auto w-full max-w-lg rounded-2xl border-border/80 bg-card/95 shadow-2xl shadow-black/8 dark:shadow-black/35"
        >
            <CardHeader class="px-6 pt-8 pb-0 text-center sm:px-8">
                <div
                    class="mx-auto mb-1 flex h-10 w-10 items-center justify-center rounded-md"
                >
                    <AppLogoIcon class="size-9 fill-current text-foreground" />
                </div>
                <CardTitle class="text-2xl font-semibold">
                    Connexion
                </CardTitle>
                <CardDescription class="text-sm">
                    Eau la maman
                </CardDescription>
            </CardHeader>
            <CardContent class="px-6 pt-2 pb-4 sm:px-8 sm:pt-3 sm:pb-6">
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
                    class="flex flex-col gap-6"
                >
                    <div class="grid gap-6">
                        <div class="grid gap-2">
                            <Label for="email">Adresse e-mail</Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                required
                                autofocus
                                :tabindex="1"
                                autocomplete="email"
                                placeholder="email@example.com"
                            />
                            <InputError :message="errors.email" />
                        </div>

                        <div class="grid gap-2">
                            <div class="flex items-center justify-between">
                                <Label for="password">Mot de passe</Label>
                                
                            </div>
                            <Input
                                id="password"
                                type="password"
                                name="password"
                                required
                                :tabindex="2"
                                autocomplete="current-password"
                                placeholder="Mot de passe"
                            />
                            <InputError :message="errors.password" />
                        </div>

                        <div class="flex items-center justify-between">
                            <Label for="remember" class="flex items-center space-x-3">
                                <Checkbox id="remember" name="remember" :tabindex="3" />
                                <span>Se souvenir de moi</span>
                            </Label>
                        </div>

                        <Button
                            type="submit"
                            class="mt-2 w-full"
                            :tabindex="4"
                            :disabled="processing"
                            data-test="login-button"
                        >
                            <Spinner v-if="processing" />
                            Se connecter
                        </Button>
                    </div>

                    <div
                        class="text-center text-sm text-muted-foreground"
                        v-if="canRegister"
                    >
                    <TextLink   class="text-center text-sm text-muted-foreground mr-2" v-if="canResetPassword" :href="request()" :tabindex="5">Mot de passe oublie ?</TextLink>
                         <TextLink :href="register()" :tabindex="5" >S'inscrire</TextLink>
                    </div>
                </Form>
            </CardContent>
        </Card>
    </AuthBase>
</template>
