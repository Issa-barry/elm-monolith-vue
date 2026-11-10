<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { Pagination } from './types';

defineProps<{ page: Pagination<unknown> }>();

function libelle(label: string): string {
    return label
        .replace('&laquo; Previous', '‹')
        .replace('Next &raquo;', '›')
        .replace('&laquo;', '‹')
        .replace('&raquo;', '›');
}
</script>

<template>
    <div
        v-if="page.last_page > 1"
        class="flex items-center justify-between gap-3"
    >
        <p class="text-sm text-muted-foreground tabular-nums">
            {{ page.from }}–{{ page.to }} sur {{ page.total }}
        </p>
        <div class="flex flex-wrap items-center gap-1">
            <component
                :is="link.url ? Link : 'span'"
                v-for="link in page.links"
                :key="link.label"
                :href="link.url ?? undefined"
                preserve-scroll
                class="inline-flex h-8 min-w-8 items-center justify-center rounded px-3 text-sm transition-colors"
                :class="[
                    link.active
                        ? 'bg-primary font-medium text-primary-foreground'
                        : 'text-muted-foreground hover:bg-muted/50',
                    !link.url ? 'pointer-events-none opacity-40' : '',
                ]"
            >
                {{ libelle(link.label) }}
            </component>
        </div>
    </div>
</template>
