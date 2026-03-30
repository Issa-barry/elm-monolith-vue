<script setup lang="ts">
import LandingFooter from '@/components/landing/LandingFooter.vue';
import LandingTopbar from '@/components/landing/LandingTopbar.vue';
import { Head } from '@inertiajs/vue3';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import Textarea from 'primevue/textarea';
import { ref } from 'vue';

withDefaults(
    defineProps<{
        canRegister: boolean;
    }>(),
    {
        canRegister: true,
    },
);

const contact = ref({
    name: '',
    email: '',
    phone: '',
    message: '',
});
</script>

<template>
    <Head>
        <title>Contact</title>
    </Head>
    <div class="min-h-screen bg-slate-50 font-sans text-slate-900">
        <LandingTopbar :can-register="canRegister" />

        <main>
            <section class="bg-white px-6 py-20 md:px-12 lg:px-20">
                <div
                    class="mx-auto flex w-full max-w-6xl flex-col-reverse gap-12 lg:flex-row"
                >
                    <form
                        class="flex w-full flex-1 flex-col gap-4"
                        @submit.prevent
                    >
                        <div class="flex flex-col gap-2">
                            <label for="name" class="font-medium text-slate-800"
                                >Nom / Entreprise</label
                            >
                            <InputText id="name" v-model="contact.name" />
                        </div>

                        <div class="flex flex-col gap-2">
                            <label
                                for="email"
                                class="font-medium text-slate-800"
                                >Email</label
                            >
                            <InputText id="email" v-model="contact.email" />
                        </div>

                        <div class="flex flex-col gap-2">
                            <label
                                for="phone"
                                class="font-medium text-slate-800"
                                >Numero de telephone *</label
                            >
                            <InputText
                                id="phone"
                                v-model="contact.phone"
                                required
                            />
                        </div>

                        <div class="flex flex-col gap-2">
                            <label
                                for="message"
                                class="font-medium text-slate-800"
                                >Message *</label
                            >
                            <Textarea
                                id="message"
                                v-model="contact.message"
                                rows="5"
                                cols="30"
                                required
                            />
                        </div>

                        <Button
                            label="Envoyer le message"
                            icon="pi pi-send"
                            class="w-fit"
                            type="submit"
                        />
                    </form>

                    <div
                        class="relative flex w-full flex-1 flex-col gap-12 overflow-hidden"
                    >
                        <div class="flex flex-col gap-4">
                            <h1 class="text-4xl font-medium text-slate-900">
                                Contactez-nous
                            </h1>
                            <p class="leading-normal text-slate-700">
                                Notre equipe est disponible pour les
                                partenariats, la distribution, les livraisons et
                                toute demande liee a Eau la maman.
                            </p>
                        </div>

                        <a
                            href="https://maps.google.com/?q=Conakry%20Matoto%20Guinee"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex cursor-pointer items-center gap-2 font-bold text-primary no-underline"
                        >
                            <span>Voir l'adresse sur Google Maps</span>
                            <i class="pi pi-arrow-right" />
                        </a>

                        <ul class="m-0 list-none p-0 text-slate-700">
                            <li class="mb-4 flex items-center">
                                <i
                                    class="pi pi-whatsapp mr-4 text-lg leading-none"
                                />
                                <span>+224 620 00 00 00</span>
                            </li>
                            <li class="mb-4 flex items-center">
                                <i
                                    class="pi pi-inbox mr-4 text-lg leading-none"
                                />
                                <span>contact@eaulamaman.com</span>
                            </li>
                            <li class="flex items-center">
                                <i
                                    class="pi pi-map-marker mr-4 text-lg leading-none"
                                />
                                <span>Conakry, Matoto, Guinee</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </section>

            <div class="mx-auto w-full max-w-6xl px-6 py-8 lg:px-8">
                <LandingFooter :version-label="$page.props.appVersionLabel" />
            </div>
        </main>
    </div>
</template>
