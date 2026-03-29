import { expect, type Locator, type Page } from '@playwright/test';

export const E2E_EMAIL = process.env.E2E_EMAIL ?? 'superadmin@admin.com';
export const E2E_PHONE = process.env.E2E_PHONE ?? '+33758855039';
export const E2E_PASSWORD = process.env.E2E_PASSWORD ?? 'Staff@2025';

export function escapeRegExp(value: string): string {
    return value.replace(/[.*+?^${}()|[\\]\\\\]/g, '\\\\$&');
}

export async function login(page: Page): Promise<void> {
    await page.goto('/login');

    // Attendre que Vue ait monté le formulaire
    await page.waitForSelector('input[name="password"]', { timeout: 20_000 });

    const emailInput = page.locator('input[name="email"]');
    if ((await emailInput.count()) > 0) {
        await emailInput.fill(E2E_EMAIL);
    } else {
        // Formulaire téléphone : l'input caché name="telephone" est géré par Vue.
        // On remplit le champ visible (type="tel") pour déclencher la réactivité,
        // puis on force la valeur sur l'input caché.
        const telVisible = page.locator('input[type="tel"]:visible').first();
        if ((await telVisible.count()) > 0) {
            const localDigits = E2E_PHONE.replace(/^\+\d{1,3}/, '').replace(/^0/, '');
            await telVisible.fill(localDigits);
        }
        await page.evaluate((phone) => {
            const hiddenPhone = document.querySelector('input[name="telephone"]') as HTMLInputElement | null;
            if (hiddenPhone) hiddenPhone.value = phone;
        }, E2E_PHONE);
    }

    await page.locator('input[name="password"]').fill(E2E_PASSWORD);
    await page.getByRole('button', { name: /se connecter/i }).click();

    await page.waitForURL((url) => !url.pathname.endsWith('/login'));
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
            ? page.getByRole('option', { name: new RegExp(escapeRegExp(optionName), 'i') }).first()
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

            const deleteItem = page.getByRole('menuitem', { name: /supprimer/i }).first();
            if (!(await deleteItem.isVisible().catch(() => false))) {
                break;
            }
            await deleteItem.click({ timeout: 3000, force: true });

            const confirmDelete = page.getByRole('button', { name: /^supprimer$/i }).last();
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
