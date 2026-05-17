<script setup lang="ts">
import type { KpiWidgetItem } from '@/types/kpi-widgets';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import KpiWidgetCard from './KpiWidgetCard.vue';

const props = withDefaults(
    defineProps<{
        items: KpiWidgetItem[];
        breakpoint?: 'sm' | 'md';
        mobileSlideWidthClass?: string;
        desktopWrapperClass?: string;
        desktopItemDefaultClass?: string;
        mobileCardMinHeightClass?: string;
        desktopCardMinHeightClass?: string;
    }>(),
    {
        breakpoint: 'md',
        mobileSlideWidthClass: 'max-w-[88%] min-w-[88%]',
        desktopWrapperClass: 'grid grid-cols-3 gap-4',
        desktopItemDefaultClass: '',
        mobileCardMinHeightClass: 'min-h-[176px]',
        desktopCardMinHeightClass: 'min-h-[176px]',
    },
);

const scrollerRef = ref<HTMLElement | null>(null);
const currentSlide = ref(0);

const totalSlides = computed(() => props.items.length);

const mobileVisibilityClass = computed(() =>
    props.breakpoint === 'sm' ? 'sm:hidden' : 'md:hidden',
);

const desktopVisibilityClass = computed(() =>
    props.breakpoint === 'sm' ? 'hidden sm:grid' : 'hidden md:grid',
);

function updateCurrentSlide() {
    const scroller = scrollerRef.value;
    if (!scroller) return;

    const cards = Array.from(
        scroller.querySelectorAll<HTMLElement>('[data-widget-slide]'),
    );
    if (!cards.length) {
        currentSlide.value = 0;
        return;
    }

    const viewportCenter = scroller.scrollLeft + scroller.clientWidth / 2;
    let closestIndex = 0;
    let minDistance = Number.POSITIVE_INFINITY;

    cards.forEach((card, index) => {
        const cardCenter = card.offsetLeft + card.offsetWidth / 2;
        const distance = Math.abs(cardCenter - viewportCenter);
        if (distance < minDistance) {
            minDistance = distance;
            closestIndex = index;
        }
    });

    currentSlide.value = closestIndex;
}

function goToSlide(index: number) {
    const scroller = scrollerRef.value;
    if (!scroller) return;

    const cards = Array.from(
        scroller.querySelectorAll<HTMLElement>('[data-widget-slide]'),
    );

    cards[index]?.scrollIntoView({
        behavior: 'smooth',
        inline: 'start',
        block: 'nearest',
    });
}

onMounted(() => {
    scrollerRef.value?.addEventListener('scroll', updateCurrentSlide, {
        passive: true,
    });
    updateCurrentSlide();
});

onUnmounted(() => {
    scrollerRef.value?.removeEventListener('scroll', updateCurrentSlide);
});
</script>

<template>
    <div v-if="items.length > 0" :class="mobileVisibilityClass">
        <div
            ref="scrollerRef"
            class="flex snap-x snap-mandatory items-stretch gap-3 overflow-x-auto px-1 pb-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
        >
            <div
                v-for="item in items"
                :key="`mobile-${item.id}`"
                data-widget-slide
                class="shrink-0 snap-start"
                :class="mobileSlideWidthClass"
            >
                <KpiWidgetCard
                    :item="item"
                    :min-height-class="mobileCardMinHeightClass"
                />
            </div>
        </div>

        <div
            v-if="totalSlides > 1"
            class="mt-1.5 flex items-center justify-center gap-1.5"
        >
            <button
                v-for="dotIndex in totalSlides"
                :key="`dot-${dotIndex}`"
                type="button"
                class="h-1.5 w-1.5 rounded-full transition-colors"
                :class="
                    dotIndex - 1 === currentSlide
                        ? 'bg-primary'
                        : 'bg-primary/30'
                "
                @click="goToSlide(dotIndex - 1)"
            />
        </div>
    </div>

    <div
        v-if="items.length > 0"
        :class="[desktopVisibilityClass, desktopWrapperClass]"
    >
        <div
            v-for="item in items"
            :key="`desktop-${item.id}`"
            :class="item.desktopClass || desktopItemDefaultClass"
        >
            <KpiWidgetCard
                :item="item"
                :min-height-class="desktopCardMinHeightClass"
            />
        </div>
    </div>
</template>
