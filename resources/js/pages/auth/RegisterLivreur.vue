<script setup lang="ts">
import PhoneOtpRegistrationSteps from '@/components/PhoneOtpRegistrationSteps.vue';
import SeoHead from '@/components/seo/SeoHead.vue';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Truck } from 'lucide-vue-next';
</script>

<template>
    <AuthBase
        title="Inscription livreur"
        description="Créez votre espace chauffeur / livreur"
    >
        <SeoHead
            title="Devenir livreur"
            description="Rejoignez Eau la Maman comme livreur à Conakry : inscription rapide, validation par notre équipe, livraisons d'eau minérale au quotidien."
        />
        <PhoneOtpRegistrationSteps
            submit-url="/register/livreur"
            submit-label="Créer mon compte livreur"
            account-label="Compte livreur pour"
        >
            <template #banner>
                <div
                    class="flex items-start gap-2 rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-sm text-blue-800 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-200"
                >
                    <Truck class="mt-0.5 h-4 w-4 shrink-0" />
                    <span
                        >Votre compte sera actif après validation par
                        l'équipe.</span
                    >
                </div>
            </template>
        </PhoneOtpRegistrationSteps>
    </AuthBase>
</template>
