<script setup lang="ts">
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItemType } from '@/types';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
    showHeader?: boolean;
    hideMobileHeader?: boolean;
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
    showHeader: true,
    hideMobileHeader: false,
});
</script>

<template>
    <AppLayout
        :breadcrumbs="breadcrumbs"
        :show-header="showHeader"
        :hide-mobile-header="hideMobileHeader"
    >
        <slot />
    </AppLayout>
</template>
