<script setup lang="ts">
defineProps<{
    versionLabel?: string | null;
}>();

const year = new Date().getFullYear();
</script>

<template>
    <footer class="mt-12 border-t border-slate-200/80 py-8">
        <div
            class="flex flex-col gap-2 text-sm text-slate-600 sm:flex-row sm:items-center sm:justify-between"
        >
            <p>(c) {{ year }} GBALAN. Tous droits reserves.</p>
            <p v-if="versionLabel" class="font-medium text-slate-700">
                {{ versionLabel }}
            </p>
        </div>
    </footer>
</template>
