<script setup lang="ts">
import type { CommissionDetailTab } from '@/types/commission';
import {
    History,
    Info,
    ReceiptText,
    WalletCards,
    type LucideIcon,
} from 'lucide-vue-next';

const props = defineProps<{
    modelValue: CommissionDetailTab;
    counts?: {
        depenses?: number;
        paiements?: number;
    };
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: CommissionDetailTab): void;
}>();

const tabs: { key: CommissionDetailTab; label: string; icon: LucideIcon }[] = [
    { key: 'informations', label: 'Informations', icon: Info },
    { key: 'depenses', label: 'Dépenses', icon: ReceiptText },
    { key: 'paiements', label: 'Paiements', icon: WalletCards },
    { key: 'historique', label: 'Historique', icon: History },
];

function countFor(key: CommissionDetailTab): number | undefined {
    if (key === 'depenses') return props.counts?.depenses;
    if (key === 'paiements') return props.counts?.paiements;
    return undefined;
}
</script>

<template>
    <div
        class="flex gap-1 overflow-x-auto rounded-xl border bg-muted/30 p-1"
        role="tablist"
        aria-label="Sections du détail de commission"
    >
        <button
            v-for="tab in tabs"
            :key="tab.key"
            type="button"
            role="tab"
            :aria-selected="props.modelValue === tab.key"
            class="inline-flex shrink-0 items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-all"
            :class="
                props.modelValue === tab.key
                    ? 'bg-background text-foreground shadow-sm'
                    : 'text-muted-foreground hover:bg-background/60 hover:text-foreground'
            "
            @click="emit('update:modelValue', tab.key)"
        >
            <component :is="tab.icon" class="h-4 w-4" />
            {{ tab.label }}
            <span
                v-if="countFor(tab.key) !== undefined && countFor(tab.key)! > 0"
                class="rounded-full bg-muted px-1.5 py-0.5 text-[10px] tabular-nums"
                >{{ countFor(tab.key) }}</span
            >
        </button>
    </div>
</template>
