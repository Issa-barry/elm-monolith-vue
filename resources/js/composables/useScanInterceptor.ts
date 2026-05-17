import { onMounted, onUnmounted } from 'vue';

const AZERTY_TO_QWERTY: Record<string, string> = {
    q: 'a',
    a: 'q',
    z: 'w',
    w: 'z',
    '&': '1',
    é: '2',
    '"': '3',
    "'": '4',
    '(': '5',
    '-': '6',
    è: '7',
    _: '8',
    ç: '9',
    à: '0',
    M: ':',
    '!': '/',
    ':': '.',
    ')': '-',
};

function decode(s: string): string {
    return s
        .split('')
        .map((c) => AZERTY_TO_QWERTY[c] ?? c)
        .join('');
}

function resolveInternalUrl(raw: string): string | null {
    const origin = window.location.origin;
    const isInternal = (u: string) =>
        u.startsWith(origin + '/') || u === origin;
    if (isInternal(raw)) return raw;
    const decoded = decode(raw);
    if (isInternal(decoded)) return decoded;
    return null;
}

export function useScanInterceptor() {
    let buffer = '';
    let lastKeyTime = 0;
    let fastCount = 0;
    let resetTimer: ReturnType<typeof setTimeout> | null = null;

    function reset() {
        buffer = '';
        fastCount = 0;
        if (resetTimer) clearTimeout(resetTimer);
        resetTimer = null;
    }

    function scheduleReset() {
        if (resetTimer) clearTimeout(resetTimer);
        // Si aucune touche n'arrive dans 400ms, on abandonne le buffer
        resetTimer = setTimeout(reset, 400);
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
            // Valider seulement si au moins 10 frappes rapides consécutives
            // (évite les faux positifs sur de courtes frappes humaines)
            if (fastCount >= 10 && buffer.length >= 10) {
                const url = resolveInternalUrl(buffer.trim());
                if (url) {
                    e.preventDefault();
                    reset();
                    window.location.href = url;
                    return;
                }
            }
            reset();
            return;
        }

        // Ignorer les touches non-imprimables (Shift, Ctrl, F1…)
        if (e.key.length !== 1) return;

        const now = Date.now();
        const interval = now - lastKeyTime;
        lastKeyTime = now;

        // < 50ms entre deux touches = vitesse scanner
        if (buffer.length === 0 || interval < 50) {
            if (interval < 50) fastCount++;
            buffer += e.key;
        } else {
            // Frappe trop lente → pas un scanner, reset
            reset();
            buffer = e.key;
        }

        scheduleReset();
    }

    onMounted(() => document.addEventListener('keydown', onKeydown));
    onUnmounted(() => document.removeEventListener('keydown', onKeydown));
}
