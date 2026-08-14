<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { useEnvironmentTheme } from '@/composables/useEnvironmentTheme';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import {
    PRIMEVUE_PRIMARY_SWATCHES,
    PRIMEVUE_SURFACE_SWATCHES,
    PRIMEVUE_THEME_LABELS,
    type PrimeVuePrimaryName,
    type PrimeVueSurfaceName,
    type PrimeVueThemeName,
} from '@/lib/primevue-theme';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { Lock, Palette } from 'lucide-vue-next';
import { useToast } from 'primevue/usetoast';
import { computed, ref, watch } from 'vue';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Paramètres', href: '/settings/profile' },
    { title: 'Thème', href: '/settings/theme' },
];

const toast = useToast();
const { active, allowed, locked, processing, update } = useEnvironmentTheme();

// Brouillon local : les swatches ne s'appliquent qu'au clic sur "Enregistrer"
// (une modification de thème est visible par TOUS les utilisateurs de
// l'environnement, pas juste par l'admin qui manipule l'écran — pas de
// sauvegarde silencieuse à chaque clic).
const draft = ref<{
    preset: PrimeVueThemeName;
    primary: PrimeVuePrimaryName;
    surface: PrimeVueSurfaceName;
}>({ ...active.value });

watch(active, (value) => {
    draft.value = { ...value };
});

const hasChanges = computed(
    () =>
        draft.value.preset !== active.value.preset ||
        draft.value.primary !== active.value.primary ||
        draft.value.surface !== active.value.surface,
);

function save() {
    update({ ...draft.value });
    toast.add({
        severity: 'success',
        summary: 'Thème global mis à jour',
        detail: "Appliqué à tous les utilisateurs de l'environnement.",
        life: 3000,
    });
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head><title>Thème</title></Head>

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall
                    title="Thème global"
                    description="Preset, couleur principale et surface appliqués à tous les utilisateurs de cet environnement. La liste des valeurs proposées est fixée par le déploiement."
                />

                <div class="overflow-hidden rounded-xl border bg-card">
                    <div
                        class="flex items-center gap-2 border-b bg-muted/30 px-5 py-3"
                    >
                        <Palette class="h-4 w-4 text-muted-foreground" />
                        <h3 class="text-sm font-semibold text-foreground">
                            Apparence de l'environnement
                        </h3>
                    </div>

                    <div class="space-y-6 p-5">
                        <!-- Preset -->
                        <div class="space-y-2">
                            <p
                                class="flex items-center gap-1.5 text-xs font-medium tracking-wide text-muted-foreground uppercase"
                            >
                                Preset PrimeVue
                                <Lock
                                    v-if="locked.preset"
                                    class="h-3 w-3"
                                    aria-hidden="true"
                                />
                            </p>
                            <div
                                class="inline-flex gap-1 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800"
                            >
                                <button
                                    v-for="value in allowed.presets"
                                    :key="value"
                                    type="button"
                                    :disabled="locked.preset"
                                    @click="draft.preset = value"
                                    :class="[
                                        'rounded-md px-3.5 py-1.5 text-sm transition-colors disabled:cursor-not-allowed',
                                        draft.preset === value
                                            ? 'bg-white font-medium shadow-xs dark:bg-neutral-700 dark:text-neutral-100'
                                            : 'text-neutral-500 hover:bg-neutral-200/60 hover:text-black disabled:hover:bg-transparent dark:text-neutral-400 dark:hover:bg-neutral-700/60',
                                    ]"
                                >
                                    {{ PRIMEVUE_THEME_LABELS[value] }}
                                </button>
                            </div>
                            <p
                                v-if="locked.preset"
                                class="text-xs text-muted-foreground"
                            >
                                Verrouillé par la politique de cet
                                environnement.
                            </p>
                        </div>

                        <!-- Couleur principale -->
                        <div class="space-y-2">
                            <p
                                class="flex items-center gap-1.5 text-xs font-medium tracking-wide text-muted-foreground uppercase"
                            >
                                Couleur principale
                                <Lock
                                    v-if="locked.primary"
                                    class="h-3 w-3"
                                    aria-hidden="true"
                                />
                            </p>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="value in allowed.primaries"
                                    :key="value"
                                    type="button"
                                    :disabled="locked.primary"
                                    :aria-label="`Couleur ${value}`"
                                    @click="draft.primary = value"
                                    :class="[
                                        'flex h-8 w-8 items-center justify-center rounded-full border transition-all disabled:cursor-not-allowed',
                                        draft.primary === value
                                            ? 'border-foreground ring-2 ring-offset-2 ring-offset-background'
                                            : 'border-border hover:scale-105 disabled:hover:scale-100',
                                    ]"
                                >
                                    <span
                                        class="h-5 w-5 rounded-full"
                                        :class="
                                            PRIMEVUE_PRIMARY_SWATCHES[value]
                                        "
                                    />
                                </button>
                            </div>
                            <p
                                v-if="locked.primary"
                                class="text-xs text-muted-foreground"
                            >
                                Verrouillé par la politique de cet
                                environnement ({{ allowed.primaries[0] }}
                                uniquement).
                            </p>
                        </div>

                        <!-- Surface -->
                        <div class="space-y-2">
                            <p
                                class="flex items-center gap-1.5 text-xs font-medium tracking-wide text-muted-foreground uppercase"
                            >
                                Surface
                                <Lock
                                    v-if="locked.surface"
                                    class="h-3 w-3"
                                    aria-hidden="true"
                                />
                            </p>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="value in allowed.surfaces"
                                    :key="value"
                                    type="button"
                                    :disabled="locked.surface"
                                    :aria-label="`Surface ${value}`"
                                    @click="draft.surface = value"
                                    :class="[
                                        'flex h-8 w-8 items-center justify-center rounded-full border transition-all disabled:cursor-not-allowed',
                                        draft.surface === value
                                            ? 'border-foreground ring-2 ring-offset-2 ring-offset-background'
                                            : 'border-border hover:scale-105 disabled:hover:scale-100',
                                    ]"
                                >
                                    <span
                                        class="h-5 w-5 rounded-full"
                                        :class="
                                            PRIMEVUE_SURFACE_SWATCHES[value]
                                        "
                                    />
                                </button>
                            </div>
                            <p
                                v-if="locked.surface"
                                class="text-xs text-muted-foreground"
                            >
                                Verrouillé par la politique de cet
                                environnement.
                            </p>
                        </div>

                        <div class="flex justify-end border-t pt-4">
                            <Button
                                :disabled="!hasChanges || processing"
                                @click="save"
                            >
                                Enregistrer les changements
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
