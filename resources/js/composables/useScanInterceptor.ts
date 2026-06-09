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
    function tryParse(s: string): string | null {
        try {
            const u = new URL(s);
            if (u.protocol === 'http:' || u.protocol === 'https:') {
                // On garde le chemin et reconstruit sur l'origine courante :
                // robuste aux décalages APP_URL et aux corruptions AZERTY du host.
                return window.location.origin + u.pathname + u.search + u.hash;
            }
        } catch {
            // s n'est pas une URL valide
        }
        return null;
    }
    return tryParse(raw) ?? tryParse(decode(raw));
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

// Références de livraison : VT-xxxxx (commande vente) ou TR-xxxxx (transfert logistique)
const LIVRAISON_REF_RE = /^(VT|TR)-/i;

async function resolveLivraisonUrl(ref: string): Promise<string | null> {
    try {
        const res = await fetch(`/scan/livraison/${encodeURIComponent(ref)}`, {
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

                // Cas 2 : ULID nu (QR propriétaire/livreur de l'app mobile)
                // On essaie decoded (AZERTY→QWERTY), puis raw si decoded n'est pas un ULID valide
                // (ex: le 'M' du ULID est converti en ':' par decode, ce qui l'invalide)
                const decoded = decode(raw);
                const ulidCandidate = isUlid(decoded) ? decoded : isUlid(raw) ? raw : null;
                if (ulidCandidate) {
                    resolveUlidUrl(ulidCandidate).then((resolved) => {
                        if (resolved) window.location.href = resolved;
                    });
                    return;
                }

                // Cas 3 : Référence livraison brute VT-xxxxx / TR-xxxxx (QR anciens format)
                // On teste raw (avant décodage AZERTY) car le tiret '-' est converti en '6' par decode()
                if (LIVRAISON_REF_RE.test(raw)) {
                    resolveLivraisonUrl(raw).then((resolved) => {
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
