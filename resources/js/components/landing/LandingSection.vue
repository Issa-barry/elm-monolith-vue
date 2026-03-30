<script setup lang="ts">
const cards = [
    {
        title: 'Message clair',
        description:
            'Un bloc principal lisible qui explique en quelques secondes ce que ton produit apporte.',
    },
    {
        title: 'Parcours fluide',
        description:
            'Une architecture simple: accroche, preuves, benefices et CTA sans detour.',
    },
    {
        title: 'Base extensible',
        description:
            'Chaque partie est un composant independant, facile a enrichir selon tes besoins.',
    },
];
</script>

<template>
    <section
        id="landing-section"
        class="mt-10 rounded-3xl bg-white/80 p-6 shadow-sm lg:p-10"
    >
        <div class="max-w-3xl">
            <p
                class="text-xs font-semibold tracking-[0.2em] text-slate-500 uppercase"
            >
                Section
            </p>
            <h2 class="mt-2 text-3xl font-bold text-slate-950">
                Une structure simple qui convertit.
            </h2>
            <p class="mt-3 text-slate-700">
                Tu peux maintenant brancher ton contenu produit, des
                temoignages, et des CTA orientes objectif.
            </p>
        </div>

        <div class="mt-8 grid gap-4 md:grid-cols-3">
            <article
                v-for="card in cards"
                :key="card.title"
                class="rounded-2xl border border-slate-200 bg-slate-50 p-5 transition hover:-translate-y-0.5 hover:shadow-md"
            >
                <h3 class="text-xl font-semibold text-slate-950">
                    {{ card.title }}
                </h3>
                <p class="mt-2 text-sm leading-relaxed text-slate-700">
                    {{ card.description }}
                </p>
            </article>
        </div>
    </section>
</template>
