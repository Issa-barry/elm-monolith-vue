<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { Button } from '@/components/ui/button';
import { useAppearance } from '@/composables/useAppearance';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItemType } from '@/types';
import { Bell, Moon, Sun } from 'lucide-vue-next';
import { onMounted, ref } from 'vue';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItemType[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const { updateAppearance } = useAppearance();
const isDark = ref(false);

function syncThemeState() {
    if (typeof document === 'undefined') return;
    isDark.value = document.documentElement.classList.contains('dark');
}

function toggleTheme() {
    updateAppearance(isDark.value ? 'light' : 'dark');
    syncThemeState();
}

onMounted(() => {
    syncThemeState();
});
</script>

<template>
    <header
        class="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/70 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4"
    >
        <div class="flex items-center gap-2">
            <SidebarTrigger class="-ml-1" />
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </template>
        </div>

        <div class="ml-auto flex items-center gap-1">
            <Button variant="ghost" size="icon" class="relative h-9 w-9" @click="toggleTheme">
                <Sun v-if="isDark" class="h-5 w-5" />
                <Moon v-else class="h-5 w-5" />
                <span class="sr-only">Changer le theme</span>
            </Button>
            <Button variant="ghost" size="icon" class="relative h-9 w-9">
                <Bell class="h-5 w-5" />
                <span class="sr-only">Notifications</span>
            </Button>
        </div>
    </header>
</template>
