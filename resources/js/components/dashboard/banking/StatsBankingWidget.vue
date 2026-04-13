<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';

interface StatsFactures {
    total_count: number;
    total_montant: number;
    payees_count: number;
    payees_montant: number;
    impayees_count: number;
    annulees_count: number;
    reste_a_encaisser: number;
}

defineProps<{ stats: StatsFactures }>();

function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}

// ── Carrousel mobile ────────────────────────────────────────────────────────────
const scrollerRef = ref<HTMLElement | null>(null);
const currentSlide = ref(0);
const totalSlides = 3;

function updateCurrentSlide() {
    const scroller = scrollerRef.value;
    if (!scroller) return;
    const cards = Array.from(
        scroller.querySelectorAll<HTMLElement>('[data-slide]'),
    );
    if (!cards.length) return;
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
        scroller.querySelectorAll<HTMLElement>('[data-slide]'),
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
    <!-- ══ MOBILE ══════════════════════════════════════════════════════════════ -->
    <div class="col-span-12 sm:hidden">
        <div
            ref="scrollerRef"
            class="flex snap-x snap-mandatory gap-3 overflow-x-auto px-1 pb-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
        >
            <!-- Slide 1 : Total Factures -->
            <div data-slide class="min-w-[88%] snap-start">
                <div
                    class="card relative h-full overflow-hidden border-transparent"
                >
                    <svg
                        viewBox="0 0 900 600"
                        xmlns="http://www.w3.org/2000/svg"
                        class="absolute top-0 left-0 h-full w-full"
                        preserveAspectRatio="none"
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
                    <div class="relative z-20 text-white">
                        <div class="mb-4 text-xl font-semibold">
                            Total Factures
                        </div>
                        <div class="mb-4 text-2xl font-bold">
                            {{ formatGNF(stats.total_montant) }}
                        </div>
                        <div class="text-sm">
                            {{ stats.total_count }} facture{{
                                stats.total_count !== 1 ? 's' : ''
                            }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Slide 2 : Factures payées -->
            <div data-slide class="min-w-[88%] snap-start">
                <div class="card flex h-full flex-col justify-center">
                    <span class="mb-3 text-xl font-medium text-foreground"
                        >Factures payées</span
                    >
                    <span class="mb-3 text-2xl font-bold text-primary">{{
                        formatGNF(stats.payees_montant)
                    }}</span>
                    <span class="text-sm text-muted-foreground">
                        {{ stats.payees_count }} facture{{
                            stats.payees_count !== 1 ? 's' : ''
                        }}
                        payée{{ stats.payees_count !== 1 ? 's' : '' }}
                    </span>
                </div>
            </div>

            <!-- Slide 3 : Reste à encaisser -->
            <div data-slide class="min-w-[88%] snap-start">
                <div class="card flex h-full flex-col justify-center">
                    <span class="mb-3 text-xl font-medium text-foreground"
                        >Reste à encaisser</span
                    >
                    <span class="mb-3 text-2xl font-bold text-primary">{{
                        formatGNF(stats.reste_a_encaisser)
                    }}</span>
                    <span class="text-sm text-muted-foreground">
                        {{ stats.impayees_count }} impayée{{
                            stats.impayees_count !== 1 ? 's' : ''
                        }}
                        — {{ stats.annulees_count }} annulée{{
                            stats.annulees_count !== 1 ? 's' : ''
                        }}
                    </span>
                </div>
            </div>
        </div>

        <!-- Dots -->
        <div class="mt-1.5 flex items-center justify-center gap-1.5">
            <button
                v-for="dotIndex in totalSlides"
                :key="dotIndex"
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

    <!-- ══ DESKTOP ═════════════════════════════════════════════════════════════ -->

    <!-- Card 1 : Total Factures -->
    <div class="col-span-12 hidden sm:block md:col-span-6 xl:col-span-4">
        <div class="card relative h-full overflow-hidden border-transparent">
            <svg
                viewBox="0 0 900 600"
                xmlns="http://www.w3.org/2000/svg"
                class="absolute top-0 left-0 h-full w-full"
                preserveAspectRatio="none"
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
            <div class="relative z-20 text-white">
                <div class="mb-4 text-xl font-semibold">Total Factures</div>
                <div class="mb-6 text-2xl font-bold">
                    {{ formatGNF(stats.total_montant) }}
                </div>
                <div class="text-sm">
                    {{ stats.total_count }} facture{{
                        stats.total_count !== 1 ? 's' : ''
                    }}
                </div>
            </div>
        </div>
    </div>

    <!-- Card 2 : Factures payées -->
    <div class="col-span-12 hidden sm:block md:col-span-6 xl:col-span-4">
        <div
            class="card flex h-full flex-col items-center justify-center text-center"
        >
            <span class="mb-4 text-xl font-medium text-foreground"
                >Factures payées</span
            >
            <span class="mb-4 text-2xl font-bold text-primary">{{
                formatGNF(stats.payees_montant)
            }}</span>
            <span class="text-sm text-muted-foreground">
                {{ stats.payees_count }} facture{{
                    stats.payees_count !== 1 ? 's' : ''
                }}
                payée{{ stats.payees_count !== 1 ? 's' : '' }}
            </span>
        </div>
    </div>

    <!-- Card 3 : Reste à encaisser -->
    <div class="col-span-12 hidden sm:block md:col-span-6 xl:col-span-4">
        <div
            class="card flex h-full flex-col items-center justify-center text-center"
        >
            <span class="mb-4 text-xl font-medium text-foreground"
                >Reste à encaisser</span
            >
            <span class="mb-4 text-2xl font-bold text-primary">{{
                formatGNF(stats.reste_a_encaisser)
            }}</span>
            <span class="text-sm text-muted-foreground">
                {{ stats.impayees_count }} impayée{{
                    stats.impayees_count !== 1 ? 's' : ''
                }}
                — {{ stats.annulees_count }} annulée{{
                    stats.annulees_count !== 1 ? 's' : ''
                }}
            </span>
        </div>
    </div>
</template>
