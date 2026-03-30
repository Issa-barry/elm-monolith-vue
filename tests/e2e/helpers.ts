import { expect, type Locator, type Page } from '@playwright/test';

export const E2E_EMAIL = process.env.E2E_EMAIL ?? 'superadmin@admin.com';
export const E2E_PHONE = process.env.E2E_PHONE ?? '+33758855039';
export const E2E_PASSWORD = process.env.E2E_PASSWORD ?? 'Staff@2025';

export function escapeRegExp(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

export async function login(page: Page): Promise<void> {
    let lastError: unknown;

    for (let attempt = 1; attempt <= 3; attempt++) {
        await page.goto('/login');
        await page.waitForSelector('input[name="password"]', {
            timeout: 20_000,
        });

        const emailInput = page.locator('input[name="email"]');
        if ((await emailInput.count()) > 0) {
            await emailInput.first().fill(E2E_EMAIL);
        } else {
            await page.evaluate((phone) => {
                const hiddenTelephone = document.querySelector(
                    'input[name="telephone"]',
                ) as HTMLInputElement | null;

                if (!hiddenTelephone) return;

                const setValue = Object.getOwnPropertyDescriptor(
                    HTMLInputElement.prototype,
                    'value',
                )?.set;

                setValue?.call(hiddenTelephone, phone);
                hiddenTelephone.dispatchEvent(
                    new Event('input', { bubbles: true }),
                );
                hiddenTelephone.dispatchEvent(
                    new Event('change', { bubbles: true }),
                );
            }, E2E_PHONE);
        }

        await page.locator('input[name="password"]').fill(E2E_PASSWORD);

        const submitButton = page
            .getByRole('button', { name: /se connecter/i })
            .first();
        await expect(submitButton).toBeEnabled({ timeout: 10_000 });
        await submitButton.click();

        try {
            await expect(page).not.toHaveURL(/\/login(?:\?.*)?$/, {
                timeout: 30_000,
            });
            return;
        } catch (error) {
            lastError = error;

            const bodyText = await page
                .locator('body')
                .innerText()
                .catch(() => '');
            const isRateLimited =
                /too many|trop de tentatives|veuillez patienter|secondes/i.test(
                    bodyText,
                );

            await page.waitForTimeout(isRateLimited ? 61_000 : 2_000 * attempt);
        }
    }

    throw (
        lastError ??
        new Error('Unable to login after retries in E2E helper.')
    );
}

export function getVisibleSearchInput(page: Page): Locator {
    return page.locator('input[placeholder*="rechercher" i]:visible').first();
}

export async function openRowActions(row: Locator): Promise<void> {
    await row.locator('button').last().click({ timeout: 3000 });
}

export async function selectOptionFromCombobox(
    page: Page,
    combobox: Locator,
    optionName?: string | RegExp,
): Promise<void> {
    await combobox.click({ timeout: 3000 });

    const option = optionName
        ? typeof optionName === 'string'
            ? page
                  .getByRole('option', {
                      name: new RegExp(escapeRegExp(optionName), 'i'),
                  })
                  .first()
            : page.getByRole('option', { name: optionName }).first()
        : page.locator('[role="option"]:visible').first();

    await expect(option).toBeVisible();
    await option.click({ timeout: 3000 });
}

export async function cleanupRowsByPrefix(
    page: Page,
    route: string,
    prefix: string,
): Promise<void> {
    await login(page);
    await page.goto(route);

    const searchInput = getVisibleSearchInput(page);
    await searchInput.fill(prefix);

    const guard = new RegExp(escapeRegExp(prefix), 'i');

    for (let i = 0; i < 6; i++) {
        const row = page.locator('tbody tr', { hasText: guard }).first();

        if (!(await row.isVisible().catch(() => false))) {
            break;
        }

        try {
            await openRowActions(row);

            const deleteItem = page
                .getByRole('menuitem', { name: /supprimer/i })
                .first();
            if (!(await deleteItem.isVisible().catch(() => false))) {
                break;
            }
            await deleteItem.click({ timeout: 3000, force: true });

            const confirmDelete = page
                .getByRole('button', { name: /^supprimer$/i })
                .last();
            if (!(await confirmDelete.isVisible().catch(() => false))) {
                break;
            }
            await confirmDelete.click({ timeout: 3000 });
        } catch {
            break;
        }

        await page.waitForLoadState('networkidle');
        await searchInput.fill(prefix);
    }
}
