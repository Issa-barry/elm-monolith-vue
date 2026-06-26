<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { formatPhoneDisplay } from '@/lib/utils';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, HandCoins } from 'lucide-vue-next';

const props = defineProps<{
    backHref: string;
    eyebrow: string;
    title: string;
    telephone?: string | null;
    canPay: boolean;
    payLabel: string;
}>();

const emit = defineEmits<{
    (e: 'pay'): void;
}>();
</script>

<template>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-center gap-3">
            <Link
                :href="props.backHref"
                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground hover:bg-muted/80"
            >
                <ArrowLeft class="h-4 w-4" />
            </Link>
            <div>
                <p
                    class="text-xs font-semibold tracking-[0.14em] text-muted-foreground uppercase"
                >
                    {{ props.eyebrow }}
                </p>
                <p class="mt-0.5 text-xl font-semibold">
                    {{ props.title }}
                </p>
                <p v-if="props.telephone" class="text-sm text-muted-foreground">
                    {{ formatPhoneDisplay(props.telephone) }}
                </p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <Button v-if="props.canPay" size="sm" @click="emit('pay')">
                <HandCoins class="mr-1.5 h-4 w-4" />
                {{ props.payLabel }}
            </Button>
        </div>
    </div>
</template>
