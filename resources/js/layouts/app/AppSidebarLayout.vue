<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import type { BreadcrumbItemType } from '@/types';
import ConfirmDialog from 'primevue/confirmdialog';
import Toast from 'primevue/toast';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar" class="overflow-x-hidden rounded-none">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <div class="flex flex-1 flex-col">
                <slot />
            </div>
        </AppContent>
    </AppShell>
    <ConfirmDialog />
    <Toast position="bottom-right" />
</template>
