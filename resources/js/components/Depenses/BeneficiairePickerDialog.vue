<script setup lang="ts" generic="T extends { id: string }">
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
        <DialogContent
            class="flex flex-col max-sm:inset-0 max-sm:h-dvh max-sm:max-h-dvh max-sm:w-full max-sm:max-w-none max-sm:translate-x-0 max-sm:translate-y-0 max-sm:rounded-none max-sm:pb-[max(1rem,env(safe-area-inset-bottom))] sm:max-h-[80vh] sm:max-w-lg max-sm:[&>button]:flex max-sm:[&>button]:size-11 max-sm:[&>button]:items-center max-sm:[&>button]:justify-center"
        >
            <DialogHeader class="shrink-0 pr-10 text-left">
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription class="sr-only">
                    Recherchez puis sélectionnez le concerné dans la liste.
                </DialogDescription>
            </DialogHeader>

            <div
                class="min-h-0 flex-1 space-y-4 overflow-y-auto sm:flex sm:flex-col sm:gap-4 sm:space-y-0 sm:overflow-hidden"
            >
                <div class="grid shrink-0 gap-3 sm:grid-cols-2 sm:gap-2">
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
                            class="max-sm:min-h-11 max-sm:text-base"
                        />
                    </div>
                </div>

                <div
                    role="listbox"
                    class="space-y-1 sm:-mx-1 sm:min-h-0 sm:flex-1 sm:overflow-y-auto sm:px-1"
                >
                    <button
                        v-for="option in filtered"
                        :key="option.id"
                        type="button"
                        role="option"
                        class="min-h-12 w-full rounded-lg px-3 py-3 text-left text-sm [overflow-wrap:anywhere] transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:min-h-0 sm:py-2"
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
            </div>
        </DialogContent>
    </Dialog>
</template>
