<script setup lang="ts">
import type { ArticleStock } from '@/types/dashboard-stock';
import { router } from '@inertiajs/vue3';
import Select from 'primevue/select';
import { ref, watch } from 'vue';

/**
 * Article de Stat-Stock : les quantités ne s'additionnent jamais entre articles (aucune unité
 * commune), l'état, les flux et le stock par site portent donc sur un article à la fois. Le choix
 * recharge la page avec `article`, en conservant les autres filtres (agence, période). Les choix
 * viennent du serveur, qui refuse un article hors du périmètre.
 */
const props = defineProps<{
    /** Adresse de la page, rechargée avec l'article choisi. */
    url: string;
    articles: ArticleStock[];
    article: string | null;
    /** Autres filtres de la page à conserver. */
    parametres?: Record<string, string>;
}>();

const choix = ref(props.article);
const chargement = ref(false);
const erreur = ref<string | null>(null);

function onChoix(valeur: string | null): void {
    if (!valeur || valeur === props.article) return;
    choix.value = valeur;

    router.get(
        props.url,
        { ...props.parametres, article: valeur },
        {
            preserveState: true,
            preserveScroll: true,
            onStart: () => {
                chargement.value = true;
                erreur.value = null;
            },
            onFinish: () => (chargement.value = false),
            onError: (erreurs) => {
                erreur.value = erreurs.article ?? 'Article invalide.';
                choix.value = props.article;
            },
        },
    );
}

// L'article actif est celui renvoyé par le serveur.
watch(
    () => props.article,
    (article) => (choix.value = article),
);
</script>

<template>
    <div
        class="flex max-w-full flex-col items-stretch gap-1.5 sm:items-end"
        data-testid="selecteur-article"
    >
        <Select
            :model-value="choix"
            :options="articles"
            option-label="label"
            option-value="value"
            aria-label="Article"
            :disabled="chargement || articles.length === 0"
            :loading="chargement"
            filter
            class="min-w-56 text-sm"
            data-testid="article-choix"
            @update:model-value="onChoix"
        />
        <p
            v-if="erreur"
            role="alert"
            class="text-xs text-destructive"
            data-testid="article-erreur"
        >
            {{ erreur }}
        </p>
    </div>
</template>
