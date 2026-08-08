<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const organization = computed(() => page.props.auth.user?.organization);
const orgName = computed(() => organization.value?.name ?? page.props.name);
const logoUrl = computed(() => organization.value?.logo_url ?? null);
</script>

<template>
    <div
        v-if="logoUrl"
        class="flex aspect-square size-8 items-center justify-center overflow-hidden rounded-md bg-sidebar-primary"
    >
        <img
            :src="logoUrl"
            :alt="orgName"
            class="h-full w-full object-contain"
        />
    </div>
    <div
        v-else
        class="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground"
    >
        <AppLogoIcon
            class="size-5 fill-current text-sidebar-primary-foreground"
        />
    </div>
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span class="mb-0.5 truncate leading-tight font-semibold">{{
            orgName
        }}</span>
    </div>
</template>
