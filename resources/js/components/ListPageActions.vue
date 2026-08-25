<script setup lang="ts">
// Impose l'ordre visuel Exporter → Importer → Filtres → Nouveau des actions
// d'en-tête des pages de liste (standard UI, cf. AGENTS.md) : l'ordre est fixé
// ici, côté composant, pour qu'une page ne puisse plus le casser en déclarant
// ses slots dans un ordre différent. Un slot non fourni ne rend rien (aucune
// action fantôme), donc pas besoin de v-if par slot.
</script>

<template>
    <div
        data-testid="list-page-actions"
        class="flex flex-wrap items-center gap-2"
    >
        <slot name="export" />
        <slot name="import" />
        <slot name="filters" />
        <slot name="primary" />
    </div>
</template>
