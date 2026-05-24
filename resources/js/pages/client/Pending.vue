<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/vue3';
import { Clock, LogOut } from 'lucide-vue-next';

function handleLogout() {
    router.flushAll();
    router.post('/logout');
}
</script>

<template>
    <div
        class="flex min-h-screen flex-col items-center justify-center bg-background px-4"
    >
        <div class="w-full max-w-sm space-y-8 text-center">
            <div class="flex justify-center">
                <AppLogoIcon class="h-12 w-12 fill-current text-primary" />
            </div>

            <div class="space-y-3">
                <div
                    class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-950"
                >
                    <Clock class="h-8 w-8 text-amber-600 dark:text-amber-400" />
                </div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Compte en attente
                </h1>
                <p class="text-sm text-muted-foreground">
                    Votre inscription a bien été reçue. Un membre de l'équipe va
                    valider votre compte sous peu. Vous recevrez une
                    confirmation dès que votre accès sera activé.
                </p>
            </div>

            <div
                class="space-y-1 rounded-lg border border-border bg-muted/40 px-4 py-4 text-left text-sm"
            >
                <p class="font-medium">Prochaines étapes</p>
                <ul class="mt-2 space-y-1.5 text-muted-foreground">
                    <li class="flex items-start gap-2">
                        <span
                            class="mt-0.5 h-1.5 w-1.5 shrink-0 rounded-full bg-primary"
                        />
                        L'équipe vérifie vos informations
                    </li>
                    <li class="flex items-start gap-2">
                        <span
                            class="mt-0.5 h-1.5 w-1.5 shrink-0 rounded-full bg-primary"
                        />
                        Votre compte est activé
                    </li>
                    <li class="flex items-start gap-2">
                        <span
                            class="mt-0.5 h-1.5 w-1.5 shrink-0 rounded-full bg-primary"
                        />
                        Reconnectez-vous pour accéder à votre espace
                    </li>
                </ul>
            </div>

            <Button
                variant="outline"
                class="w-full gap-2"
                @click="handleLogout"
            >
                <LogOut class="h-4 w-4" />
                Se déconnecter
            </Button>
        </div>
    </div>
</template>
