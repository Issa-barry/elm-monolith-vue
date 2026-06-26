<script setup lang="ts">
import type { PeriodeOption } from '@/types/commission';
import Dropdown from 'primevue/dropdown';
import { computed } from 'vue';

const props = defineProps<{
    modelValue: string;
    periodesDisponibles: PeriodeOption[];
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
}>();

const options = computed<PeriodeOption[]>(() => [
    { code: '', label: 'Toutes les périodes' },
    ...props.periodesDisponibles,
]);

const localValue = computed({
    get: () => props.modelValue,
    set: (val: string) => emit('update:modelValue', val),
});
</script>

<template>
    <Dropdown
        v-model="localValue"
        :options="options"
        option-label="label"
        option-value="code"
        placeholder="Toutes les périodes"
        class="w-full text-sm sm:w-64"
    />
</template>
