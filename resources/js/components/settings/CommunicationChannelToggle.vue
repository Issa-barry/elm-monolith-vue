<script setup lang="ts">
import { Lock } from 'lucide-vue-next';

const props = defineProps<{
    modelValue: boolean;
    label: string;
    available: boolean;
    disabled?: boolean;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: boolean];
}>();

function toggle() {
    if (!props.available || props.disabled) return;
    emit('update:modelValue', !props.modelValue);
}
</script>

<template>
    <div class="flex items-center gap-2">
        <button
            type="button"
            role="switch"
            :aria-checked="modelValue"
            :disabled="!available || disabled"
            class="relative inline-flex h-5 w-9 shrink-0 rounded-full border-2 border-transparent transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-40"
            :class="modelValue && available ? 'bg-primary' : 'bg-input'"
            @click="toggle"
        >
            <span
                class="pointer-events-none block h-4 w-4 rounded-full bg-background shadow-lg ring-0 transition-transform"
                :class="modelValue && available ? 'translate-x-4' : 'translate-x-0'"
            />
        </button>
        <span
            class="flex items-center gap-1 text-xs"
            :class="available ? 'text-foreground' : 'text-muted-foreground'"
        >
            {{ label }}
            <Lock v-if="!available" class="h-3 w-3" />
        </span>
        <span v-if="!available" class="text-xs text-muted-foreground italic">
            Fournisseur non configuré
        </span>
    </div>
</template>
