<script setup lang="ts">
import { cn } from '@/lib/utils';

// Modèle Apollo des Supports de trésorerie : tailles fixes, grille responsive dans la page.
// `compact` : même langage (police, ombre, hiérarchie) en plus dense, pour une ligne de
// cinq ou six indicateurs (rapport par agence) — cf. docs/ui-cartes-et-informations.md.
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
        compact?: boolean;
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
                'card h-full w-full min-w-0 text-left font-apollo antialiased shadow-[0_4px_30px_0_rgba(221,224,255,0.54)] dark:shadow-none',
                compact ? 'px-5 py-4' : 'p-7',
                as === 'button' &&
                    'transition-colors hover:border-primary/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
                active && 'border-primary ring-1 ring-primary',
            )
        "
    >
        <div class="flex items-start justify-between gap-3">
            <span
                :class="
                    cn(
                        'block font-semibold text-slate-700 dark:text-slate-200',
                        compact
                            ? 'text-[13px] leading-[18px]'
                            : 'text-[15.75px] leading-[24.5px]',
                    )
                "
                >{{ title }}</span
            >
            <slot name="indicator" />
        </div>
        <div
            :class="
                cn(
                    'flex items-start justify-between',
                    compact ? 'mt-2' : 'mt-3.5',
                )
            "
        >
            <div
                class="w-full min-w-0"
                :class="
                    horizontal &&
                    'sm:flex sm:items-center sm:justify-between sm:gap-7'
                "
            >
                <p
                    :class="
                        compact
                            ? 'text-[22px] leading-[28px] font-bold text-slate-900 tabular-nums dark:text-slate-50'
                            : 'text-[31.5px] leading-[35px] font-bold text-slate-900 dark:text-slate-50'
                    "
                >
                    <span
                        class="mr-1.5 whitespace-nowrap"
                        data-slot="kpi-value"
                        >{{ value }}</span
                    >
                    <span
                        v-if="unit"
                        :class="
                            cn(
                                'inline-block font-medium whitespace-nowrap text-muted-foreground',
                                compact
                                    ? 'text-[12px] leading-[16px]'
                                    : 'text-[14px] leading-[16.8px]',
                            )
                        "
                        >{{ unit }}</span
                    >
                </p>
                <p
                    v-if="detail"
                    data-slot="kpi-detail"
                    :class="
                        cn(
                            'font-medium',
                            compact
                                ? 'mt-1 text-[12.5px] leading-[16px]'
                                : 'text-[14px] leading-[16.8px]',
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
