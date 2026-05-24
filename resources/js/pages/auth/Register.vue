<script setup lang="ts">
import PhoneOtpRegistrationSteps from '@/components/PhoneOtpRegistrationSteps.vue';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <AuthBase
        title="Créer un compte"
        description="Renseignez vos informations pour créer votre compte"
    >
        <Head title="Inscription" />
        <PhoneOtpRegistrationSteps
            submit-url="/register"
            submit-label="Créer mon compte"
            account-label="Compte pour"
        />
    </AuthBase>
</template>
