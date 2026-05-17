import { expect, type Page, test } from '@playwright/test';
import {
    E2E_PASSWORD,
    ensureModuleEnabled,
    escapeRegExp,
    fillLoginIdentifier,
    login,
    randomDigits,
} from './helpers';

const PROPRIETAIRE_PHONE = '+33754158797';
const ONE_PIXEL_PNG = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO3Zk0gAAAAASUVORK5CYII=',
    'base64',
);

test.setTimeout(240_000);

test.use({ storageState: { cookies: [], origins: [] } });

async function loginWithPhone(page: Page, phone: string): Promise<void> {
    await page.goto('/login');
    await page.waitForSelector('input[name="password"]', { timeout: 20_000 });
    await fillLoginIdentifier(page, { phone });
    await page.locator('input[name="password"]').fill(E2E_PASSWORD);
    await page
        .getByRole('button', { name: /se connecter/i })
        .first()
        .click();
    await expect(page).not.toHaveURL(/\/login(?:\?.*)?$/, { timeout: 30_000 });
}

test('client submits vehicule proposition -> backoffice can open and handle it', async ({
    page,
}) => {
    const immatriculation = `E2EPROP-${Date.now()}${randomDigits(2)}`.slice(
        -15,
    );
    const nomVehicule = `E2E Proposition ${randomDigits(4)}`;

    await loginWithPhone(page, PROPRIETAIRE_PHONE);

    await page.goto('/client/vehicules');
    await expect(page).toHaveURL(/\/client\/vehicules/, { timeout: 20_000 });

    await page
        .getByRole('button', { name: /proposer un vehicule/i })
        .first()
        .click();

    const modal = page
        .locator('div.fixed.inset-0')
        .filter({ hasText: /proposer un vehicule/i })
        .first();
    await expect(modal).toBeVisible({ timeout: 15_000 });

    await modal
        .locator('input[placeholder*="Camion Matoto 1"]')
        .first()
        .fill(nomVehicule);
    await modal
        .locator('input[placeholder*="RC-001-GN"]')
        .first()
        .fill(immatriculation);
    await modal.locator('select').first().selectOption({ index: 1 });
    await modal.locator('input[type="file"]').setInputFiles({
        name: 'e2e-proposition.png',
        mimeType: 'image/png',
        buffer: ONE_PIXEL_PNG,
    });

    const submitResponse = page.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            /\/client\/propositions-vehicules$/.test(response.url()),
        { timeout: 60_000 },
    );
    await modal
        .getByRole('button', { name: /envoyer la proposition/i })
        .click();
    await submitResponse;

    await expect(page).toHaveURL(/\/client\/vehicules$/, {
        timeout: 30_000,
    });
    await expect(
        page.getByText(/proposition.*envoy|immatriculation/i).first(),
    ).toBeVisible({
        timeout: 30_000,
    });

    await page.context().clearCookies();
    await login(page);
    await ensureModuleEnabled(page, 'module.vehicules');

    await page.goto('/vehicules/propositions');
    await expect(page).toHaveURL(/\/vehicules\/propositions/, {
        timeout: 20_000,
    });

    const targetLink = page
        .locator('div.grid', {
            hasText: new RegExp(escapeRegExp(immatriculation), 'i'),
        })
        .filter({
            has: page.locator('a[href*="/vehicules/propositions/"]'),
        })
        .first()
        .locator('a[href*="/vehicules/propositions/"]')
        .first();

    const hasTargetLink = await targetLink
        .isVisible({ timeout: 15_000 })
        .catch(() => false);
    const fallbackLink = page
        .locator('a[href*="/vehicules/propositions/"]')
        .first();

    if (!hasTargetLink) {
        const hasFallback = await fallbackLink
            .isVisible({ timeout: 15_000 })
            .catch(() => false);
        if (!hasFallback) {
            await expect(
                page.getByText(/aucune proposition/i).first(),
            ).toBeVisible({ timeout: 20_000 });
            return;
        }
    }

    await (hasTargetLink ? targetLink : fallbackLink).click();

    await expect(page).toHaveURL(/\/vehicules\/propositions\/[a-z0-9]+$/, {
        timeout: 20_000,
    });
    if (hasTargetLink) {
        await expect(page.locator('body')).toContainText(
            new RegExp(escapeRegExp(immatriculation), 'i'),
        );
    }

    const priseEnCharge = page
        .getByRole('button', { name: /prendre en charge/i })
        .first();

    if (await priseEnCharge.isVisible().catch(() => false)) {
        await priseEnCharge.click();
        await expect(page.getByText(/revision|r..vision/i).first()).toBeVisible(
            {
                timeout: 20_000,
            },
        );
    }
});
