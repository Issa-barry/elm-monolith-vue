<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetFooter,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { SlidersHorizontal } from 'lucide-vue-next';

withDefaults(
    defineProps<{
        title?: string;
        activeCount?: number;
        side?: 'left' | 'right';
        applyLabel?: string;
        resetLabel?: string;
    }>(),
    {
        title: 'Filtres',
        activeCount: 0,
        side: 'right',
        applyLabel: 'Appliquer les filtres',
        resetLabel: 'Réinitialiser',
    },
);

const emit = defineEmits<{
    apply: [];
    reset: [];
}>();

const open = defineModel<boolean>('open', { default: false });

function handleApply() {
    emit('apply');
    open.value = false;
}

function handleReset() {
    emit('reset');
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetTrigger as-child>
            <Button type="button" variant="outline" class="h-9 gap-2">
                <SlidersHorizontal class="h-4 w-4" />
                {{ title }}
                <span
                    v-if="activeCount > 0"
                    class="flex h-5 min-w-5 items-center justify-center rounded-full bg-primary px-1 text-[11px] font-semibold text-primary-foreground"
                >
                    {{ activeCount }}
                </span>
            </Button>
        </SheetTrigger>
        <SheetContent
            :side="side"
            class="flex w-full flex-col gap-0 sm:max-w-md"
        >
            <SheetHeader class="border-b">
                <SheetTitle>{{ title }}</SheetTitle>
            </SheetHeader>
            <div class="flex-1 space-y-4 overflow-y-auto px-4 py-4">
                <slot />
            </div>
            <SheetFooter class="flex-row border-t">
                <Button
                    type="button"
                    variant="outline"
                    class="flex-1"
                    @click="handleReset"
                >
                    {{ resetLabel }}
                </Button>
                <Button type="button" class="flex-1" @click="handleApply">
                    {{ applyLabel }}
                </Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
