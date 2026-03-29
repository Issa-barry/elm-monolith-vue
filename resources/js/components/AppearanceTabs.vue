<script setup lang="ts">
import { useAppearance } from '@/composables/useAppearance';
import { Monitor, Moon, Sun } from 'lucide-vue-next';

const {
    appearance,
    updateAppearance,
    primeVueTheme,
    updatePrimeVueTheme,
    primeVuePrimary,
    updatePrimeVuePrimary,
    primeVueSurface,
    updatePrimeVueSurface,
} = useAppearance();

const tabs = [
    { value: 'light', Icon: Sun, label: 'Light' },
    { value: 'dark', Icon: Moon, label: 'Dark' },
    { value: 'system', Icon: Monitor, label: 'System' },
] as const;

const primeVueThemes = [
    { value: 'starter', label: 'Starter Kit' },
    { value: 'aura', label: 'Aura' },
    { value: 'lara', label: 'Lara' },
    { value: 'material', label: 'Material' },
    { value: 'nora', label: 'Nora' },
] as const;

const primeVuePrimaryColors = [
    { value: 'zinc', colorClass: 'bg-zinc-500' },
    { value: 'emerald', colorClass: 'bg-emerald-500' },
    { value: 'green', colorClass: 'bg-green-500' },
    { value: 'lime', colorClass: 'bg-lime-500' },
    { value: 'orange', colorClass: 'bg-orange-500' },
    { value: 'amber', colorClass: 'bg-amber-500' },
    { value: 'yellow', colorClass: 'bg-yellow-500' },
    { value: 'teal', colorClass: 'bg-teal-500' },
    { value: 'cyan', colorClass: 'bg-cyan-500' },
    { value: 'sky', colorClass: 'bg-sky-500' },
    { value: 'blue', colorClass: 'bg-blue-500' },
    { value: 'indigo', colorClass: 'bg-indigo-500' },
    { value: 'violet', colorClass: 'bg-violet-500' },
    { value: 'purple', colorClass: 'bg-purple-500' },
    { value: 'fuchsia', colorClass: 'bg-fuchsia-500' },
    { value: 'pink', colorClass: 'bg-pink-500' },
    { value: 'rose', colorClass: 'bg-rose-500' },
] as const;

const primeVueSurfaceColors = [
    { value: 'zinc', colorClass: 'bg-zinc-500' },
    { value: 'slate', colorClass: 'bg-slate-500' },
    { value: 'stone', colorClass: 'bg-stone-500' },
    { value: 'neutral', colorClass: 'bg-neutral-500' },
    { value: 'gray', colorClass: 'bg-gray-500' },
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
            <p
                class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
            >
                Theme PrimeVue
            </p>
            <div
                class="inline-flex gap-1 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800"
            >
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

        <div class="space-y-2">
            <p
                class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
            >
                Couleur principale
            </p>
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="{ value, colorClass } in primeVuePrimaryColors"
                    :key="value"
                    type="button"
                    :aria-label="`Couleur ${value}`"
                    @click="updatePrimeVuePrimary(value)"
                    :class="[
                        'flex h-8 w-8 items-center justify-center rounded-full border transition-all',
                        primeVuePrimary === value
                            ? 'border-foreground ring-2 ring-offset-2 ring-offset-background'
                            : 'border-border hover:scale-105',
                    ]"
                >
                    <span class="h-5 w-5 rounded-full" :class="colorClass" />
                </button>
            </div>
        </div>

        <div class="space-y-2">
            <p
                class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
            >
                Surface
            </p>
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="{ value, colorClass } in primeVueSurfaceColors"
                    :key="value"
                    type="button"
                    :aria-label="`Surface ${value}`"
                    @click="updatePrimeVueSurface(value)"
                    :class="[
                        'flex h-8 w-8 items-center justify-center rounded-full border transition-all',
                        primeVueSurface === value
                            ? 'border-foreground ring-2 ring-offset-2 ring-offset-background'
                            : 'border-border hover:scale-105',
                    ]"
                >
                    <span class="h-5 w-5 rounded-full" :class="colorClass" />
                </button>
            </div>
        </div>
    </div>
</template>
