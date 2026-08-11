<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { CheckCircle2 } from 'lucide-vue-next';
</script>

<template>
    <AuthLayout
        title="Installation terminée"
        description="Votre organisation et votre compte super administrateur sont prêts."
    >
        <Head title="Installation terminée" />

        <div class="flex flex-col items-center gap-6 text-center">
            <CheckCircle2 class="h-14 w-14 text-emerald-500" />
            <p class="text-sm text-muted-foreground">
                Installation terminée avec succès. Vous pouvez maintenant vous
                connecter avec le numéro de téléphone et le mot de passe que
                vous venez de définir.
            </p>
            <Link href="/login" class="w-full">
                <Button class="w-full">Se connecter</Button>
            </Link>
        </div>
    </AuthLayout>
</template>
