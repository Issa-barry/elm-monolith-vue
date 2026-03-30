<script setup lang="ts">
import LandingFooter from '@/components/landing/LandingFooter.vue';
import LandingHero from '@/components/landing/LandingHero.vue';
import LandingSection from '@/components/landing/LandingSection.vue';
import LandingTopbar from '@/components/landing/LandingTopbar.vue';
import { Head } from '@inertiajs/vue3';

withDefaults(
    defineProps<{
        canRegister: boolean;
    }>(),
    {
        canRegister: true,
    },
);
</script>

<template>
    <Head title="Welcome" />
    <div class="min-h-screen bg-slate-50 font-sans text-slate-900">
        <LandingTopbar :can-register="canRegister" />
        <div class="mx-auto w-full max-w-6xl px-6 py-8 lg:px-8">
            <LandingHero :can-register="canRegister" />
            <LandingSection />
            <LandingFooter :version-label="$page.props.appVersionLabel" />
        </div>
    </div>
</template>
