<script setup lang="ts">
import { cn } from '@/lib/utils';

// Modèle Apollo des Supports de trésorerie : tailles fixes, grille responsive dans la page.
withDefaults(
    defineProps<{
        title: string;
        value: string | number;
        unit?: string;
        detail?: string;
        warning?: boolean;
        as?: 'div' | 'button';
        active?: boolean;
        horizontal?: boolean;
    }>(),
    { as: 'div' },
);
</script>

<template>
    <component
        :is="as"
        :type="as === 'button' ? 'button' : undefined"
        :class="
            cn(
                'card h-full w-full min-w-0 p-7 text-left font-apollo antialiased shadow-[0_4px_30px_0_rgba(221,224,255,0.54)] dark:shadow-none',
                as === 'button' &&
                    'transition-colors hover:border-primary/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
                active && 'border-primary ring-1 ring-primary',
            )
        "
    >
        <div class="flex items-start justify-between gap-3">
            <span
                class="block text-[15.75px] leading-[24.5px] font-semibold text-slate-700 dark:text-slate-200"
                >{{ title }}</span
            >
            <slot name="indicator" />
        </div>
        <div class="mt-3.5 flex items-start justify-between">
            <div
                class="w-full min-w-0"
                :class="
                    horizontal &&
                    'sm:flex sm:items-center sm:justify-between sm:gap-7'
                "
            >
                <p
                    class="text-[31.5px] leading-[35px] font-bold text-slate-900 dark:text-slate-50"
                >
                    <span
                        class="mr-1.5 whitespace-nowrap"
                        data-slot="kpi-value"
                        >{{ value }}</span
                    >
                    <span
                        v-if="unit"
                        class="inline-block text-[14px] leading-[16.8px] font-medium whitespace-nowrap text-muted-foreground"
                        >{{ unit }}</span
                    >
                </p>
                <p
                    v-if="detail"
                    data-slot="kpi-detail"
                    :class="
                        cn(
                            'text-[14px] leading-[16.8px] font-medium',
                            warning
                                ? 'text-amber-700 dark:text-amber-400'
                                : 'text-muted-foreground',
                        )
                    "
                >
                    {{ detail }}
                </p>
            </div>
        </div>
        <slot />
    </component>
</template>
