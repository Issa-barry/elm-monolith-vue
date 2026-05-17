<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';

const input = ref<HTMLInputElement | null>(null);
const status = ref<'idle' | 'redirect' | 'error'>('idle');
const lastScanned = ref('');

// Décode un input QWERTY interprété par un clavier AZERTY
// (le scanner envoie des scan codes QWERTY, Windows les lit en AZERTY)
const AZERTY_TO_QWERTY: Record<string, string> = {
    // Lettres
    'q': 'a', 'a': 'q',
    'z': 'w', 'w': 'z',
    // Chiffres (AZERTY sans Shift → chiffre QWERTY)
    '&': '1', 'é': '2', '"': '3', "'": '4', '(': '5',
    '-': '6', 'è': '7', '_': '8', 'ç': '9', 'à': '0',
    // Symboles présents dans les URLs
    'M': ':', '!': '/', ':': '.', ')': '-',
};

function decodeAzerty(s: string): string {
    return s.split('').map(c => AZERTY_TO_QWERTY[c] ?? c).join('');
}

function focusInput() {
    input.value?.focus();
}

function resolveUrl(raw: string): string | null {
    const origin = window.location.origin;
    const isInternal = (url: string) =>
        url.startsWith(origin + '/') || url === origin;

    if (isInternal(raw)) return raw;

    const decoded = decodeAzerty(raw);
    if (isInternal(decoded)) return decoded;

    return null;
}

function handleEnter() {
    const raw = input.value?.value.trim() ?? '';
    input.value!.value = '';

    if (!raw) return;

    const url = resolveUrl(raw);

    if (!url) {
        lastScanned.value = raw;
        status.value = 'error';
        setTimeout(() => {
            status.value = 'idle';
            focusInput();
        }, 2000);
        return;
    }

    lastScanned.value = url;
    status.value = 'redirect';
    window.location.href = url;
}

function handleKeydown(e: KeyboardEvent) {
    if (e.key === 'Enter') {
        handleEnter();
    }
}

// Refocus automatique si l'agent clique ailleurs par erreur
function handleWindowClick() {
    focusInput();
}

onMounted(() => {
    focusInput();
    window.addEventListener('click', handleWindowClick);
});

onUnmounted(() => {
    window.removeEventListener('click', handleWindowClick);
});
</script>

<template>
    <AppLayout>
        <Head title="Scan QR" />

        <div class="flex min-h-[70vh] flex-col items-center justify-center gap-8">

            <div class="text-center">
                <h1 class="text-2xl font-semibold text-foreground">Station de scan</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Pointez le scanner sur le QR code du partenaire.
                </p>
            </div>

            <!-- Zone de scan visuelle -->
            <div
                class="relative flex h-48 w-48 items-center justify-center rounded-2xl border-2 border-dashed transition-colors"
                :class="{
                    'border-primary': status === 'idle',
                    'border-green-500 bg-green-50': status === 'redirect',
                    'border-destructive bg-destructive/5': status === 'error',
                }"
            >
                <!-- Coins décoratifs -->
                <span class="absolute top-2 left-2 h-5 w-5 rounded-tl-lg border-t-2 border-l-2 border-current" />
                <span class="absolute top-2 right-2 h-5 w-5 rounded-tr-lg border-t-2 border-r-2 border-current" />
                <span class="absolute bottom-2 left-2 h-5 w-5 rounded-bl-lg border-b-2 border-l-2 border-current" />
                <span class="absolute bottom-2 right-2 h-5 w-5 rounded-br-lg border-b-2 border-r-2 border-current" />

                <div class="flex flex-col items-center gap-2 text-muted-foreground">
                    <svg v-if="status === 'idle'" xmlns="http://www.w3.org/2000/svg" class="h-10 w-10 opacity-40" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4v1m0 14v1M4 12H3m18 0h-1M6.34 6.34l-.71-.71m12.73 12.73-.71-.71M6.34 17.66l-.71.71M17.66 6.34l.71-.71" />
                        <rect x="7" y="7" width="4" height="4" rx="0.5" stroke-width="1.5" />
                        <rect x="13" y="7" width="4" height="4" rx="0.5" stroke-width="1.5" />
                        <rect x="7" y="13" width="4" height="4" rx="0.5" stroke-width="1.5" />
                    </svg>
                    <svg v-else-if="status === 'redirect'" class="h-10 w-10 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                    <svg v-else class="h-10 w-10 text-destructive" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>

                    <span class="text-xs font-medium">
                        <template v-if="status === 'idle'">En attente…</template>
                        <template v-else-if="status === 'redirect'">Redirection…</template>
                        <template v-else>QR non reconnu</template>
                    </span>
                </div>
            </div>

            <!-- Input caché qui reçoit le scanner -->
            <input
                ref="input"
                type="text"
                class="fixed -left-[9999px] top-0 opacity-0"
                autocomplete="off"
                @keydown="handleKeydown"
            />

            <p class="text-xs text-muted-foreground">
                Le curseur reste actif en permanence — aucune action requise avant le scan.
            </p>

            <p v-if="status === 'error'" class="max-w-xs break-all text-center text-xs text-destructive">
                QR invalide ou hors application : <span class="font-mono">{{ lastScanned }}</span>
            </p>
        </div>
    </AppLayout>
</template>
