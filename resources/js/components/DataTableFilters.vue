<script setup lang="ts">
import FilterBar from '@/components/FilterBar.vue';
import { Button } from '@/components/ui/button';
import { Search, X } from 'lucide-vue-next';

defineProps<{
    hasActiveFilters?: boolean;
    searchPlaceholder?: string;
    withDates?: boolean;
    withSearch?: boolean;
}>();

const emit = defineEmits<{
    filter: [];
    reset: [];
}>();

const search = defineModel<string>('search');
const dateDebut = defineModel<string>('dateDebut');
const dateFin = defineModel<string>('dateFin');
</script>

<template>
    <FilterBar>
        <slot />

        <template
            v-if="
                withDates !== false &&
                (dateDebut !== undefined || dateFin !== undefined)
            "
        >
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

        <div
            v-if="search !== undefined && withSearch !== false"
            class="relative w-[240px]"
        >
            <Search
                class="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground"
            />
            <input
                v-model="search"
                type="search"
                :placeholder="searchPlaceholder ?? 'Rechercher…'"
                class="h-9 w-full rounded-md border border-input bg-background pr-3 pl-8 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                @keydown.enter="emit('filter')"
            />
        </div>

        <template #actions>
            <Button size="sm" @click="emit('filter')">
                <Search class="mr-1.5 h-3.5 w-3.5" />
                Appliquer les filtres
            </Button>
            <Button
                v-if="hasActiveFilters"
                size="sm"
                variant="ghost"
                @click="emit('reset')"
            >
                <X class="mr-1.5 h-3.5 w-3.5" />
                Réinitialiser
            </Button>
        </template>
    </FilterBar>
</template>
