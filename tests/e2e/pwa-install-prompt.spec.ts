/**
 * Proposition d'installation mobile — cf. docs/pwa.md § Proposition
 * d'installation mobile. Sur téléphone non installé, l'interface ELM reste
 * utilisable dans le navigateur ; une carte non bloquante propose seulement
 * d'installer la PWA. Tablette et desktop : jamais de carte.
 *
 * `beforeinstallprompt` réel non automatisable ici (nécessite les critères
 * d'installabilité complets — HTTPS/manifest/service worker — cf. docs/pwa.md) :
 * l'événement est simulé via `dispatchEvent` pour tester le chemin Android
 * (native_prompt) de façon déterministe.
 *
 * Repère de contenu back-office : le bouton "Toggle Sidebar" (AppSidebarHeader),
 * identique quel que soit le viewport.
 */
import { devices, expect, test, type Page } from '@playwright/test';

const IPHONE_UA =
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
const ANDROID_PHONE_UA =
    'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36';
const UNSUPPORTED_PHONE_UA =
    'Mozilla/5.0 (Android 14; Mobile; rv:120.0) Gecko/120.0 Firefox/120.0';

const installCard = (page: Page) => page.getByTestId('pwa-install-prompt');

async function expectDashboardUsable(page: Page): Promise<void> {
    await expect(
        page.getByRole('button', { name: 'Toggle Sidebar' }),
    ).toBeVisible();
}

async function dispatchBeforeInstallPrompt(page: Page): Promise<void> {
    await page.evaluate(() => {
        const evt = new Event('beforeinstallprompt', {
            cancelable: true,
        }) as Event & {
            prompt?: () => Promise<void>;
            userChoice?: Promise<{ outcome: string; platform: string }>;
        };
        evt.prompt = () => {
            (window as unknown as { __promptCalled: boolean }).__promptCalled =
                true;
            return Promise.resolve();
        };
        evt.userChoice = Promise.resolve({
            outcome: 'accepted',
            platform: 'android',
        });
        window.dispatchEvent(evt);
    });
}

test.describe("Proposition d'installation — iPhone", () => {
    test.use({
        viewport: { width: 390, height: 844 },
        hasTouch: true,
        isMobile: true,
        userAgent: IPHONE_UA,
    });

    test('non installé : interface utilisable + carte, le bouton ouvre les instructions Safari', async ({
        page,
    }) => {
        await page.goto('/backoffice/dashboard');

        await expectDashboardUsable(page);
        await expect(installCard(page)).toBeVisible();

        await installCard(page)
            .getByRole('button', { name: 'Installer' })
            .click();
        await expect(
            page.getByText('Appuyez sur le bouton Partager de Safari.', {
                exact: false,
            }),
        ).toBeVisible();
    });

    test('« Plus tard » masque la carte pour la session', async ({ page }) => {
        await page.goto('/backoffice/dashboard');
        await installCard(page)
            .getByRole('button', { name: 'Plus tard' })
            .click();
        await expect(installCard(page)).toHaveCount(0);

        await page.goto('/backoffice/dashboard');
        await expectDashboardUsable(page);
        await expect(installCard(page)).toHaveCount(0);
    });

    test('déjà en mode standalone : pas de carte', async ({ page }) => {
        await page.addInitScript(() => {
            Object.defineProperty(window.navigator, 'standalone', {
                value: true,
                configurable: true,
            });
        });
        await page.goto('/backoffice/dashboard');

        await expectDashboardUsable(page);
        await expect(installCard(page)).toHaveCount(0);
    });
});

test.describe("Proposition d'installation — Android", () => {
    test.use({
        viewport: { width: 412, height: 915 },
        hasTouch: true,
        isMobile: true,
        userAgent: ANDROID_PHONE_UA,
    });

    test('invite native captée : le bouton « Installer » déclenche prompt()', async ({
        page,
    }) => {
        await page.goto('/backoffice/dashboard');
        await expectDashboardUsable(page);
        await expect(installCard(page)).toHaveCount(0);

        await dispatchBeforeInstallPrompt(page);

        await expect(installCard(page)).toBeVisible();
        await installCard(page)
            .getByRole('button', { name: 'Installer' })
            .click();

        await expect
            .poll(() =>
                page.evaluate(
                    () =>
                        (window as unknown as { __promptCalled?: boolean })
                            .__promptCalled,
                ),
            )
            .toBe(true);
    });

    test('appinstalled déclenché : la carte disparaît', async ({ page }) => {
        await page.goto('/backoffice/dashboard');
        await dispatchBeforeInstallPrompt(page);
        await expect(installCard(page)).toBeVisible();

        await page.evaluate(() => {
            window.dispatchEvent(new Event('appinstalled'));
        });

        await expect(installCard(page)).toHaveCount(0);
        await expectDashboardUsable(page);
    });
});

test.describe("Proposition d'installation — navigateur sans chemin d'installation", () => {
    test.use({
        viewport: { width: 393, height: 851 },
        hasTouch: true,
        isMobile: true,
        userAgent: UNSUPPORTED_PHONE_UA,
    });

    test('interface utilisable, aucune carte (pas de bouton qui échouerait)', async ({
        page,
    }) => {
        await page.goto('/backoffice/dashboard');

        await expectDashboardUsable(page);
        await expect(installCard(page)).toHaveCount(0);
    });
});

test.describe("Proposition d'installation — tablette et desktop (non concernés)", () => {
    test('iPad : jamais de carte', async ({ browser }) => {
        const context = await browser.newContext({
            ...devices['iPad (gen 7)'],
            storageState: '.auth/user.json',
        });
        const page = await context.newPage();
        await page.goto('/backoffice/dashboard');

        await expectDashboardUsable(page);
        await expect(installCard(page)).toHaveCount(0);
        await context.close();
    });

    test('desktop : jamais de carte', async ({ page }) => {
        await page.goto('/backoffice/dashboard');

        await expectDashboardUsable(page);
        await expect(installCard(page)).toHaveCount(0);
    });
});
