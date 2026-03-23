<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const props = withDefaults(defineProps<{
    label: string;
    dotClass?: string;
    class?: HTMLAttributes['class'];
}>(), {
    dotClass: 'bg-zinc-400 dark:bg-zinc-500',
});
</script>

<template>
    <span :class="cn('inline-flex items-center gap-2 whitespace-nowrap text-xs font-medium text-foreground', props.class)">
        <span :class="cn('h-2.5 w-2.5 shrink-0 rounded-full', dotClass)" />
        <span>{{ label }}</span>
    </span>
</template>
