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
    <Head title="Politique de confidentialité">
        <meta
            name="description"
            content="Politique de confidentialité d'Eau la Maman : données collectées, finalités, partage, sécurité, conservation et droits des utilisateurs."
        />
    </Head>

    <div class="min-h-screen bg-background font-sans text-foreground">
        <LandingTopbar :can-register="canRegister" />

        <main>
            <section class="bg-background px-6 py-16 md:px-12 lg:px-20">
                <div class="mx-auto w-full max-w-3xl">
                    <header class="mb-12">
                        <h1 class="text-4xl font-medium text-foreground">
                            Politique de confidentialité
                        </h1>
                        <p class="mt-3 text-sm text-muted-foreground">
                            Dernière mise à jour : 08 juillet 2026
                        </p>
                    </header>

                    <div class="flex flex-col gap-10 leading-relaxed text-foreground/90">
                        <section>
                            <h2 class="mb-3 text-xl font-semibold text-foreground">
                                1. Préambule
                            </h2>
                            <p>
                                La présente Politique de confidentialité a pour
                                objet d'informer les utilisateurs de
                                l'application <strong>Eau la Maman</strong>
                                sur les modalités de collecte, d'utilisation,
                                de conservation et de protection de leurs
                                données à caractère personnel.
                            </p>
                            <p class="mt-3">
                                Eau la Maman attache une importance
                                particulière au respect de la vie privée de
                                ses utilisateurs et s'engage à traiter les
                                données personnelles conformément aux lois et
                                réglementations applicables en matière de
                                protection des données, notamment le
                                <strong
                                    >Règlement Général sur la Protection des
                                    Données (RGPD)</strong
                                >
                                lorsqu'il est applicable.
                            </p>
                            <p class="mt-3">
                                L'utilisation de l'application implique
                                l'acceptation pleine et entière de la présente
                                Politique de confidentialité.
                            </p>
                        </section>

                        <section>
                            <h2 class="mb-3 text-xl font-semibold text-foreground">
                                2. Responsable du traitement
                            </h2>
                            <p>
                                Le responsable du traitement des données
                                personnelles est :
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
                                    Site Internet :
                                    <a
                                        href="https://eau-la-maman.fr"
                                        class="text-primary no-underline hover:underline"
                                        >https://eau-la-maman.fr</a
                                    >
                                </li>
                            </ul>
                        </section>

                        <section>
                            <h2 class="mb-3 text-xl font-semibold text-foreground">
                                3. Données collectées
                            </h2>
                            <p>
                                Dans le cadre de l'utilisation de
                                l'application, Eau la Maman peut être amenée à
                                collecter les catégories de données suivantes
                                :
                            </p>

                            <h3 class="mt-4 mb-2 font-semibold text-foreground">
                                Données d'identification
                            </h3>
                            <ul class="list-disc space-y-1 pl-5">
                                <li>Nom et prénom</li>
                                <li>Adresse électronique</li>
                                <li>Numéro de téléphone</li>
                            </ul>

                            <h3 class="mt-4 mb-2 font-semibold text-foreground">
                                Données de livraison
                            </h3>
                            <ul class="list-disc space-y-1 pl-5">
                                <li>Adresse de livraison</li>
                                <li>Informations relatives aux commandes</li>
                            </ul>

                            <h3 class="mt-4 mb-2 font-semibold text-foreground">
                                Données de compte
                            </h3>
                            <ul class="list-disc space-y-1 pl-5">
                                <li>Identifiant utilisateur</li>
                                <li>
                                    Informations nécessaires à
                                    l'authentification
                                </li>
                            </ul>

                            <h3 class="mt-4 mb-2 font-semibold text-foreground">
                                Données techniques
                            </h3>
                            <p>
                                Selon les fonctionnalités utilisées, certaines
                                informations techniques peuvent être
                                automatiquement collectées, notamment :
                            </p>
                            <ul class="mt-2 list-disc space-y-1 pl-5">
                                <li>modèle de l'appareil ;</li>
                                <li>système d'exploitation ;</li>
                                <li>informations de diagnostic ;</li>
                                <li>
                                    journaux techniques nécessaires à la
                                    sécurité et au bon fonctionnement de
                                    l'application.
                                </li>
                            </ul>
                            <p class="mt-3">
                                Aucune donnée sensible au sens de la
                                réglementation applicable n'est volontairement
                                collectée.
                            </p>
                        </section>

                        <section>
                            <h2 class="mb-3 text-xl font-semibold text-foreground">
                                4. Finalités du traitement
                            </h2>
                            <p>
                                Les données personnelles sont exclusivement
                                collectées pour les finalités suivantes :
                            </p>
                            <ul class="mt-3 list-disc space-y-1 pl-5">
                                <li>
                                    création et gestion du compte utilisateur
                                    ;
                                </li>
                                <li>
                                    traitement, préparation et suivi des
                                    commandes ;
                                </li>
                                <li>organisation et suivi des livraisons ;</li>
                                <li>assistance aux utilisateurs ;</li>
                                <li>
                                    amélioration continue des fonctionnalités
                                    de l'application ;
                                </li>
                                <li>
                                    prévention des fraudes et sécurisation des
                                    services ;
                                </li>
                                <li>
                                    respect des obligations légales et
                                    réglementaires.
                                </li>
                            </ul>
                            <p class="mt-3">
                                Les données ne sont jamais utilisées à des
                                fins incompatibles avec les finalités
                                ci-dessus.
                            </p>
                        </section>

                        <section>
                            <h2 class="mb-3 text-xl font-semibold text-foreground">
                                5. Base légale du traitement
                            </h2>
                            <p>
                                Les traitements de données reposent, selon les
                                cas, sur :
                            </p>
                            <ul class="mt-3 list-disc space-y-1 pl-5">
                                <li>
                                    l'exécution du contrat conclu avec
                                    l'utilisateur ;
                                </li>
                                <li>le respect des obligations légales ;</li>
                                <li>
                                    l'intérêt légitime d'Eau la Maman
                                    consistant à assurer la sécurité, le bon
                                    fonctionnement et l'amélioration de ses
                                    services ;
                                </li>
                                <li>
                                    le consentement de l'utilisateur lorsque
                                    celui-ci est requis par la réglementation.
                                </li>
                            </ul>
                        </section>

                        <section>
                            <h2 class="mb-3 text-xl font-semibold text-foreground">
                                6. Partage des données
                            </h2>
                            <p>
                                Les données personnelles ne sont ni vendues,
                                ni louées, ni cédées à des tiers à des fins
                                commerciales.
                            </p>
                            <p class="mt-3">
                                Les données peuvent uniquement être
                                communiquées :
                            </p>
                            <ul class="mt-3 list-disc space-y-1 pl-5">
                                <li>aux employés et administrateurs habilités ;</li>
                                <li>
                                    aux livreurs intervenant dans le
                                    traitement des commandes ;
                                </li>
                                <li>
                                    aux prestataires techniques indispensables
                                    au fonctionnement des services
                                    (hébergement, maintenance, sécurité) ;
                                </li>
                                <li>
                                    aux autorités compétentes lorsque la loi
                                    l'exige.
                                </li>
                            </ul>
                            <p class="mt-3">
                                Tous les destinataires sont soumis à une
                                obligation de confidentialité.
                            </p>
                        </section>

                        <section>
                            <h2 class="mb-3 text-xl font-semibold text-foreground">
                                7. Conservation des données
                            </h2>
                            <p>
                                Les données personnelles sont conservées
                                uniquement pendant la durée strictement
                                nécessaire aux finalités pour lesquelles elles
                                ont été collectées, ainsi que pour satisfaire
                                aux obligations légales applicables.
                            </p>
                            <p class="mt-3">
                                À l'expiration de ces délais, les données sont
                                supprimées ou anonymisées.
                            </p>
                        </section>

                        <section>
                            <h2 class="mb-3 text-xl font-semibold text-foreground">
                                8. Sécurité des données
                            </h2>
                            <p>
                                Eau la Maman met en œuvre des mesures
                                techniques et organisationnelles appropriées
                                afin de garantir un niveau de sécurité adapté
                                aux risques, notamment :
                            </p>
                            <ul class="mt-3 list-disc space-y-1 pl-5">
                                <li>authentification sécurisée des utilisateurs ;</li>
                                <li>contrôle des accès ;</li>
                                <li>
                                    chiffrement des communications lorsque
                                    cela est applicable ;
                                </li>
                                <li>sauvegardes régulières ;</li>
                                <li>surveillance des accès non autorisés.</li>
                            </ul>
                            <p class="mt-3">
                                Malgré toutes les précautions mises en œuvre,
                                aucun système informatique ne peut garantir
                                une sécurité absolue.
                            </p>
                        </section>

                        <section>
                            <h2 class="mb-3 text-xl font-semibold text-foreground">
                                9. Droits des utilisateurs
                            </h2>
                            <p>
                                Sous réserve des dispositions légales
                                applicables, chaque utilisateur dispose des
                                droits suivants :
                            </p>
                            <ul class="mt-3 list-disc space-y-1 pl-5">
                                <li>droit d'accès à ses données ;</li>
                                <li>droit de rectification ;</li>
                                <li>droit à l'effacement ;</li>
                                <li>droit à la limitation du traitement ;</li>
                                <li>droit d'opposition ;</li>
                                <li>
                                    droit à la portabilité de ses données
                                    lorsque celui-ci est applicable.
                                </li>
                            </ul>
                            <p class="mt-3">
                                Toute demande peut être adressée aux
                                coordonnées indiquées à l'article 13.
                            </p>
                        </section>

                        <section>
                            <h2 class="mb-3 text-xl font-semibold text-foreground">
                                10. Cookies et technologies similaires
                            </h2>
                            <p>
                                L'application peut utiliser des technologies
                                similaires aux cookies afin d'assurer son bon
                                fonctionnement, de sécuriser les sessions
                                utilisateurs et d'améliorer l'expérience
                                utilisateur.
                            </p>
                            <p class="mt-3">
                                Ces technologies ne sont pas utilisées à des
                                fins publicitaires.
                            </p>
                        </section>

                        <section>
                            <h2 class="mb-3 text-xl font-semibold text-foreground">
                                11. Services tiers
                            </h2>
                            <p>
                                L'application peut s'appuyer sur certains
                                services tiers nécessaires à son
                                fonctionnement, notamment :
                            </p>
                            <ul class="mt-3 list-disc space-y-1 pl-5">
                                <li>services d'hébergement ;</li>
                                <li>services d'authentification ;</li>
                                <li>services de notification ;</li>
                                <li>outils d'analyse et de diagnostic.</li>
                            </ul>
                            <p class="mt-3">
                                Ces prestataires n'ont accès aux données que
                                dans la mesure strictement nécessaire à
                                l'exécution de leurs missions.
                            </p>
                        </section>

                        <section>
                            <h2 class="mb-3 text-xl font-semibold text-foreground">
                                12. Protection des mineurs
                            </h2>
                            <p>
                                L'application n'est pas spécifiquement
                                destinée aux enfants de moins de 13 ans.
                            </p>
                            <p class="mt-3">
                                Si des données concernant un mineur étaient
                                collectées par erreur, elles seraient
                                supprimées dans les meilleurs délais après en
                                avoir été informés.
                            </p>
                        </section>

                        <section>
                            <h2 class="mb-3 text-xl font-semibold text-foreground">
                                13. Contact
                            </h2>
                            <p>
                                Pour toute question relative à la présente
                                Politique de confidentialité ou à l'exercice
                                de vos droits, vous pouvez contacter :
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
                                    Site web :
                                    <a
                                        href="https://eau-la-maman.fr"
                                        class="text-primary no-underline hover:underline"
                                        >https://eau-la-maman.fr</a
                                    >
                                </li>
                            </ul>
                        </section>

                        <section>
                            <h2 class="mb-3 text-xl font-semibold text-foreground">
                                14. Modification de la politique de
                                confidentialité
                            </h2>
                            <p>
                                Eau la Maman se réserve le droit de modifier
                                la présente Politique de confidentialité à
                                tout moment afin de tenir compte des
                                évolutions légales, réglementaires, techniques
                                ou fonctionnelles.
                            </p>
                            <p class="mt-3">
                                Toute modification prendra effet dès sa
                                publication sur cette page. Les utilisateurs
                                sont invités à consulter régulièrement cette
                                Politique afin de prendre connaissance des
                                éventuelles mises à jour.
                            </p>
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
