<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Head, router } from '@inertiajs/vue3';
import { MapPin } from 'lucide-vue-next';

defineProps<{ user_name: string }>();

function logout() {
    router.post('/logout');
}
</script>

<template>
    <Head title="Accès restreint" />

    <div class="flex min-h-screen flex-col items-center justify-center bg-background px-4">
        <div class="w-full max-w-md text-center">
            <!-- Icône -->
            <div class="mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-950/40">
                <MapPin class="h-8 w-8 text-amber-600 dark:text-amber-400" />
            </div>

            <!-- Titre -->
            <h1 class="text-2xl font-bold tracking-tight">
                Aucun site assigné
            </h1>

            <!-- Message -->
            <p class="mt-3 text-muted-foreground">
                Bonjour <strong>{{ user_name }}</strong>, votre compte n'est encore affecté à aucun site.
                Veuillez contacter votre administrateur pour obtenir un accès.
            </p>

            <div class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-400">
                Un administrateur doit vous assigner à un site depuis la gestion des utilisateurs.
            </div>

            <!-- Action -->
            <div class="mt-8">
                <Button variant="outline" @click="logout">Se déconnecter</Button>
            </div>
        </div>
    </div>
</template>
