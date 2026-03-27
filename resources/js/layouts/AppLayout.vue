<script setup lang="ts">
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItemType } from '@/types';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
    showHeader?: boolean;
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
    showHeader: true,
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs" :show-header="showHeader">
        <slot />
    </AppLayout>
</template>
