<script setup lang="ts" generic="T extends { id: string }">
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { computed, reactive, watch } from 'vue';
import type { PickerField } from './pickerTypes';

const props = defineProps<{
    visible: boolean;
    title: string;
    options: T[];
    fields: PickerField<T>[];
    emptyLabel?: string;
}>();

const emit = defineEmits<{
    'update:visible': [value: boolean];
    select: [option: T];
}>();

const queries = reactive<Record<string, string>>({});

watch(
    () => props.visible,
    (visible) => {
        if (visible) {
            for (const f of props.fields) queries[f.key] = '';
        }
    },
);

function normalizeDigits(s: string) {
    return s.replace(/\D/g, '');
}

function fieldMatches(field: PickerField<T>, option: T, q: string): boolean {
    const raw = field.value(option);
    if (!raw) return false;
    if (field.phone) {
        const digits = normalizeDigits(q);
        return digits
            ? normalizeDigits(raw).includes(digits)
            : raw.toLowerCase().includes(q);
    }
    return raw.toLowerCase().includes(q);
}

const filtered = computed<T[]>(() =>
    props.options.filter((option) =>
        props.fields.every((f) => {
            const q = (queries[f.key] ?? '').trim().toLowerCase();
            return !q || fieldMatches(f, option, q);
        }),
    ),
);

function select(option: T) {
    emit('select', option);
    emit('update:visible', false);
}
</script>

<template>
    <Dialog :open="visible" @update:open="(v) => emit('update:visible', v)">
        <DialogContent class="flex max-h-[80vh] flex-col sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
            </DialogHeader>

            <div
                class="grid shrink-0 gap-2"
                :class="fields.length > 2 ? 'sm:grid-cols-2' : 'grid-cols-2'"
            >
                <div v-for="(f, i) in fields" :key="f.key">
                    <Label
                        :for="`picker-${f.key}`"
                        class="mb-1 block text-xs font-medium text-muted-foreground"
                    >
                        {{ f.label }}
                    </Label>
                    <Input
                        :id="`picker-${f.key}`"
                        v-model="queries[f.key]"
                        :placeholder="f.placeholder ?? f.label"
                        :autofocus="i === 0"
                    />
                </div>
            </div>

            <div
                role="listbox"
                class="-mx-1 min-h-0 flex-1 space-y-0.5 overflow-y-auto px-1"
            >
                <button
                    v-for="option in filtered"
                    :key="option.id"
                    type="button"
                    role="option"
                    class="w-full rounded-md px-3 py-2 text-left text-sm transition-colors hover:bg-muted"
                    @click="select(option)"
                >
                    <slot name="option" :option="option" />
                </button>

                <div
                    v-if="filtered.length === 0"
                    class="px-3 py-8 text-center text-sm text-muted-foreground"
                >
                    {{ emptyLabel ?? 'Aucun résultat' }}
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
