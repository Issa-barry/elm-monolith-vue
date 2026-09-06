/**
 * PWA — service worker minimal (cf. docs/pwa.md).
 *
 * Dans tests/e2e-pwa/ (pas tests/e2e/) avec sa propre config
 * (playwright.pwa.config.ts) : un `npx playwright test` sans argument (E2E
 * full/smoke en CI, testDir = tests/e2e) ne le ramasse donc jamais — un
 * `testIgnore` sur playwright.config.ts avait été envisagé puis écarté,
 * vérifié qu'il bloque aussi bien un chemin de fichier passé explicitement en
 * argument, ce qui aurait rendu ce fichier impossible à lancer du tout. Deux
 * raisons cumulées justifient cette exclusion de la suite par défaut :
 * - `npm run e2e:build` désactive le service worker par défaut
 *   (PWA_FORCE_ENABLED absent, cf. vite.config.ts) — ces tests attendraient
 *   `reg.active` indéfiniment (timeout, jamais un skip propre).
 * - Même service worker actif, ses fetch d'installation partagent le même
 *   `php artisan serve` mono-thread que tous les autres workers Playwright
 *   du job — un run E2E complet en parallèle de ce fichier serait une source
 *   de flakiness pour l'un comme pour l'autre.
 *
 * Pour les exécuter, construire l'app avec le service worker actif puis
 * lancer cette config dédiée (E2E_SKIP_GLOBAL_SETUP=1 : ces tests se
 * connectent eux-mêmes, inutile de rejouer le seed de commissions coûteux de
 * global-setup.ts) :
 *
 *   npm run e2e:db:reset && npm run e2e:build:pwa && E2E_SKIP_GLOBAL_SETUP=1 npx playwright test --config=playwright.pwa.config.ts
 */
import { expect, test } from '@playwright/test';
import { login } from '../e2e/helpers';

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
            expect(
                iconRes.ok(),
                `icône introuvable : ${icon.src}`,
            ).toBeTruthy();
        }

        expect(
            manifest.icons.some(
                (i: { purpose?: string }) => i.purpose === 'maskable',
            ),
        ).toBeTruthy();
    });
});

test.describe('PWA — service worker', () => {
    test("s'enregistre et ne précache que des fichiers statiques autorisés", async ({
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

        // `reg.active` confirme que la promesse de `install` a résolu, mais
        // pas que le cache contient déjà ses entrées au moment précis où ce
        // test les lit (observé en CI, sous charge partagée avec un autre
        // worker Playwright sur le même `php artisan serve` mono-thread :
        // le nom du cache existe dès `caches.open()`, avant toute écriture —
        // attendre seulement son existence peut donc lire un cache encore
        // vide). On attend directement la condition qui nous intéresse
        // réellement : au moins une entrée présente dans ce cache précis.
        await page.waitForFunction(
            async () => {
                const names = await caches.keys();
                const cacheName = names.find((n) => n.startsWith('elm-pwa-'));
                if (!cacheName) return false;
                const cache = await caches.open(cacheName);
                const keys = await cache.keys();
                return keys.length > 0;
            },
            null,
            { timeout: 30_000 },
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

            expect(
                isStaticAllowlisted,
                `chemin en cache inattendu : ${p}`,
            ).toBeTruthy();
            expect(
                p.startsWith('/backoffice'),
                `page métier en cache : ${p}`,
            ).toBeFalsy();
            expect(
                p.startsWith('/client'),
                `page métier en cache : ${p}`,
            ).toBeFalsy();
            expect(
                p.startsWith('/api/'),
                `réponse API en cache : ${p}`,
            ).toBeFalsy();
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
