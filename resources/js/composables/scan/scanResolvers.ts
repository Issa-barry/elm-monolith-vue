// Résolveurs partagés entre le scanner USB clavier (useScanInterceptor, tout le
// backoffice) et le scanner caméra (ScannerModal, dashboard mobile) — mêmes règles de
// reconnaissance et de sécurité pour les deux entrées, un seul endroit à faire évoluer.

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

export function decode(s: string): string {
    return s
        .split('')
        .map((c) => AZERTY_TO_QWERTY[c] ?? c)
        .join('');
}

// ULID Crockford base32 : 26 caractères parmi 0-9 a-z (sans i l o u)
const ULID_RE = /^[0-9a-hjkmnp-tv-z]{26}$/i;

export function isUlid(s: string): boolean {
    return ULID_RE.test(s);
}

// Reconstruit une URL scannée sur l'origine courante : robuste aux décalages APP_URL
// (ex. migration de domaine) et corruptions AZERTY du host — n'ouvre jamais l'hôte tel
// que scanné, seulement son chemin, sur l'origine réellement affichée par le navigateur.
export function resolveInternalUrl(raw: string): string | null {
    function tryParse(s: string): string | null {
        try {
            const u = new URL(s);
            if (u.protocol === 'http:' || u.protocol === 'https:') {
                return window.location.origin + u.pathname + u.search + u.hash;
            }
        } catch {
            // s n'est pas une URL valide
        }
        return null;
    }
    return tryParse(raw) ?? tryParse(decode(raw));
}

function toCurrentOrigin(url: string): string {
    try {
        const u = new URL(url);
        return window.location.origin + u.pathname + u.search + u.hash;
    } catch {
        return url;
    }
}

async function fetchScanUrl(path: string): Promise<string | null> {
    try {
        const res = await fetch(path, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!res.ok) return null;
        const json = (await res.json()) as { url?: string | null };
        return json.url ? toCurrentOrigin(json.url) : null;
    } catch {
        return null;
    }
}

export async function resolveUlidUrl(ulid: string): Promise<string | null> {
    return fetchScanUrl(`/scan/user/${ulid}`);
}

// Références de livraison : VT-xxxxx (commande vente) ou TR-xxxxx (transfert logistique)
export const LIVRAISON_REF_RE = /^(VT|TR)-/i;

export async function resolveLivraisonUrl(ref: string): Promise<string | null> {
    return fetchScanUrl(`/scan/livraison/${encodeURIComponent(ref)}`);
}

export async function resolveProduitUrl(code: string): Promise<string | null> {
    return fetchScanUrl(`/scan/produit/${encodeURIComponent(code)}`);
}

export type ScanResolution =
    | { status: 'resolved'; url: string }
    | { status: 'not_found' }
    | { status: 'unrecognized' };

/**
 * Résolution d'un texte de QR code scanné (caméra ou USB) — 3 cas, dans l'ordre déjà
 * utilisé par useScanInterceptor : URL interne complète, ULID nu (QR propriétaire/
 * livreur de l'app mobile), référence livraison brute.
 */
export async function resolveQrText(raw: string): Promise<ScanResolution> {
    const url = resolveInternalUrl(raw);
    if (url) return { status: 'resolved', url };

    // On essaie decoded (AZERTY→QWERTY), puis raw si decoded n'est pas un ULID valide
    // (ex: le 'M' du ULID est converti en ':' par decode, ce qui l'invalide)
    const decoded = decode(raw);
    const ulidCandidate = isUlid(decoded) ? decoded : isUlid(raw) ? raw : null;
    if (ulidCandidate) {
        const resolved = await resolveUlidUrl(ulidCandidate);
        return resolved ? { status: 'resolved', url: resolved } : { status: 'not_found' };
    }

    // On teste raw (avant décodage AZERTY) car le tiret '-' est converti en '6' par decode()
    if (LIVRAISON_REF_RE.test(raw)) {
        const resolved = await resolveLivraisonUrl(raw);
        return resolved ? { status: 'resolved', url: resolved } : { status: 'not_found' };
    }

    return { status: 'unrecognized' };
}

/**
 * Résolution d'un code-barres produit (EAN/UPC/Code128) — la symbologie est déjà
 * connue côté appelant (ZXing la fournit), donc pas de détection de motif ici.
 */
export async function resolveBarcodeText(code: string): Promise<ScanResolution> {
    const resolved = await resolveProduitUrl(code);
    return resolved ? { status: 'resolved', url: resolved } : { status: 'not_found' };
}
