<script setup lang="ts">
import ImageLightbox from '@/components/ImageLightbox.vue';
import StatusDot from '@/components/StatusDot.vue';
import { ref, type Component } from 'vue';

const props = withDefaults(
    defineProps<{
        /** Type d'entité affiché au-dessus du titre, ex: "Véhicule", "Propriétaire". */
        eyebrow: string;
        title: string;
        icon?: Component;
        photoUrl?: string | null;
        avatarShape?: 'circle' | 'square';
        statusLabel?: string;
        statusDotClass?: string;
    }>(),
    { avatarShape: 'circle' },
);

const lightboxOpen = ref(false);
</script>

<template>
    <div
        class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
    >
        <div class="flex items-start gap-4">
            <div
                v-if="icon || photoUrl"
                :class="[
                    'flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden',
                    avatarShape === 'circle'
                        ? 'rounded-full bg-primary text-primary-foreground'
                        : 'rounded-xl border bg-muted/30',
                    photoUrl && 'cursor-zoom-in',
                ]"
                @click="photoUrl && (lightboxOpen = true)"
            >
                <img
                    v-if="photoUrl"
                    :src="photoUrl"
                    :alt="title"
                    class="h-full w-full object-cover"
                />
                <component
                    :is="icon"
                    v-else-if="icon"
                    class="h-6 w-6"
                    :class="
                        avatarShape === 'square' && 'text-muted-foreground/30'
                    "
                />
            </div>
            <div>
                <p
                    class="text-xs font-medium tracking-widest text-muted-foreground uppercase"
                >
                    {{ eyebrow }}
                </p>
                <div class="mt-0.5 flex items-center gap-2">
                    <h1 class="text-2xl font-semibold tracking-tight">
                        {{ title }}
                    </h1>
                    <StatusDot
                        v-if="statusLabel"
                        :label="statusLabel"
                        :dot-class="statusDotClass"
                        class="text-sm text-muted-foreground"
                    />
                </div>
                <slot name="subtitle" />
            </div>
        </div>

        <div v-if="$slots.actions" class="flex items-center gap-2">
            <slot name="actions" />
        </div>
    </div>

    <ImageLightbox
        :url="lightboxOpen ? (photoUrl ?? null) : null"
        :alt="props.title"
        @close="lightboxOpen = false"
    />
</template>
