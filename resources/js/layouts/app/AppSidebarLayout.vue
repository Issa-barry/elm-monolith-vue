<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import { useScanInterceptor } from '@/composables/useScanInterceptor';
import type { BreadcrumbItemType } from '@/types';
import ConfirmDialog from 'primevue/confirmdialog';
import Toast from 'primevue/toast';

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

useScanInterceptor();
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar" class="overflow-x-hidden rounded-none">
            <div :class="hideMobileHeader ? 'hidden sm:block' : ''">
                <AppSidebarHeader
                    v-if="showHeader"
                    :breadcrumbs="breadcrumbs"
                />
            </div>
            <div class="flex w-full flex-1 flex-col">
                <slot />
            </div>
        </AppContent>
    </AppShell>
    <ConfirmDialog />
    <Toast position="bottom-right" />
    <!-- Groupe "top" dédié aux parcours fonctions RH / profils d'accès / sites (validation de
         compte, transfert de site...) — toast.add({ group: 'top', ... }) uniquement, jamais
         l'instance par défaut ci-dessus : ne change pas la position des toasts existants
         ailleurs dans l'app (cf. CLAUDE de session, "gestion des fonctions"). -->
    <Toast group="top" position="top-right" />
</template>
