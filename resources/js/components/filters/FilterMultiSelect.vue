<script setup lang="ts">
import MultiSelect from 'primevue/multiselect';
import { computed } from 'vue';

export interface MultiSelectOption {
    value: string | number;
    label: string;
}

const props = withDefaults(
    defineProps<{
        options: MultiSelectOption[];
        placeholder?: string;
    }>(),
    { placeholder: 'Tous' },
);

const model = defineModel<(string | number)[]>({ default: () => [] });

const allValues = computed(() => props.options.map((o) => String(o.value)));

const isAllSelected = computed(
    () =>
        allValues.value.length > 0 &&
        allValues.value.every((v) => (model.value ?? []).map(String).includes(v)),
);

const displayValue = computed(() => {
    const vals = (model.value ?? []).map(String);
    if (vals.length === 0) return [];
    return vals;
});

function toggleAll() {
    if (isAllSelected.value) {
        model.value = [];
    } else {
        model.value = allValues.value;
    }
}

function handleChange(newVal: (string | number)[]) {
    model.value = newVal;
}
</script>

<template>
    <MultiSelect
        :model-value="displayValue"
        :options="options"
        option-label="label"
        option-value="value"
        :placeholder="placeholder"
        class="w-full"
        fluid
        display="chip"
        :show-toggle-all="false"
        append-to="self"
        @update:model-value="handleChange"
    >
        <template #header>
            <div
                class="flex cursor-pointer items-center gap-2 border-b px-3 py-2 hover:bg-muted/50"
                @click.stop="toggleAll"
            >
                <div
                    class="flex h-4 w-4 shrink-0 items-center justify-center rounded border"
                    :class="
                        isAllSelected
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'border-input bg-background'
                    "
                >
                    <svg
                        v-if="isAllSelected"
                        class="h-3 w-3"
                        viewBox="0 0 12 12"
                        fill="none"
                    >
                        <path
                            d="M2 6l3 3 5-5"
                            stroke="currentColor"
                            stroke-width="1.5"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>
                </div>
                <span class="text-sm font-medium">Tous</span>
            </div>
        </template>
    </MultiSelect>
</template>
