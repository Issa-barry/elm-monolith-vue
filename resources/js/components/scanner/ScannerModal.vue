<script setup lang="ts">
// Scanner caméra du dashboard mobile — QR code (URL interne, ULID propriétaire/livreur,
// référence livraison) et codes-barres produit (EAN-8/13, UPC-A/E, Code128), via ZXing.
// Réutilise les mêmes résolveurs que le scanner USB clavier (useScanInterceptor), cf.
// resources/js/composables/scan/scanResolvers.ts — même sécurité, même comportement.
import { resolveBarcodeText, resolveQrText } from '@/composables/scan/scanResolvers';
import { router } from '@inertiajs/vue3';
import type { IScannerControls } from '@zxing/browser';
import Dialog from 'primevue/dialog';
import { useToast } from 'primevue/usetoast';
import { onBeforeUnmount, ref, watch } from 'vue';

const visible = defineModel<boolean>('visible', { default: false });

const toast = useToast();
const videoRef = ref<HTMLVideoElement | null>(null);
const status = ref<'idle' | 'requesting' | 'scanning' | 'error'>('idle');
const errorMessage = ref('');

let stream: MediaStream | null = null;
let controls: IScannerControls | null = null;
let processing = false;
let cameraActivation = 0;

function stopCamera() {
    cameraActivation += 1;
    controls?.stop();
    controls = null;
    stream?.getTracks().forEach((track) => track.stop());
    stream = null;
    processing = false;
}

async function startCamera() {
    const activation = ++cameraActivation;

    if (!window.isSecureContext) {
        status.value = 'error';
        errorMessage.value = 'Le scan caméra nécessite une connexion sécurisée (HTTPS).';
        return;
    }
    if (!navigator.mediaDevices?.getUserMedia) {
        status.value = 'error';
        errorMessage.value = 'Caméra indisponible sur cet appareil ou ce navigateur.';
        return;
    }

    status.value = 'requesting';
    errorMessage.value = '';

    try {
        // ZXing est chargé uniquement après l'ouverture du scanner afin de ne
        // pas alourdir le chargement initial du dashboard mobile.
        const [{ BrowserMultiFormatReader }, { BarcodeFormat, DecodeHintType }] =
            await Promise.all([
                import('@zxing/browser'),
                import('@zxing/library'),
            ]);

        if (!visible.value || activation !== cameraActivation) return;

        const mediaStream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: { ideal: 'environment' } },
            audio: false,
        });

        if (!visible.value || activation !== cameraActivation) {
            mediaStream.getTracks().forEach((track) => track.stop());
            return;
        }

        stream = mediaStream;

        if (!videoRef.value) {
            stopCamera();
            return;
        }

        const barcodeFormats = new Set([
            BarcodeFormat.EAN_13,
            BarcodeFormat.EAN_8,
            BarcodeFormat.UPC_A,
            BarcodeFormat.UPC_E,
            BarcodeFormat.CODE_128,
        ]);
        const hints = new Map();
        hints.set(DecodeHintType.POSSIBLE_FORMATS, [
            BarcodeFormat.QR_CODE,
            ...barcodeFormats,
        ]);
        const reader = new BrowserMultiFormatReader(hints);

        controls = await reader.decodeFromStream(stream, videoRef.value, (result) => {
            if (processing || !result) return;
            processing = true;
            void handleResult(
                result.getText(),
                barcodeFormats.has(result.getBarcodeFormat()),
            );
        });
        status.value = 'scanning';
    } catch (e) {
        stopCamera();
        status.value = 'error';
        errorMessage.value =
            (e as DOMException).name === 'NotAllowedError'
                ? "Autorisation caméra refusée — autorisez l'accès dans les réglages du navigateur."
                : "Impossible d'accéder à la caméra ou de démarrer la lecture.";
    }
}

async function handleResult(text: string, isBarcode: boolean) {
    stopCamera();

    const result = isBarcode
        ? await resolveBarcodeText(text)
        : await resolveQrText(text);

    if (result.status === 'resolved') {
        visible.value = false;
        router.visit(result.url);
        return;
    }

    toast.add({
        severity: 'warn',
        summary:
            result.status === 'not_found'
                ? 'Code reconnu mais introuvable'
                : 'Code non reconnu',
        life: 4000,
    });

    if (visible.value) {
        void startCamera();
    }
}

watch(visible, (isVisible) => {
    if (isVisible) {
        void startCamera();
    } else {
        stopCamera();
        status.value = 'idle';
    }
});

onBeforeUnmount(stopCamera);
</script>

<template>
    <Dialog
        v-model:visible="visible"
        modal
        header="Scanner"
        :style="{ width: 'min(94vw, 28rem)' }"
        :draggable="false"
    >
        <div class="flex flex-col gap-3">
            <div
                v-if="status === 'error'"
                class="rounded-md bg-destructive/10 p-3 text-sm text-destructive"
            >
                {{ errorMessage }}
            </div>

            <div class="relative aspect-square w-full overflow-hidden rounded-lg bg-black">
                <video
                    ref="videoRef"
                    class="h-full w-full object-cover"
                    muted
                    playsinline
                    autoplay
                ></video>
                <div
                    v-if="status === 'requesting'"
                    class="absolute inset-0 flex items-center justify-center bg-black/60 text-sm text-white"
                >
                    Démarrage de la caméra…
                </div>
            </div>

            <p class="text-center text-xs text-muted-foreground">
                Cadrez un QR code ou un code-barres produit (EAN, UPC, Code128).
            </p>
        </div>
    </Dialog>
</template>
