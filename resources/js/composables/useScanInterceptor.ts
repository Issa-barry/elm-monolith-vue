import { onMounted, onUnmounted } from 'vue';
import { resolveQrText } from './scan/scanResolvers';

export function useScanInterceptor() {
    let buffer = '';
    let bufferStartMs = 0; // horodatage du 1er caractère
    let resetTimer: ReturnType<typeof setTimeout> | null = null;

    function reset() {
        buffer = '';
        bufferStartMs = 0;
        if (resetTimer) clearTimeout(resetTimer);
        resetTimer = null;
    }

    function scheduleReset() {
        if (resetTimer) clearTimeout(resetTimer);
        // Abandon si aucune touche pendant 500ms
        resetTimer = setTimeout(reset, 500);
    }

    function onKeydown(e: KeyboardEvent) {
        const target = e.target as HTMLElement;

        // Ne pas intercepter si un champ de saisie est actif
        if (
            target.tagName === 'INPUT' ||
            target.tagName === 'TEXTAREA' ||
            target.tagName === 'SELECT' ||
            target.isContentEditable
        )
            return;

        if (e.key === 'Enter') {
            const raw = buffer.trim();
            const elapsed = Date.now() - bufferStartMs;

            // Scanner USB : ≥ 8 caractères arrivés en < 500 ms
            // Frappe humaine : trop longue ou trop courte
            if (raw.length >= 8 && elapsed < 500) {
                e.preventDefault();
                reset();

                resolveQrText(raw).then((result) => {
                    if (result.status === 'resolved') {
                        window.location.href = result.url;
                    }
                });
                return;
            }

            reset();
            return;
        }

        // Ignorer les touches non-imprimables (Shift, Ctrl, F1…)
        if (e.key.length !== 1) return;

        if (buffer.length === 0) {
            bufferStartMs = Date.now();
        }

        buffer += e.key;
        scheduleReset();
    }

    onMounted(() => document.addEventListener('keydown', onKeydown));
    onUnmounted(() => document.removeEventListener('keydown', onKeydown));
}
