<script setup lang="ts">
import { useAppearance } from '@/composables/useAppearance';
import { Monitor, Moon, Sun } from 'lucide-vue-next';

const { appearance, updateAppearance, primeVueTheme, updatePrimeVueTheme } = useAppearance();

const tabs = [
    { value: 'light', Icon: Sun, label: 'Light' },
    { value: 'dark', Icon: Moon, label: 'Dark' },
    { value: 'system', Icon: Monitor, label: 'System' },
] as const;

const primeVueThemes = [
    { value: 'aura', label: 'Aura' },
    { value: 'lara', label: 'Lara' },
    { value: 'material', label: 'Material' },
    { value: 'nora', label: 'Nora' },
] as const;
</script>

<template>
    <div class="space-y-4">
        <div
            class="inline-flex gap-1 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800"
        >
            <button
                v-for="{ value, Icon, label } in tabs"
                :key="value"
                @click="updateAppearance(value)"
                :class="[
                    'flex items-center rounded-md px-3.5 py-1.5 transition-colors',
                    appearance === value
                        ? 'bg-white shadow-xs dark:bg-neutral-700 dark:text-neutral-100'
                        : 'text-neutral-500 hover:bg-neutral-200/60 hover:text-black dark:text-neutral-400 dark:hover:bg-neutral-700/60',
                ]"
            >
                <component :is="Icon" class="-ml-1 h-4 w-4" />
                <span class="ml-1.5 text-sm">{{ label }}</span>
            </button>
        </div>

        <div class="space-y-2">
            <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                Theme PrimeVue
            </p>
            <div class="inline-flex gap-1 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800">
                <button
                    v-for="{ value, label } in primeVueThemes"
                    :key="value"
                    @click="updatePrimeVueTheme(value)"
                    :class="[
                        'rounded-md px-3.5 py-1.5 text-sm transition-colors',
                        primeVueTheme === value
                            ? 'bg-white font-medium shadow-xs dark:bg-neutral-700 dark:text-neutral-100'
                            : 'text-neutral-500 hover:bg-neutral-200/60 hover:text-black dark:text-neutral-400 dark:hover:bg-neutral-700/60',
                    ]"
                >
                    {{ label }}
                </button>
            </div>
        </div>
    </div>
</template>
