// Service worker minimal ELM PWA — V1.
//
// Rôle UNIQUE : rendre l'application installable. Ne met JAMAIS en cache une
// navigation, une réponse Inertia (X-Inertia), un appel /api/*, ni aucune
// donnée métier (commandes, stocks, ventes, paiements, livraisons). Aucune
// stratégie NetworkFirst / StaleWhileRevalidate sur une route applicative :
// tout ce qui n'est pas explicitement autorisé ci-dessous continue vers le
// réseau exactement comme si ce fichier n'existait pas.
//
// Procédure de retrait / désactivation : voir docs/pwa.md.

const CACHE_VERSION = 'v1';
const CACHE_NAME = `elm-pwa-${CACHE_VERSION}`;

// Liste blanche fixe : icônes + manifest (ne changent jamais de nom, pas de hash).
const STATIC_ALLOWLIST = [
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    '/icons/icon-maskable-512.png',
    '/manifest.json',
];

// Le build normal écrit dans public/build/, le build E2E dans public/build-e2e/
// (cf. vite.config.ts / AppServiceProvider::boot()) — ce fichier est unique et
// statique, donc l'environnement lui est transmis via la query string au moment
// de l'enregistrement (resources/js/app.ts), jamais deviné depuis self.location.
function getBuildDir() {
    const params = new URL(self.location.href).searchParams;
    return params.get('build') === 'build-e2e' ? 'build-e2e' : 'build';
}

// Dérive la liste des assets Vite à précacher à partir du manifest RÉEL du
// build courant (public/{build}/manifest.json, généré par laravel-vite-plugin)
// — jamais de noms de fichiers hashés maintenus à la main. Se limite au chunk
// d'entrée (resources/js/app.ts) et à son CSS : les chunks de pages (chargés à
// la demande par Inertia) ne sont pas précachés, ils restent de simples
// requêtes réseau normales comme aujourd'hui.
async function collectViteAssets() {
    const buildDir = getBuildDir();
    try {
        const res = await fetch(`/${buildDir}/manifest.json`, { cache: 'no-store' });
        if (!res.ok) return [];
        const manifest = await res.json();
        const entry = manifest['resources/js/app.ts'];
        if (!entry) return [];
        const urls = [];
        if (entry.file) urls.push(`/${buildDir}/${entry.file}`);
        if (Array.isArray(entry.css)) {
            for (const cssFile of entry.css) urls.push(`/${buildDir}/${cssFile}`);
        }
        return urls;
    } catch {
        return [];
    }
}

// N'accepte en cache qu'une réponse statique valide : 200 direct, même
// origine (type 'basic'), jamais une redirection, jamais du HTML inattendu
// (page d'erreur 404/500 servie avec un statut 200 par erreur de config).
function isCacheableStaticResponse(response) {
    if (!response || !response.ok) return false;
    if (response.type !== 'basic') return false;
    if (response.redirected) return false;
    const contentType = response.headers.get('content-type') || '';
    if (contentType.includes('text/html')) return false;
    return true;
}

self.addEventListener('install', (event) => {
    event.waitUntil(
        (async () => {
            const viteAssets = await collectViteAssets();
            const urls = [...STATIC_ALLOWLIST, ...viteAssets];
            const cache = await caches.open(CACHE_NAME);

            // Séquentiel, pas Promise.all : quelques centaines de ms de plus à
            // l'install, mais évite de saturer un backend à faible concurrence
            // (ex. `php artisan serve`, mono-thread, utilisé en local/E2E) avec
            // une rafale de requêtes simultanées au premier chargement.
            for (const url of urls) {
                try {
                    const response = await fetch(url, { cache: 'no-store' });
                    if (isCacheableStaticResponse(response)) {
                        await cache.put(url, response);
                    }
                } catch {
                    // Un asset manquant/indisponible ne doit jamais empêcher
                    // l'installation du service worker.
                }
            }
        })(),
    );
    // Pas de self.skipWaiting() : le nouveau service worker reste "waiting"
    // tant qu'un onglet utilise l'ancien, comportement standard non intrusif
    // (cf. docs/pwa.md, section mise à jour).
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            const names = await caches.keys();
            await Promise.all(
                names
                    .filter((name) => name.startsWith('elm-pwa-') && name !== CACHE_NAME)
                    .map((name) => caches.delete(name)),
            );
        })(),
    );
    // Pas de clients.claim() : les onglets déjà ouverts restent contrôlés par
    // l'ancien service worker jusqu'à leur prochaine navigation complète.
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    // Jamais sur une écriture (POST/PUT/PATCH/DELETE) : aucune opération
    // métier ne doit pouvoir être interceptée ou rejouée par ce fichier.
    if (request.method !== 'GET') return;

    // Jamais sur une navigation (chargement de page, retour arrière...) :
    // aucune page HTML, aucune redirection d'authentification n'est concernée.
    if (request.mode === 'navigate') return;

    const url = new URL(request.url);

    // Jamais cross-origin.
    if (url.origin !== self.location.origin) return;

    // Liste blanche explicite par chemin : seuls les assets Vite hashés du
    // build courant et les icônes/manifest fixes sont éligibles à une réponse
    // depuis le cache. Toute requête Inertia (X-Inertia), API (/api/*) ou
    // applicative ne matche jamais ce filtre et continue vers le réseau
    // exactement comme en l'absence de service worker.
    const buildDir = getBuildDir();
    const isEligible =
        STATIC_ALLOWLIST.includes(url.pathname) ||
        url.pathname.startsWith(`/${buildDir}/assets/`);

    if (!isEligible) return;

    event.respondWith(
        (async () => {
            const cached = await caches.match(request, { cacheName: CACHE_NAME });
            if (cached) return cached;
            // Pas dans le cache (ex: chunk de page non précaché) : requête
            // réseau normale, sans écriture en cache à la volée (pas de
            // stratégie StaleWhileRevalidate/NetworkFirst).
            return fetch(request);
        })(),
    );
});
