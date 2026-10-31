<script setup lang="ts">
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { Info } from 'lucide-vue-next';
import { ref } from 'vue';

defineProps<{ label: string }>();

// Survol, focus clavier ou appui : un écran tactile n'a pas de survol, l'appui ouvre et ferme
// l'explication (un appui ailleurs la ferme aussi).
const ouvert = ref(false);
</script>

<template>
    <TooltipProvider :delay-duration="150">
        <Tooltip v-model:open="ouvert" disable-closing-trigger>
            <TooltipTrigger as-child>
                <button
                    type="button"
                    :aria-label="label"
                    class="shrink-0 rounded-sm text-primary transition-colors outline-none hover:text-primary/80 focus-visible:ring-2 focus-visible:ring-ring"
                    @click="ouvert = !ouvert"
                >
                    <Info class="h-4 w-4" aria-hidden="true" />
                </button>
            </TooltipTrigger>
            <TooltipContent
                side="bottom"
                class="z-[1200] w-80 max-w-[calc(100vw-2rem)] px-4 py-3 text-left text-sm leading-relaxed font-normal text-pretty"
            >
                <slot />
            </TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
