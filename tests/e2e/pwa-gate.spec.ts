/**
 * Garde-fou d'accès mobile — cf. docs/pwa.md § Garde-fou mobile. Sur
 * téléphone, l'interface ELM normale (connexion, back-office, espace client)
 * ne doit jamais être atteignable tant que la PWA n'est pas installée
 * (`display: standalone`). Tablette et desktop : comportement web normal
 * inchangé.
 *
 * `beforeinstallprompt` réel non automatisable ici (nécessite les critères
 * d'installabilité complets — HTTPS/manifest/service worker — cf. docs/pwa.md) :
 * l'événement est simulé via `dispatchEvent` pour tester le chemin Android
 * (native_prompt) de façon déterministe.
 *
 * Repère de contenu back-office : le bouton "Toggle Sidebar" (AppSidebarHeader),
 * identique quel que soit le viewport — contrairement aux items de nav (la
 * sidebar rend des `<button>` de groupe sur desktop/tablette mais des `<a>` à
 * plat en dessous d'un certain viewport) ou aux cartes KPI du dashboard
 * (`StatsBankingWidget` clone ses slides pour un carrousel : plusieurs copies
 * simultanées dans le DOM, dont une hors champ — un repère peu fiable ici).
 *
 * `toBeVisible()`/`toBeHidden()` ne teste que la visibilité CSS propre d'un
 * élément, jamais s'il est recouvert par un autre — un `fixed inset-0`
 * par-dessus ne le fait donc jamais échouer. Prouver que le garde-fou
 * bloque réellement l'accès nécessite un vrai test d'occlusion via
 * `elementFromPoint` (voir expectDashboardBlockedByGate ci-dessous).
 */
import { devices, expect, test, type Page } from '@playwright/test';

const IPHONE_UA =
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
const ANDROID_PHONE_UA =
    'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36';
const UNSUPPORTED_PHONE_UA =
    'Mozilla/5.0 (Android 14; Mobile; rv:120.0) Gecko/120.0 Firefox/120.0';

const dashboardLandmark = (page: Page) =>
    page.getByRole('button', { name: 'Toggle Sidebar' });

async function expectDashboardVisible(page: Page): Promise<void> {
    await expect(
        page.getByRole('heading', { name: 'Installer ELM' }),
    ).toHaveCount(0);
    await expect(dashboardLandmark(page)).toBeVisible();
}

// Le contenu back-office est bien monté dans le DOM derrière le garde-fou
// (Vue ne conditionne pas son montage), mais entièrement recouvert et
// inatteignable : le point central de son repère doit résoudre sur l'overlay
// du garde-fou (`data-testid="pwa-gate"`), jamais sur le repère lui-même.
async function expectDashboardBlockedByGate(page: Page): Promise<void> {
    await expect(
        page.getByRole('heading', { name: 'Installer ELM' }),
    ).toBeVisible();

    const landmark = dashboardLandmark(page);
    await expect(landmark).toHaveCount(1);
    const box = await landmark.boundingBox();
    expect(box).not.toBeNull();

    const topmostIsGate = await page.evaluate(
        ({ x, y }) => {
            const el = document.elementFromPoint(x, y);
            return el?.closest('[data-testid="pwa-gate"]') != null;
        },
        { x: box!.x + box!.width / 2, y: box!.y + box!.height / 2 },
    );
    expect(topmostIsGate).toBe(true);
}

test.describe('Garde-fou PWA — téléphone (iPhone)', () => {
    test.use({
        viewport: { width: 390, height: 844 },
        hasTouch: true,
        isMobile: true,
        userAgent: IPHONE_UA,
    });

    test('iPhone non installé : garde-fou plein écran, back-office recouvert, instructions manuelles', async ({
        page,
    }) => {
        await page.goto('/backoffice/dashboard');

        await expect(
            page.getByText('Appuyez sur le bouton Partager de Safari.', {
                exact: false,
            }),
        ).toBeVisible();
        // Aucun bouton d'installation automatique sur iOS.
        await expect(
            page.getByRole('button', { name: /^Installer ELM$/ }),
        ).toHaveCount(0);

        await expectDashboardBlockedByGate(page);
    });

    test('iPhone déjà en mode standalone : interface normale, pas de garde-fou', async ({
        page,
    }) => {
        await page.addInitScript(() => {
            Object.defineProperty(window.navigator, 'standalone', {
                value: true,
                configurable: true,
            });
        });
        await page.goto('/backoffice/dashboard');

        await expectDashboardVisible(page);
    });
});

test.describe('Garde-fou PWA — Android', () => {
    test.use({
        viewport: { width: 412, height: 915 },
        hasTouch: true,
        isMobile: true,
        userAgent: ANDROID_PHONE_UA,
    });

    test("invite native captée : le clic sur « Installer ELM » déclenche prompt()", async ({
        page,
    }) => {
        await page.goto('/backoffice/dashboard');
        await expectDashboardBlockedByGate(page);

        await page.evaluate(() => {
            const evt = new Event('beforeinstallprompt', {
                cancelable: true,
            }) as Event & {
                prompt?: () => Promise<void>;
                userChoice?: Promise<{ outcome: string; platform: string }>;
            };
            evt.prompt = () => {
                (
                    window as unknown as { __promptCalled: boolean }
                ).__promptCalled = true;
                return Promise.resolve();
            };
            evt.userChoice = Promise.resolve({
                outcome: 'accepted',
                platform: 'android',
            });
            window.dispatchEvent(evt);
        });

        const installButton = page.getByRole('button', {
            name: /Installer ELM/,
        });
        await expect(installButton).toBeVisible();
        await installButton.click();

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

    test('appinstalled déclenché : le garde-fou se lève sans rechargement', async ({
        page,
    }) => {
        await page.goto('/backoffice/dashboard');
        await expectDashboardBlockedByGate(page);

        await page.evaluate(() => {
            window.dispatchEvent(new Event('appinstalled'));
        });

        await expectDashboardVisible(page);
    });
});

test.describe("Garde-fou PWA — navigateur sans chemin d'installation", () => {
    test.use({
        viewport: { width: 393, height: 851 },
        hasTouch: true,
        isMobile: true,
        userAgent: UNSUPPORTED_PHONE_UA,
    });

    test('affiche le message de repli, propose "Continuer dans le navigateur" seulement après le délai', async ({
        page,
    }) => {
        await page.goto('/backoffice/dashboard');
        await expectDashboardBlockedByGate(page);
        await expect(
            page.getByText("Votre navigateur ne permet pas l'installation", {
                exact: false,
            }),
        ).toBeVisible();

        const continueButton = page.getByRole('button', {
            name: 'Continuer dans le navigateur',
        });
        await expect(continueButton).toHaveCount(0);
        await expect(continueButton).toBeVisible({ timeout: 3_000 });

        await continueButton.click();
        await expectDashboardVisible(page);

        // Persisté pour la session : une nouvelle navigation ne reproduit pas le garde-fou.
        await page.goto('/backoffice/dashboard');
        await expectDashboardVisible(page);
    });
});

test.describe('Garde-fou PWA — tablette et desktop (non concernés)', () => {
    test('iPad : comportement web normal, jamais de garde-fou', async ({
        browser,
    }) => {
        const context = await browser.newContext({
            ...devices['iPad (gen 7)'],
            storageState: '.auth/user.json',
        });
        const page = await context.newPage();
        await page.goto('/backoffice/dashboard');

        await expectDashboardVisible(page);
        await context.close();
    });

    test('desktop : comportement inchangé, jamais de garde-fou', async ({
        page,
    }) => {
        await page.goto('/backoffice/dashboard');

        await expectDashboardVisible(page);
    });
});
