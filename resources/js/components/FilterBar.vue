<template>
    <div
        class="flex flex-wrap items-center gap-2 rounded-xl border bg-muted/30 p-3"
    >
        <slot />
        <div v-if="$slots.actions" class="ml-auto flex gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
