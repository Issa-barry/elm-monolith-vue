// Config dédiée à tests/e2e-pwa/pwa.spec.ts — volontairement un fichier séparé
// de playwright.config.ts (testDir différent), pas une exclusion type
// `testIgnore` : un `testIgnore` bloque aussi bien la collecte par défaut
// qu'un chemin de fichier passé explicitement en argument (vérifié
// directement), donc `npx playwright test tests/e2e-pwa/pwa.spec.ts`
// n'aurait jamais fonctionné avec ce mécanisme. Avec un testDir séparé au
// contraire : `npx playwright test` (E2E full/smoke, aucun argument) ne le
// voit jamais, et cette config dédiée reste invocable explicitement via
// `--config`. Voir tests/e2e-pwa/pwa.spec.ts pour pourquoi ce fichier est
// isolé du reste de la suite (service worker désactivé par défaut sur le
// build E2E standard, et contention possible avec les autres workers
// Playwright sur le même `php artisan serve` mono-thread).
import { defineConfig } from '@playwright/test';
import baseConfig from './playwright.config';

export default defineConfig({
    ...baseConfig,
    testDir: './tests/e2e-pwa',
});
