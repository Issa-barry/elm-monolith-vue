<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { Layers } from 'lucide-vue-next';
import { ref } from 'vue';

interface Module {
    key: string;
    label: string;
    active: boolean;
}

const props = defineProps<{ modules: Module[] }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Paramètres', href: '/settings/profile' },
    { title: 'Modules métier', href: '/settings/modules' },
];

const processing = ref<Record<string, boolean>>({});

function toggle(mod: Module) {
    if (processing.value[mod.key]) return;
    processing.value[mod.key] = true;

    router.patch(
        '/settings/modules',
        { module: mod.key, active: !mod.active },
        {
            preserveScroll: true,
            onFinish: () => {
                processing.value[mod.key] = false;
            },
        },
    );
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Modules métier" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall
                    title="Modules métier"
                    description="Activez ou désactivez les modules de l'application sans redéploiement"
                />

                <div class="overflow-hidden rounded-xl border bg-card">
                    <!-- En-tête -->
                    <div
                        class="flex items-center gap-2 border-b bg-muted/30 px-5 py-3"
                    >
                        <Layers class="h-4 w-4 text-muted-foreground" />
                        <h3 class="text-sm font-semibold text-foreground">
                            Modules disponibles
                        </h3>
                    </div>

                    <!-- Lignes -->
                    <div class="divide-y">
                        <div
                            v-for="mod in modules"
                            :key="mod.key"
                            class="flex items-center gap-4 px-5 py-4"
                        >
                            <!-- Label -->
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-foreground">
                                    {{ mod.label }}
                                </p>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    {{ mod.key }}
                                </p>
                            </div>

                            <!-- Toggle -->
                            <button
                                type="button"
                                role="switch"
                                :aria-checked="mod.active"
                                :disabled="processing[mod.key]"
                                class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                                :class="mod.active ? 'bg-primary' : 'bg-input'"
                                @click="toggle(mod)"
                            >
                                <span
                                    class="pointer-events-none block h-5 w-5 rounded-full bg-background shadow-lg ring-0 transition-transform"
                                    :class="
                                        mod.active
                                            ? 'translate-x-5'
                                            : 'translate-x-0'
                                    "
                                />
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
