<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { formatPhoneDisplay } from '@/lib/utils';
import { Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    HandCoins,
    Phone,
    SlidersHorizontal,
} from 'lucide-vue-next';

const props = defineProps<{
    backHref: string;
    eyebrow: string;
    title: string;
    telephone?: string | null;
    activeFiltersLabel?: string;
    canPay: boolean;
    payLabel: string;
}>();

const emit = defineEmits<{
    (e: 'pay'): void;
}>();
</script>

<template>
    <header
        class="flex flex-wrap items-start justify-between gap-4 rounded-xl border bg-card px-4 py-4 shadow-sm sm:px-5"
    >
        <div class="flex min-w-0 items-start gap-3">
            <Link
                :href="props.backHref"
                aria-label="Retour à la liste des commissions"
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border bg-background text-muted-foreground shadow-sm transition-colors hover:bg-muted hover:text-foreground"
            >
                <ArrowLeft class="h-4 w-4" />
            </Link>
            <div class="min-w-0">
                <p
                    class="text-xs font-semibold tracking-[0.14em] text-muted-foreground uppercase"
                >
                    {{ props.eyebrow }}
                </p>
                <h1
                    class="mt-0.5 truncate text-2xl font-semibold tracking-tight"
                >
                    {{ props.title }}
                </h1>
                <div
                    class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground"
                >
                    <p
                        v-if="props.telephone"
                        class="inline-flex items-center gap-1.5"
                    >
                        <Phone class="h-3.5 w-3.5" />
                        {{ formatPhoneDisplay(props.telephone) }}
                    </p>
                    <p
                        v-if="props.activeFiltersLabel"
                        class="inline-flex min-w-0 items-center gap-1.5"
                        data-testid="commission-active-filters-label"
                    >
                        <SlidersHorizontal class="h-3.5 w-3.5 shrink-0" />
                        <span class="truncate">{{
                            props.activeFiltersLabel
                        }}</span>
                    </p>
                </div>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <Button v-if="props.canPay" @click="emit('pay')">
                <HandCoins class="mr-1.5 h-4 w-4" />
                {{ props.payLabel }}
            </Button>
        </div>
    </header>
</template>
