<script setup lang="ts">
import type { CommissionDetailTab } from '@/types/commission';

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

const tabs: { key: CommissionDetailTab; label: string }[] = [
    { key: 'informations', label: 'Informations' },
    { key: 'depenses', label: 'Dépenses' },
    { key: 'paiements', label: 'Paiements' },
    { key: 'historique', label: 'Historique' },
];

function countFor(key: CommissionDetailTab): number | undefined {
    if (key === 'depenses') return props.counts?.depenses;
    if (key === 'paiements') return props.counts?.paiements;
    return undefined;
}
</script>

<template>
    <div class="flex border-b">
        <button
            v-for="tab in tabs"
            :key="tab.key"
            type="button"
            class="px-4 py-2 text-sm font-medium transition-colors"
            :class="
                props.modelValue === tab.key
                    ? 'border-b-2 border-primary text-primary'
                    : 'text-muted-foreground hover:text-foreground'
            "
            @click="emit('update:modelValue', tab.key)"
        >
            {{ tab.label }}
            <span
                v-if="countFor(tab.key) !== undefined && countFor(tab.key)! > 0"
                class="ml-1 rounded-full bg-muted px-1.5 py-0.5 text-[10px] tabular-nums"
                >{{ countFor(tab.key) }}</span
            >
        </button>
    </div>
</template>
