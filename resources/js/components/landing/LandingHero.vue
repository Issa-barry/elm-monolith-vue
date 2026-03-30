<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import Button from 'primevue/button';
</script>

<template>
    <section class="w-full bg-slate-900 px-6 py-12 lg:px-20 lg:py-16">
        <div class="flex flex-col items-center gap-12 lg:flex-row">
            <div class="flex-1 p-2 lg:p-4">
                <h1
                    class="mb-4 text-4xl leading-tight font-bold text-white md:text-5xl"
                >
                    <span class="text-primary underline">Eau la maman.</span>
                    L'eau minerale qui vous accompagne chaque jour
                </h1>
                <p class="mb-8 text-xl leading-normal text-slate-300">
                    Eau la maman, marque du groupe GBALAN, propose de l'eau
                    minerale en sachet et bientot en bouteille.
                </p>
                <ul class="flex list-none flex-col gap-4">
                    <li class="flex items-center gap-2">
                        <i class="pi pi-check-circle text-xl text-primary" />
                        <span class="leading-normal text-slate-300"
                            >Production locale avec un controle qualite
                            rigoureux.</span
                        >
                    </li>
                    <li class="flex items-center gap-2">
                        <i class="pi pi-check-circle text-xl text-primary" />
                        <span class="leading-normal text-slate-300"
                            >Distribution rapide a Matoto et dans les zones
                            voisines.</span
                        >
                    </li>
                    <li class="flex items-center gap-2">
                        <i class="pi pi-check-circle text-xl text-primary" />
                        <span class="leading-normal text-slate-300"
                            >Ouvert aux partenariats revendeurs, fournisseurs et
                            investisseurs.</span
                        >
                    </li>
                </ul>
                <Button
                    label="Nous contacter"
                    rounded
                    size="large"
                    class="mt-12"
                    @click="router.visit('/contact')"
                />
            </div>
            <div class="flex-1 overflow-hidden text-center lg:text-right">
                <img
                    src="https://fqjltiegiezfetthbags.supabase.co/storage/v1/render/image/public/block.images/blocks/hero/Illustrations.svg"
                    alt="Hero illustration"
                    class="mx-auto w-full max-w-md lg:mr-0 lg:ml-auto lg:w-auto lg:max-w-xl"
                />
            </div>
        </div>
    </section>
</template>
