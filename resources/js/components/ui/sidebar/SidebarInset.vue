<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'

const props = defineProps<{
  class?: HTMLAttributes['class']
}>()
</script>

<template>
  <main
    data-slot="sidebar-inset"
    :class="cn(
      'bg-background relative flex min-h-svh w-full flex-1 flex-col rounded-none',
      'md:border-l md:border-sidebar-border',
      'md:peer-data-[variant=inset]:ml-0 md:peer-data-[variant=inset]:peer-data-[state=collapsed]:ml-0',
      props.class,
    )"
  >
    <slot />
  </main>
</template>
