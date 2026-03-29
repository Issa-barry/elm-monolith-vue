<script setup lang="ts">
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { logout } from '@/routes';
import { send } from '@/routes/verification';
import { Form, Head } from '@inertiajs/vue3';

defineProps<{
    status?: string;
}>();
</script>

<template>
    <AuthLayout
        title="Vérification de l'e-mail"
        description="Veuillez vérifier votre adresse e-mail en cliquant sur le lien que nous venons de vous envoyer."
    >
        <Head title="Vérification de l'e-mail" />

        <div
            v-if="status === 'verification-link-sent'"
            class="mb-4 text-center text-sm font-medium text-green-600"
        >
            Un nouveau lien de vérification a été envoyé à l'adresse e-mail
            fournie lors de votre inscription.
        </div>

        <Form
            v-bind="send.form()"
            class="space-y-6 text-center"
            v-slot="{ processing }"
        >
            <Button :disabled="processing" variant="secondary">
                <Spinner v-if="processing" />
                Renvoyer l'e-mail de vérification
            </Button>

            <TextLink
                :href="logout()"
                as="button"
                class="mx-auto block text-sm"
            >
                Se déconnecter
            </TextLink>
        </Form>
    </AuthLayout>
</template>
