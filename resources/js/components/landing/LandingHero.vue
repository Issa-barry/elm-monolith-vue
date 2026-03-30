<script setup lang="ts">
import { login, register } from '@/routes';
import { Link } from '@inertiajs/vue3';

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
    <section
        class="rounded-3xl border border-slate-200 bg-white px-6 py-10 lg:px-12 lg:py-14"
    >
        <div class="grid gap-10 lg:grid-cols-[1.2fr_1fr] lg:items-center">
            <div>
                <p
                    class="inline-flex items-center rounded-full border border-slate-300 bg-white px-3 py-1 text-xs font-semibold tracking-[0.2em] text-slate-700 uppercase"
                >
                    Mission GBALAN
                </p>
                <h1
                    class="mt-4 text-4xl leading-tight font-bold text-slate-950 md:text-5xl"
                >
                    Une landing claire pour lancer ton produit comme une mission
                    orbitale.
                </h1>
                <p
                    class="mt-5 max-w-2xl text-base leading-relaxed text-slate-700"
                >
                    Concentre tes visiteurs sur l'essentiel: proposition de
                    valeur, benefices concrets et appel a l'action immediat.
                    Cette base est pensee pour evoluer vite.
                </p>

                <div class="mt-8 flex flex-wrap gap-3">
                    <Link
                        :href="login()"
                        class="inline-flex items-center rounded-full bg-slate-950 px-6 py-3 text-sm font-semibold text-white hover:bg-slate-800"
                    >
                        Demander une demo
                    </Link>
                    <Link
                        v-if="canRegister"
                        :href="register()"
                        class="inline-flex items-center rounded-full border border-slate-300 bg-white px-6 py-3 text-sm font-semibold text-slate-900 hover:border-slate-950"
                    >
                        Creer un compte
                    </Link>
                    <a
                        href="#landing-section"
                        class="inline-flex items-center rounded-full border border-transparent px-4 py-3 text-sm font-semibold text-slate-700 underline decoration-slate-400 underline-offset-4 hover:text-slate-950"
                    >
                        Explorer la section
                    </a>
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-6">
                <p
                    class="text-sm font-semibold tracking-[0.18em] text-slate-500 uppercase"
                >
                    Cockpit
                </p>
                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div
                        class="rounded-xl border border-slate-200 bg-slate-50 p-4"
                    >
                        <p
                            class="text-xs tracking-[0.14em] text-slate-500 uppercase"
                        >
                            Conversion
                        </p>
                        <p class="mt-2 text-2xl font-bold text-slate-950">
                            +38%
                        </p>
                        <p class="mt-1 text-xs text-slate-600">
                            apres redesign de la page d'accueil
                        </p>
                    </div>
                    <div
                        class="rounded-xl border border-slate-200 bg-slate-50 p-4"
                    >
                        <p
                            class="text-xs tracking-[0.14em] text-slate-500 uppercase"
                        >
                            Temps moyen
                        </p>
                        <p class="mt-2 text-2xl font-bold text-slate-950">
                            4m 12s
                        </p>
                        <p class="mt-1 text-xs text-slate-600">
                            passe sur la landing
                        </p>
                    </div>
                </div>
                <div
                    class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4"
                >
                    <p
                        class="text-xs tracking-[0.14em] text-slate-500 uppercase"
                    >
                        Etat de mission
                    </p>
                    <p class="mt-2 text-sm font-medium text-slate-900">
                        Pret pour le decollage. Ajoute ton contenu metier et
                        publie.
                    </p>
                </div>
            </div>
        </div>
    </section>
</template>
