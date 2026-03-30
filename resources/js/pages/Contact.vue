<script setup lang="ts">
import LandingFooter from '@/components/landing/LandingFooter.vue';
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
    <Head title="Contact" />
    <div class="min-h-screen bg-slate-50 font-sans text-slate-900">
        <LandingTopbar :can-register="canRegister" />

        <main class="overflow-hidden">
            <section class="bg-white px-6 py-14 md:px-12 lg:px-20 lg:py-20">
                <div class="flex flex-col items-start gap-10 lg:flex-row lg:gap-14">
                    <div class="w-full flex-1">
                        <p
                            class="mb-4 text-base leading-normal font-medium tracking-wide text-slate-900 uppercase"
                        >
                            Contactez-nous
                        </p>
                        <h1
                            class="mb-4 text-3xl leading-tight font-bold text-slate-900 md:text-4xl"
                        >
                            RESTONS EN CONTACT
                        </h1>
                        <p class="max-w-xl text-base leading-relaxed text-slate-700">
                            Notre equipe est disponible pour les partenariats, la
                            distribution, les livraisons et toute demande liee a
                            Eau la maman.
                        </p>
                    </div>

                    <div
                        class="grid w-full flex-1 grid-cols-1 gap-6 md:grid-cols-2"
                    >
                        <article class="flex items-start gap-4">
                            <div
                                class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-slate-100"
                            >
                                <i
                                    class="pi pi-inbox text-xl leading-none text-slate-900"
                                />
                            </div>
                            <div>
                                <p
                                    class="text-lg leading-tight font-medium text-slate-900"
                                >
                                    Email
                                </p>
                                <p class="text-sm leading-relaxed text-slate-700">
                                    contact@eaulamaman.com
                                </p>
                            </div>
                        </article>

                        <article class="flex items-start gap-4">
                            <div
                                class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-slate-100"
                            >
                                <i
                                    class="pi pi-whatsapp text-xl leading-none text-slate-900"
                                />
                            </div>
                            <div>
                                <p
                                    class="text-lg leading-tight font-medium text-slate-900"
                                >
                                    WhatsApp
                                </p>
                                <p class="text-sm leading-relaxed text-slate-700">
                                    +224 620 00 00 00
                                </p>
                            </div>
                        </article>

                        <article class="flex items-start gap-4 md:col-span-2">
                            <div
                                class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-slate-100"
                            >
                                <i
                                    class="pi pi-map-marker text-xl leading-none text-slate-900"
                                />
                            </div>
                            <div>
                                <p
                                    class="text-lg leading-tight font-medium text-slate-900"
                                >
                                    Adresse
                                </p>
                                <p class="text-sm leading-relaxed text-slate-700">
                                    Conakry, Matoto, Guinée
                                </p>
                            </div>
                        </article>
                    </div>
                </div>
            </section>

            <a
                href="https://maps.google.com/?q=Conakry%20Matoto%20Guin%C3%A9e"
                target="_blank"
                rel="noopener noreferrer"
                class="block"
            >
                <img
                    alt="Carte de localisation"
                    src="https://fqjltiegiezfetthbags.supabase.co/storage/v1/render/image/public/block.images/blocks/contact/map-3.jpg"
                    class="h-80 w-full object-cover md:h-[26rem]"
                />
            </a>

            <div class="mx-auto w-full max-w-6xl px-6 py-8 lg:px-8">
                <LandingFooter :version-label="$page.props.appVersionLabel" />
            </div>
        </main>
    </div>
</template>
