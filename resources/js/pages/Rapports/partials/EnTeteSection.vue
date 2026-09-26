<script setup lang="ts">
import { Info } from 'lucide-vue-next';

// L'aide reste accessible au clic et au clavier, y compris sur téléphone.
defineProps<{
    titre: string;
    aide: string;
    contexte?: string;
    chiffres?: { libelle: string; valeur: string }[];
}>();
</script>

<template>
    <div class="space-y-4">
        <div class="relative flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <h2 class="text-base font-semibold">{{ titre }}</h2>
                <details class="group">
                    <summary
                        :aria-label="`Comprendre : ${titre}`"
                        class="flex h-8 w-8 cursor-pointer list-none items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring [&::-webkit-details-marker]:hidden"
                    >
                        <Info class="h-4 w-4" aria-hidden="true" />
                    </summary>
                    <p
                        class="absolute top-full left-0 z-20 mt-1 w-full max-w-sm rounded-lg border bg-popover p-3 text-sm leading-relaxed text-popover-foreground shadow-md"
                    >
                        {{ aide }}
                    </p>
                </details>
            </div>
            <p
                v-if="contexte"
                class="text-xs font-medium text-muted-foreground"
            >
                {{ contexte }}
            </p>
        </div>
        <dl
            v-if="chiffres && chiffres.length > 0"
            class="grid grid-cols-1 gap-4 rounded-lg bg-muted/40 p-3 sm:flex sm:flex-wrap sm:gap-x-8"
        >
            <div
                v-for="c in chiffres"
                :key="c.libelle"
                class="flex items-baseline justify-between gap-3 sm:block"
            >
                <dt class="text-xs text-muted-foreground">{{ c.libelle }}</dt>
                <dd class="text-sm font-semibold tabular-nums sm:mt-1">
                    {{ c.valeur }}
                </dd>
            </div>
        </dl>
    </div>
</template>
