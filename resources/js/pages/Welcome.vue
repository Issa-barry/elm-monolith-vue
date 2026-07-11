<script setup lang="ts">
import LandingFooter from '@/components/landing/LandingFooter.vue';
import LandingHero from '@/components/landing/LandingHero.vue';
import LandingSection from '@/components/landing/LandingSection.vue';
import LandingTopbar from '@/components/landing/LandingTopbar.vue';
import SeoHead from '@/components/seo/SeoHead.vue';

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
    <SeoHead
        title="Eau minérale à Conakry"
        description="Eau la Maman produit et distribue de l'eau minérale à Conakry : livraison à domicile, sachets et bouteilles, revendeurs en expansion en Guinée."
        local-business
    />
    <div class="min-h-screen bg-white font-sans text-slate-900">
        <LandingTopbar :can-register="canRegister" />
        <LandingHero />
        <div class="mx-auto w-full max-w-6xl px-6 py-8 lg:px-8">
            <LandingSection />
            <LandingFooter :version-label="$page.props.appVersionLabel" />
        </div>
    </div>
</template>
