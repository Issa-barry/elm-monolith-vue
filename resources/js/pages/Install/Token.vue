<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({ token: '' });

function submit() {
    form.post('/install/token', {
        onFinish: () => form.reset('token'),
    });
}
</script>

<template>
    <AuthLayout
        title="Installation"
        description="Cette installation est protégée par une clé — saisissez-la pour continuer."
    >
        <Head title="Installation — Clé requise" />

        <form @submit.prevent="submit" class="grid gap-6">
            <div class="grid gap-2">
                <Label for="token">Clé d'installation</Label>
                <Input
                    id="token"
                    v-model="form.token"
                    type="password"
                    autocomplete="off"
                    class="mt-1 block w-full"
                    autofocus
                    placeholder="Clé d'installation"
                />
                <InputError :message="form.errors.token" />
            </div>

            <Button
                type="submit"
                class="mt-4 w-full"
                :disabled="form.processing"
            >
                <Spinner v-if="form.processing" />
                Continuer
            </Button>
        </form>
    </AuthLayout>
</template>
