<script setup lang="ts">
defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
    <section class="@container space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h3
                    class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    {{ title }}
                </h3>
                <p
                    v-if="description"
                    class="mt-1 text-xs text-muted-foreground"
                >
                    {{ description }}
                </p>
            </div>
            <div v-if="$slots.actions" class="flex items-center gap-2">
                <slot name="actions" />
            </div>
        </div>

        <slot />
    </section>
</template>
