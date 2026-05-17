<script setup lang="ts">
import type { KpiWidgetItem } from '@/types/kpi-widgets';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        item: KpiWidgetItem;
        minHeightClass?: string;
    }>(),
    {
        minHeightClass: 'min-h-[176px]',
    },
);

const isPrimaryWave = computed(
    () => (props.item.variant ?? 'default') === 'primary-wave',
);

const alignClass = computed(() =>
    (props.item.align ?? 'left') === 'center'
        ? 'items-center text-center'
        : 'items-start text-left',
);

const titleClass = computed(() => {
    if (props.item.titleClass) {
        return props.item.titleClass;
    }

    return isPrimaryWave.value ? 'text-white/95' : 'text-muted-foreground';
});

const valueClass = computed(() => {
    if (props.item.valueClass) {
        return props.item.valueClass;
    }

    return isPrimaryWave.value ? 'text-white' : 'text-foreground';
});

const subtitleClass = computed(() => {
    if (props.item.subtitleClass) {
        return props.item.subtitleClass;
    }

    return isPrimaryWave.value ? 'text-white/90' : 'text-muted-foreground';
});

const noteClass = computed(() => {
    if (props.item.noteClass) {
        return props.item.noteClass;
    }

    return isPrimaryWave.value ? 'text-white/90' : 'text-muted-foreground';
});
</script>

<template>
    <div
        class="relative h-full overflow-hidden rounded-xl border bg-card p-5"
        :class="[
            minHeightClass,
            isPrimaryWave
                ? 'border-transparent bg-primary text-white'
                : 'border-border',
            item.cardClass,
        ]"
    >
        <svg
            v-if="isPrimaryWave"
            viewBox="0 0 900 600"
            xmlns="http://www.w3.org/2000/svg"
            class="absolute top-0 left-0 h-full w-full"
            preserveAspectRatio="none"
            aria-hidden="true"
        >
            <rect
                x="0"
                y="0"
                width="900"
                height="600"
                fill="var(--p-primary-600)"
            />
            <path
                d="M0 400L30 386.5C60 373 120 346 180 334.8C240 323.7 300 328.3 360 345.2C420 362 480 391 540 392C600 393 660 366 720 355.2C780 344.3 840 349.7 870 352.3L900 355L900 601L870 601C840 601 780 601 720 601C660 601 600 601 540 601C480 601 420 601 360 601C300 601 240 601 180 601C120 601 60 601 30 601L0 601Z"
                fill="var(--p-primary-500)"
            />
        </svg>

        <div class="relative z-10 flex h-full flex-col" :class="alignClass">
            <p class="text-sm" :class="titleClass">{{ item.title }}</p>
            <p class="mt-2 text-2xl font-semibold" :class="valueClass">
                {{ item.value }}
            </p>

            <div class="mt-auto pt-1">
                <p class="text-xs" :class="subtitleClass">
                    {{ item.subtitle || '\u00A0' }}
                </p>
                <p
                    class="mt-1 text-xs"
                    :class="item.note ? noteClass : 'text-transparent'"
                >
                    {{ item.note || '\u00A0' }}
                </p>
            </div>
        </div>
    </div>
</template>
