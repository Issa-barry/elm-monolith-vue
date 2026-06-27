<script setup lang="ts">
import { X } from 'lucide-vue-next';
import { onMounted, onUnmounted } from 'vue';

const props = defineProps<{ url: string | null; alt?: string }>();
const emit = defineEmits<{ close: [] }>();

function onKeydown(e: KeyboardEvent) {
    if (e.key === 'Escape') emit('close');
}

onMounted(() => document.addEventListener('keydown', onKeydown));
onUnmounted(() => document.removeEventListener('keydown', onKeydown));
</script>

<template>
    <Teleport to="body">
        <div
            v-if="props.url"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4"
            @click.self="emit('close')"
        >
            <div class="relative max-h-full max-w-3xl">
                <button
                    type="button"
                    class="absolute -top-3 -right-3 flex h-8 w-8 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/20"
                    @click="emit('close')"
                >
                    <X class="h-5 w-5" />
                </button>
                <img
                    :src="props.url"
                    :alt="props.alt"
                    class="max-h-[80vh] max-w-full rounded-xl object-contain shadow-2xl"
                />
                <p
                    v-if="props.alt"
                    class="mt-2 text-center text-sm text-white/70"
                >
                    {{ props.alt }}
                </p>
            </div>
        </div>
    </Teleport>
</template>
