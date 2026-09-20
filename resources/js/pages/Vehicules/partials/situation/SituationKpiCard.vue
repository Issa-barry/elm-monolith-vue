<script setup lang="ts">
import { formatQuantite } from '@/lib/utils';
import type { Component } from 'vue';

withDefaults(
    defineProps<{
        label: string;
        value: number;
        unit?: string;
        icon: Component;
        tone?: 'primary' | 'success' | 'warning' | 'info';
        highlight?: boolean;
    }>(),
    { unit: undefined, tone: 'primary', highlight: false },
);

const TONES = {
    primary: 'bg-primary/10 text-primary',
    success: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    warning: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
    info: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
};
</script>

<template>
    <div
        class="rounded-xl border bg-card p-5 shadow-sm"
        :class="
            highlight
                ? 'border-amber-300/70 bg-amber-500/5 dark:border-amber-900'
                : ''
        "
    >
        <div class="flex items-start justify-between gap-3">
            <p class="text-sm font-medium text-muted-foreground">
                {{ label }}
            </p>
            <span
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg"
                :class="TONES[tone]"
            >
                <component :is="icon" class="h-[18px] w-[18px]" />
            </span>
        </div>
        <p
            class="mt-4 flex flex-wrap items-baseline gap-x-2 text-3xl font-bold tracking-tight"
        >
            <span class="whitespace-nowrap">{{ formatQuantite(value) }}</span>
            <span
                v-if="unit"
                class="text-sm font-semibold tracking-normal text-muted-foreground"
            >
                {{ unit }}
            </span>
        </p>
    </div>
</template>
