<script setup lang="ts">
import FilterBar from '@/components/FilterBar.vue';
import { Search, X } from 'lucide-vue-next';

defineProps<{
    hasActiveFilters?: boolean;
    searchPlaceholder?: string;
    resultCount?: number;
}>();

const emit = defineEmits<{
    reset: [];
}>();

const search = defineModel<string>('search');
const dateDebut = defineModel<string>('dateDebut');
const dateFin = defineModel<string>('dateFin');
</script>

<template>
    <FilterBar>
        <div v-if="search !== undefined" class="relative w-[200px] shrink-0">
            <Search
                class="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground"
            />
            <input
                v-model="search"
                type="search"
                :placeholder="searchPlaceholder ?? 'Rechercher…'"
                data-testid="search-input"
                class="h-9 w-full rounded-md border border-input bg-background py-2 pr-7 pl-8 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            />
            <button
                v-if="search"
                type="button"
                class="absolute top-1/2 right-2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                @click="search = ''"
            >
                <X class="h-3.5 w-3.5" />
            </button>
        </div>

        <slot />

        <template v-if="dateDebut !== undefined || dateFin !== undefined">
            <input
                v-if="dateDebut !== undefined"
                v-model="dateDebut"
                type="date"
                class="h-9 w-[140px] rounded-md border border-input bg-background px-2 text-sm"
            />
            <input
                v-if="dateFin !== undefined"
                v-model="dateFin"
                type="date"
                class="h-9 w-[140px] rounded-md border border-input bg-background px-2 text-sm"
            />
        </template>

        <template #actions>
            <span
                v-if="resultCount !== undefined"
                class="shrink-0 text-xs whitespace-nowrap text-muted-foreground"
            >
                {{ resultCount }} résultat{{ resultCount !== 1 ? 's' : '' }}
            </span>
            <button
                v-if="hasActiveFilters"
                type="button"
                class="shrink-0 text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                @click="emit('reset')"
            >
                Réinitialiser
            </button>
        </template>
    </FilterBar>
</template>
