<script setup lang="ts">
import { onMounted, onUnmounted, ref, watch } from 'vue';

interface Suggestion {
    label: string;
    value: string;
}

const props = withDefaults(
    defineProps<{
        label: string;
        suggestionsUrl: string;
        fieldName: string;
        placeholder?: string;
        disabled?: boolean;
    }>(),
    { placeholder: '', disabled: false },
);

const emit = defineEmits<{
    select: [];
}>();

const model = defineModel<string>({ default: '' });

const suggestions = ref<Suggestion[]>([]);
const open = ref(false);
const rootRef = ref<HTMLDivElement>();

let timer: ReturnType<typeof setTimeout>;

watch(model, (val) => {
    clearTimeout(timer);
    if (!val || val.length < 2) {
        suggestions.value = [];
        open.value = false;
        return;
    }
    timer = setTimeout(() => fetchSuggestions(val), 300);
});

async function fetchSuggestions(q: string) {
    try {
        const url =
            `${props.suggestionsUrl}?field=${encodeURIComponent(props.fieldName)}&q=${encodeURIComponent(q)}`;
        const res = await fetch(url, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (res.ok) {
            suggestions.value = await res.json();
            open.value = suggestions.value.length > 0;
        }
    } catch {
        suggestions.value = [];
    }
}

function pick(s: Suggestion) {
    model.value = s.value;
    open.value = false;
    suggestions.value = [];
    emit('select');
}

function onClickOutside(e: MouseEvent) {
    if (rootRef.value && !rootRef.value.contains(e.target as Node)) {
        open.value = false;
    }
}

onMounted(() => document.addEventListener('mousedown', onClickOutside));
onUnmounted(() => {
    document.removeEventListener('mousedown', onClickOutside);
    clearTimeout(timer);
});
</script>

<template>
    <div ref="rootRef" class="relative flex shrink-0 flex-col gap-1">
        <span class="text-xs font-medium text-muted-foreground">{{ label }}</span>
        <div class="relative w-[180px]">
            <input
                v-model="model"
                type="text"
                :placeholder="placeholder"
                :disabled="disabled"
                class="h-9 w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50"
                @keydown.escape="open = false"
                @keydown.enter.prevent="open = false; $emit('select')"
            />

            <ul
                v-if="open && suggestions.length"
                class="absolute left-0 top-full z-50 mt-1 w-max min-w-full overflow-auto rounded-md border bg-popover text-popover-foreground shadow-md"
                style="max-height: 220px"
            >
                <li
                    v-for="s in suggestions"
                    :key="s.value"
                    class="cursor-pointer px-3 py-2 text-sm hover:bg-accent hover:text-accent-foreground"
                    @mousedown.prevent="pick(s)"
                >
                    {{ s.label }}
                </li>
            </ul>
        </div>
    </div>
</template>
