<script setup lang="ts">
import type { CommissionProcessusOption } from '@/types/commission';
import Dropdown from 'primevue/dropdown';
import { computed } from 'vue';

const props = defineProps<{
    modelValue: string;
    options: CommissionProcessusOption[];
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
}>();

const localValue = computed({
    get: () => props.modelValue,
    set: (val: string) => emit('update:modelValue', val),
});
</script>

<template>
    <Dropdown
        v-model="localValue"
        :options="props.options"
        option-label="label"
        option-value="value"
        placeholder="Tous les processus"
        class="w-full text-sm sm:w-56"
    />
</template>
