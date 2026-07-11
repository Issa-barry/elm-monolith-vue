<script setup lang="ts">
import LandingFooter from '@/components/landing/LandingFooter.vue';
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
        title="Suppression de compte"
        description="Comment demander la suppression de votre compte Eau la Maman et des données personnelles associées : procédure, données supprimées, délai de traitement."
        robots="noindex,follow"
    />

    <div class="min-h-screen bg-background font-sans text-foreground">
        <LandingTopbar :can-register="canRegister" />

        <main>
            <section class="bg-background px-6 py-16 md:px-12 lg:px-20">
                <div class="mx-auto w-full max-w-3xl">
                    <header class="mb-12">
                        <h1 class="text-4xl font-medium text-foreground">
                            Suppression de compte
                        </h1>
                        <p class="mt-3 text-sm text-muted-foreground">
                            Dernière mise à jour : 08 juillet 2026
                        </p>
                    </header>

                    <div
                        class="flex flex-col gap-10 leading-relaxed text-foreground/90"
                    >
                        <section>
                            <h2
                                class="mb-3 text-xl font-semibold text-foreground"
                            >
                                Demander la suppression de votre compte
                            </h2>
                            <p>
                                Chez Eau la Maman, nous respectons votre droit à
                                la protection de vos données personnelles.
                            </p>
                            <p class="mt-3">
                                Vous pouvez demander à tout moment la
                                suppression de votre compte utilisateur ainsi
                                que des données personnelles associées.
                            </p>
                        </section>

                        <section>
                            <h2
                                class="mb-3 text-xl font-semibold text-foreground"
                            >
                                Comment effectuer une demande ?
                            </h2>
                            <p>
                                Pour demander la suppression de votre compte,
                                contactez-nous à l'adresse suivante :
                            </p>
                            <p class="mt-3">
                                <a
                                    href="mailto:contact@eau-la-maman.fr"
                                    class="text-primary no-underline hover:underline"
                                    >contact@eau-la-maman.fr</a
                                >
                            </p>
                            <p class="mt-4">Merci d'indiquer :</p>
                            <ul class="mt-3 list-disc space-y-1 pl-5">
                                <li>votre nom et prénom ;</li>
                                <li>
                                    l'adresse e-mail utilisée pour votre compte
                                    ;
                                </li>
                                <li>
                                    votre numéro de téléphone (si applicable) ;
                                </li>
                                <li>
                                    l'objet : « Demande de suppression de compte
                                    ».
                                </li>
                            </ul>
                            <p class="mt-4">
                                Notre équipe traitera votre demande dans les
                                meilleurs délais.
                            </p>
                        </section>

                        <section>
                            <h2
                                class="mb-3 text-xl font-semibold text-foreground"
                            >
                                Données supprimées
                            </h2>
                            <p>
                                Sous réserve des obligations légales
                                applicables, les données suivantes seront
                                supprimées :
                            </p>
                            <ul class="mt-3 list-disc space-y-1 pl-5">
                                <li>informations du profil utilisateur ;</li>
                                <li>nom et prénom ;</li>
                                <li>adresse e-mail ;</li>
                                <li>numéro de téléphone ;</li>
                                <li>adresses enregistrées ;</li>
                                <li>historique de connexion ;</li>
                                <li>préférences utilisateur.</li>
                            </ul>
                        </section>

                        <section>
                            <h2
                                class="mb-3 text-xl font-semibold text-foreground"
                            >
                                Données pouvant être conservées
                            </h2>
                            <p>
                                Certaines informations peuvent être conservées
                                pendant la durée imposée par la législation
                                applicable, notamment :
                            </p>
                            <ul class="mt-3 list-disc space-y-1 pl-5">
                                <li>factures ;</li>
                                <li>commandes ;</li>
                                <li>opérations comptables ;</li>
                                <li>
                                    journaux techniques liés à la sécurité ;
                                </li>
                                <li>
                                    données nécessaires au respect des
                                    obligations légales.
                                </li>
                            </ul>
                            <p class="mt-3">
                                Ces données sont conservées uniquement pendant
                                la durée strictement nécessaire et ne sont plus
                                utilisées à des fins commerciales.
                            </p>
                        </section>

                        <section>
                            <h2
                                class="mb-3 text-xl font-semibold text-foreground"
                            >
                                Délai de traitement
                            </h2>
                            <p>
                                Les demandes de suppression sont généralement
                                traitées dans un délai maximal de 30 jours.
                            </p>
                        </section>

                        <section>
                            <h2
                                class="mb-3 text-xl font-semibold text-foreground"
                            >
                                Contact
                            </h2>
                            <p>
                                Pour toute question concernant vos données
                                personnelles :
                            </p>
                            <ul class="mt-3 list-none space-y-1">
                                <li><strong>Eau la Maman</strong></li>
                                <li>
                                    Email :
                                    <a
                                        href="mailto:contact@eau-la-maman.fr"
                                        class="text-primary no-underline hover:underline"
                                        >contact@eau-la-maman.fr</a
                                    >
                                </li>
                                <li>
                                    Site :
                                    <a
                                        href="https://eau-la-maman.fr"
                                        class="text-primary no-underline hover:underline"
                                        >https://eau-la-maman.fr</a
                                    >
                                </li>
                            </ul>
                        </section>
                    </div>
                </div>
            </section>

            <div class="mx-auto w-full max-w-6xl px-6 py-8 lg:px-8">
                <LandingFooter :version-label="$page.props.appVersionLabel" />
            </div>
        </main>
    </div>
</template>
