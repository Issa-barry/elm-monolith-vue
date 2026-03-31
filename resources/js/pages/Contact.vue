<script setup lang="ts">
import LandingFooter from '@/components/landing/LandingFooter.vue';
import LandingTopbar from '@/components/landing/LandingTopbar.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { CheckCircle } from 'lucide-vue-next';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import Textarea from 'primevue/textarea';
import { computed } from 'vue';

withDefaults(
    defineProps<{
        canRegister: boolean;
    }>(),
    {
        canRegister: true,
    },
);

const page = usePage();
const flash = computed(() => (page.props as any).flash as { success?: string });

const form = useForm({
    name: '',
    email: '',
    phone: '',
    message: '',
});

function submit() {
    form.post('/contact', {
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <Head>
        <title>Contact</title>
    </Head>
    <div class="min-h-screen bg-background font-sans text-foreground">
        <LandingTopbar :can-register="canRegister" />

        <main>
            <section class="bg-background px-6 py-20 md:px-12 lg:px-20">
                <div
                    class="mx-auto flex w-full max-w-6xl flex-col-reverse gap-12 lg:flex-row"
                >
                    <!-- Success state -->
                    <div
                        v-if="flash.success"
                        class="flex w-full flex-1 flex-col items-center justify-center gap-4 rounded-xl border border-green-500/30 bg-green-500/10 p-10 text-center"
                    >
                        <CheckCircle class="h-12 w-12 text-green-500" />
                        <p
                            class="text-lg font-semibold text-green-600 dark:text-green-400"
                        >
                            Message envoyé !
                        </p>
                        <p class="text-sm text-green-600 dark:text-green-400">
                            {{ flash.success }}
                        </p>
                    </div>

                    <form
                        v-else
                        class="flex w-full flex-1 flex-col gap-4"
                        @submit.prevent="submit"
                    >
                        <div class="flex flex-col gap-2">
                            <label
                                for="name"
                                class="font-medium text-foreground"
                                >Nom / Entreprise</label
                            >
                            <InputText id="name" v-model="form.name" />
                            <p
                                v-if="form.errors.name"
                                class="text-sm text-red-500"
                            >
                                {{ form.errors.name }}
                            </p>
                        </div>

                        <div class="flex flex-col gap-2">
                            <label
                                for="email"
                                class="font-medium text-foreground"
                                >Email</label
                            >
                            <InputText id="email" v-model="form.email" />
                            <p
                                v-if="form.errors.email"
                                class="text-sm text-red-500"
                            >
                                {{ form.errors.email }}
                            </p>
                        </div>

                        <div class="flex flex-col gap-2">
                            <label
                                for="phone"
                                class="font-medium text-foreground"
                                >Numero de telephone
                                <span class="text-red-500">*</span></label
                            >
                            <InputText
                                id="phone"
                                v-model="form.phone"
                                required
                            />
                            <p
                                v-if="form.errors.phone"
                                class="text-sm text-red-500"
                            >
                                {{ form.errors.phone }}
                            </p>
                        </div>

                        <div class="flex flex-col gap-2">
                            <label
                                for="message"
                                class="font-medium text-foreground"
                                >Message
                                <span class="text-red-500">*</span></label
                            >
                            <Textarea
                                id="message"
                                v-model="form.message"
                                rows="5"
                                cols="30"
                                required
                            />
                            <p
                                v-if="form.errors.message"
                                class="text-sm text-red-500"
                            >
                                {{ form.errors.message }}
                            </p>
                        </div>

                        <Button
                            label="Envoyer le message"
                            icon="pi pi-send"
                            class="w-fit"
                            type="submit"
                            :loading="form.processing"
                        />
                    </form>

                    <div
                        class="relative flex w-full flex-1 flex-col gap-12 overflow-hidden"
                    >
                        <div class="flex flex-col gap-4">
                            <h1 class="text-4xl font-medium text-foreground">
                                Contactez-nous
                            </h1>
                            <p class="leading-normal text-muted-foreground">
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

                        <ul class="m-0 list-none p-0 text-muted-foreground">
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
