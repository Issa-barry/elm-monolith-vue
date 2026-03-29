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
        // Formulaire téléphone PrimeVue Select + input visible (type="tel").
        // Extraire le prefix (+33) et les chiffres locaux (758855039).
        const match = E2E_PHONE.match(/^(\+\d{1,4})(\d+)$/);
        if (match) {
            const [, prefix, localDigits] = match;
            // Sélectionner le pays correspondant au prefix dans le Select
            const countryCombo = page.getByRole('combobox').first();
            if ((await countryCombo.count()) > 0) {
                await countryCombo.click();
                const option = page.getByRole('option').filter({ hasText: prefix }).first();
                if ((await option.count()) > 0) {
                    await option.click();
                }
            }
            // Remplir les chiffres locaux dans l'input visible
            const telVisible = page.locator('input[type="tel"]:visible').first();
            await telVisible.fill(localDigits);
            // Attendre que Vue mette à jour l'input caché
            await page.waitForFunction(
                (phone) => (document.querySelector('input[name="telephone"]') as HTMLInputElement | null)?.value === phone,
                E2E_PHONE,
                { timeout: 5_000 },
            ).catch(() => undefined); // Tolérant si timeout
        }
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
