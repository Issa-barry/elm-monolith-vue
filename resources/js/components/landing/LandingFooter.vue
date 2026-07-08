<script setup lang="ts">
defineProps<{
    versionLabel?: string | null;
}>();

const year = new Date().getFullYear();
</script>

<template>
    <footer class="mt-12 border-t border-slate-200/80 py-8">
        <div
            class="flex flex-col gap-3 text-sm text-slate-600 sm:flex-row sm:items-center sm:justify-between"
        >
            <p>(c) {{ year }} GBALAN. Tous droits reserves.</p>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                <a
                    href="/privacy-policy"
                    class="font-medium text-slate-600 no-underline hover:text-primary hover:underline"
                    >Politique de confidentialité</a
                >
                <a
                    href="/delete-account"
                    class="font-medium text-slate-600 no-underline hover:text-primary hover:underline"
                    >Suppression de compte</a
                >
            </div>
            <p v-if="versionLabel" class="font-medium text-slate-700">
                {{ versionLabel }}
            </p>
        </div>
    </footer>
</template>
