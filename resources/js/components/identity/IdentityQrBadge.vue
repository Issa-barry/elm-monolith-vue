<script setup lang="ts">
// Remplace le cercle d'initiales sur mobile (cf. HeaderWidget.vue) par le QR code
// d'identité de l'utilisateur, quand un profil propriétaire/livreur est réellement
// rattaché — même `qr_payload` (URL de fiche backoffice) que l'espace client Inertia et
// l'API mobile (App\Services\Client\QrPayloadResolver), même bibliothèque `qrcode` que
// resources/js/components/print/QrCodeTicket.vue. Ne fabrique jamais de QR de
// substitution : `qrValue` absent → état neutre "QR indisponible".
import Dialog from 'primevue/dialog';
import QRCode from 'qrcode';
import { computed, onMounted, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        qrValue?: string | null;
        name: string;
        subtitle?: string | null;
        size?: number;
        showCaption?: boolean;
    }>(),
    { qrValue: null, subtitle: null, size: 48, showCaption: false },
);

const hasQr = computed(() => Boolean(props.qrValue));
const dataUrl = ref('');
const expandedDataUrl = ref('');
const expanded = ref(false);

async function generate() {
    if (!props.qrValue) {
        dataUrl.value = '';
        expandedDataUrl.value = '';
        return;
    }

    const options = { margin: 1, color: { dark: '#111827', light: '#ffffff' } };
    [dataUrl.value, expandedDataUrl.value] = await Promise.all([
        QRCode.toDataURL(props.qrValue, { ...options, width: props.size * 4 }),
        QRCode.toDataURL(props.qrValue, { ...options, width: 480 }),
    ]);
}

onMounted(generate);
watch(() => props.qrValue, generate);
</script>

<template>
    <div class="flex flex-shrink-0 flex-col items-center gap-1">
        <button
            v-if="hasQr"
            type="button"
            class="flex items-center justify-center overflow-hidden rounded-lg border border-border bg-white p-1 transition-colors hover:border-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
            :style="{ width: `${size}px`, height: `${size}px` }"
            aria-haspopup="dialog"
            aria-label="Agrandir mon QR code"
            @click="expanded = true"
        >
            <img
                v-if="dataUrl"
                :src="dataUrl"
                :alt="`QR code de ${name}`"
                class="h-full w-full object-contain"
            />
        </button>
        <div
            v-else
            class="flex items-center justify-center rounded-lg border border-dashed border-border bg-muted px-1 text-center text-[8px] leading-tight text-muted-foreground"
            :style="{ width: `${size}px`, height: `${size}px` }"
        >
            QR indisponible
        </div>
        <span
            v-if="showCaption && hasQr"
            class="text-[10px] font-medium leading-none text-primary"
        >
            Agrandir
        </span>
    </div>

    <Dialog
        v-model:visible="expanded"
        modal
        header="Mon QR code"
        :style="{ width: 'min(92vw, 24rem)' }"
    >
        <div class="flex flex-col items-center gap-3 py-2">
            <div class="rounded-lg border border-border bg-white p-4">
                <img
                    v-if="expandedDataUrl"
                    :src="expandedDataUrl"
                    :alt="`QR code de ${name}`"
                    class="h-56 w-56"
                />
            </div>
            <div class="text-center">
                <p class="font-semibold">{{ name }}</p>
                <p v-if="subtitle" class="text-sm text-muted-foreground">
                    {{ subtitle }}
                </p>
            </div>
        </div>
    </Dialog>
</template>
