/**
 * PWA — service worker minimal (cf. docs/pwa.md).
 *
 * Ces tests ne tournent PAS dans la suite E2E par défaut : `npm run e2e:build`
 * désactive le service worker (PWA_FORCE_ENABLED absent, cf. vite.config.ts) pour
 * ne jamais faire dépendre le reste de la suite fonctionnelle d'un cache
 * navigateur. Pour les exécuter, construire l'app avec le service worker actif
 * puis lancer uniquement ce fichier :
 *
 *   npm run e2e:db:reset && npm run e2e:build:pwa && npx playwright test tests/e2e/pwa.spec.ts
 */
import { expect, test } from '@playwright/test';
import { login } from './helpers';

test.use({ serviceWorkers: 'allow' });

test.describe('PWA — manifest et icônes', () => {
    test('le manifest est servi, valide, et référence des icônes accessibles', async ({
        request,
    }) => {
        const res = await request.get('/manifest.json');
        expect(res.ok()).toBeTruthy();

        const manifest = await res.json();
        expect(manifest.name).toBe('ELM');
        expect(manifest.short_name).toBe('ELM');
        expect(manifest.display).toBe('standalone');
        expect(manifest.start_url).toBe('/');
        expect(manifest.scope).toBe('/');
        expect(Array.isArray(manifest.icons)).toBeTruthy();
        expect(manifest.icons.length).toBeGreaterThanOrEqual(3);

        // Le manifest ne doit jamais embarquer de donnée de session/utilisateur.
        const raw = JSON.stringify(manifest).toLowerCase();
        expect(raw).not.toContain('session');
        expect(raw).not.toContain('token');
        expect(raw).not.toContain('user');

        for (const icon of manifest.icons) {
            const iconRes = await request.get(icon.src);
            expect(iconRes.ok(), `icône introuvable : ${icon.src}`).toBeTruthy();
        }

        expect(manifest.icons.some((i: { purpose?: string }) => i.purpose === 'maskable')).toBeTruthy();
    });
});

test.describe('PWA — service worker', () => {
    test('s\'enregistre et ne précache que des fichiers statiques autorisés', async ({
        page,
    }) => {
        await login(page);
        await page.goto('/backoffice/dashboard');

        await page.waitForFunction(
            () =>
                navigator.serviceWorker
                    .getRegistration()
                    .then((reg) => Boolean(reg?.active)),
            null,
            { timeout: 20_000 },
        );

        // Laisse le temps à l'install (fetch + cache.put asynchrones) de finir.
        await page.waitForFunction(
            () =>
                caches
                    .keys()
                    .then((names) => names.some((n) => n.startsWith('elm-pwa-'))),
            null,
            { timeout: 20_000 },
        );

        const cachedPaths = await page.evaluate(async () => {
            const names = await caches.keys();
            const cacheName = names.find((n) => n.startsWith('elm-pwa-'));
            if (!cacheName) return [];
            const cache = await caches.open(cacheName);
            const requests = await cache.keys();
            return requests.map((r) => new URL(r.url).pathname);
        });

        expect(cachedPaths.length).toBeGreaterThan(0);

        for (const p of cachedPaths) {
            const isStaticAllowlisted =
                p === '/manifest.json' ||
                p.startsWith('/icons/') ||
                p.startsWith('/build/assets/') ||
                p.startsWith('/build-e2e/assets/');

            expect(isStaticAllowlisted, `chemin en cache inattendu : ${p}`).toBeTruthy();
            expect(p.startsWith('/backoffice'), `page métier en cache : ${p}`).toBeFalsy();
            expect(p.startsWith('/client'), `page métier en cache : ${p}`).toBeFalsy();
            expect(p.startsWith('/api/'), `réponse API en cache : ${p}`).toBeFalsy();
        }
    });

    test('une navigation ne peut jamais être resservie par le service worker', async ({
        page,
    }) => {
        await login(page);
        await page.goto('/backoffice/dashboard');
        await page.waitForFunction(
            () =>
                navigator.serviceWorker
                    .getRegistration()
                    .then((reg) => Boolean(reg?.active)),
            null,
            { timeout: 20_000 },
        );

        const response = await page.reload();
        expect(response).not.toBeNull();
        expect(response?.fromServiceWorker()).toBeFalsy();
    });

    test('une requête Inertia (X-Inertia) vers une page métier ne passe jamais par le cache', async ({
        page,
    }) => {
        await login(page);
        await page.goto('/backoffice/dashboard');
        await page.waitForFunction(
            () =>
                navigator.serviceWorker
                    .getRegistration()
                    .then((reg) => Boolean(reg?.active)),
            null,
            { timeout: 20_000 },
        );

        // Un vrai lien interne cliqué déclenche une navigation Inertia côté
        // client (fetch avec l'en-tête X-Inertia), contrairement à page.goto()
        // qui produirait une navigation de document complète (déjà couverte
        // par le test précédent).
        const link = page.locator('a[href^="/backoffice/"]').first();
        await link.waitFor({ state: 'visible', timeout: 15_000 });

        const [response] = await Promise.all([
            page.waitForResponse(
                (res) => res.request().headers()['x-inertia'] === 'true',
                { timeout: 15_000 },
            ),
            link.click(),
        ]);

        expect(response.fromServiceWorker()).toBeFalsy();
    });
});
