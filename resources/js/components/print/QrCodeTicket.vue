<script setup lang="ts">
import QRCode from 'qrcode';
import { onMounted, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        url: string;
        size?: number;
        label?: string;
    }>(),
    {
        size: 140,
        label: 'Scanner pour consulter la commande',
    },
);

const dataUrl = ref('');

async function generate() {
    if (!props.url) return;
    dataUrl.value = await QRCode.toDataURL(props.url, {
        width: props.size * 2,
        margin: 2,
        color: { dark: '#000000', light: '#ffffff' },
    });
}

onMounted(generate);
watch(() => props.url, generate);
</script>

<template>
    <div v-if="dataUrl" class="qr-wrapper">
        <img
            :src="dataUrl"
            :width="size"
            :height="size"
            alt="QR Code"
            class="qr-img"
        />
        <p v-if="label" class="qr-label">{{ label }}</p>
    </div>
</template>

<style scoped>
.qr-wrapper {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 10px 0 6px;
    gap: 4px;
}
.qr-img {
    display: block;
    image-rendering: crisp-edges;
    background: #fff;
    padding: 0;
}
.qr-label {
    font-size: 10px;
    color: #666;
    text-align: center;
    margin-top: 2px;
    font-family: 'Courier New', Courier, monospace;
}
</style>
