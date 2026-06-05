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
    ',': 'm',
};

function decode(s: string): string {
    return s
        .split('')
        .map((c) => AZERTY_TO_QWERTY[c] ?? c)
        .join('');
}

// ULID Crockford base32 : 26 caractères parmi 0-9 a-z (sans i l o u)
const ULID_RE = /^[0-9a-hjkmnp-tv-z]{26}$/i;

function isUlid(s: string): boolean {
    return ULID_RE.test(s);
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

async function resolveUlidUrl(ulid: string): Promise<string | null> {
    try {
        const res = await fetch(`/scan/user/${ulid}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!res.ok) return null;
        const json = (await res.json()) as { url?: string };
        return json.url ?? null;
    } catch {
        return null;
    }
}

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

                // Cas 1 : URL complète (QR du dashboard client)
                const url = resolveInternalUrl(raw);
                if (url) {
                    window.location.href = url;
                    return;
                }

                // Cas 2 : ULID nu (QR de l'app mobile)
                const decoded = decode(raw);
                if (isUlid(decoded)) {
                    resolveUlidUrl(decoded).then((resolved) => {
                        if (resolved) window.location.href = resolved;
                    });
                    return;
                }
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
