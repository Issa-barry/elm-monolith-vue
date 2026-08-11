<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post('/password/force-change', {
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <AuthLayout
        title="Définir votre mot de passe"
        description="Pour des raisons de sécurité, vous devez choisir votre propre mot de passe avant de continuer."
    >
        <Head title="Définir votre mot de passe" />

        <form @submit.prevent="submit" class="grid gap-6">
            <div class="grid gap-2">
                <Label for="password">Nouveau mot de passe</Label>
                <Input
                    id="password"
                    v-model="form.password"
                    type="password"
                    autocomplete="new-password"
                    class="mt-1 block w-full"
                    autofocus
                    placeholder="Nouveau mot de passe"
                />
                <InputError :message="form.errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">
                    Confirmer le mot de passe
                </Label>
                <Input
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    type="password"
                    autocomplete="new-password"
                    class="mt-1 block w-full"
                    placeholder="Confirmer le mot de passe"
                />
                <InputError :message="form.errors.password_confirmation" />
            </div>

            <Button
                type="submit"
                class="mt-4 w-full"
                :disabled="form.processing"
            >
                <Spinner v-if="form.processing" />
                Enregistrer et continuer
            </Button>
        </form>
    </AuthLayout>
</template>
