<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

// Repère visuel "hors prod" — s'appuie sur le suffixe entre crochets déjà
// présent dans APP_NAME pour les environnements non-prod (ex: "Eau-la-maman
// [PREPROD]", "Eau-la-maman [E2E]", cf. .env.preprod.example / .env.e2e).
// Aucune nouvelle notion introduite : en prod, APP_NAME n'a pas de suffixe,
// le badge ne s'affiche donc simplement pas. Cf. docs/theming.md.
const page = usePage();

const label = computed(() => page.props.name?.match(/\[(.+?)\]/)?.[1] ?? null);
</script>

<template>
    <span
        v-if="label"
        class="inline-flex items-center gap-1 rounded-md border border-amber-500/40 bg-amber-500/10 px-2 py-0.5 text-[11px] font-semibold tracking-wide text-amber-700 uppercase dark:text-amber-400"
        :title="`Environnement : ${label} — pas la production`"
    >
        {{ label }}
    </span>
</template>
