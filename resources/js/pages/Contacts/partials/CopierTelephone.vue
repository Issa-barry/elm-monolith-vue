<script setup lang="ts">
import { ref } from 'vue';

const props = defineProps<{ telephone: string }>();

const copie = ref(false);

async function copier(): Promise<void> {
    await navigator.clipboard.writeText(props.telephone);
    copie.value = true;
    setTimeout(() => (copie.value = false), 1500);
}
</script>

<template>
    <button
        type="button"
        class="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground"
        title="Cliquez pour copier le numéro de téléphone"
        @click="copier"
    >
        <i :class="copie ? 'pi pi-check' : 'pi pi-clipboard'" />
    </button>
</template>
