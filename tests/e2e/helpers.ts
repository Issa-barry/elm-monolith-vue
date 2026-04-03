import { expect, test, type Locator, type Page } from '@playwright/test';

export const E2E_EMAIL = process.env.E2E_EMAIL ?? 'superadmin@admin.com';
export const E2E_PHONE = process.env.E2E_PHONE ?? '+33758855039';
export const E2E_PASSWORD = process.env.E2E_PASSWORD ?? 'Staff@2025';

interface LoginCountryOption {
    code: string;
    prefix: string;
}

interface ParsedLoginPhone {
    country: LoginCountryOption;
    localDigits: string;
}

const LOGIN_COUNTRIES: LoginCountryOption[] = [
    { code: 'GN', prefix: '+224' },
    { code: 'GW', prefix: '+245' },
    { code: 'SN', prefix: '+221' },
    { code: 'ML', prefix: '+223' },
    { code: 'CI', prefix: '+225' },
    { code: 'LR', prefix: '+231' },
    { code: 'SL', prefix: '+232' },
    { code: 'FR', prefix: '+33' },
    { code: 'CN', prefix: '+86' },
    { code: 'AE', prefix: '+971' },
    { code: 'IN', prefix: '+91' },
];

export function escapeRegExp(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

export function randomDigits(length: number): string {
    const max = 10 ** length;
    const array = new Uint32Array(1);
    crypto.getRandomValues(array);
    return `${array[0] % max}`.padStart(length, '0');
}

function normalizeE164Phone(rawPhone: string): string {
    const trimmed = rawPhone.trim();
    if (!trimmed) return '';

    const withPlus = trimmed.startsWith('+')
        ? trimmed
        : trimmed.startsWith('00')
          ? `+${trimmed.slice(2)}`
          : `+${trimmed}`;

    const digits = withPlus.replace(/\D/g, '');
    return digits ? `+${digits}` : '';
}

function parseLoginPhone(rawPhone: string): ParsedLoginPhone | null {
    const normalized = normalizeE164Phone(rawPhone);
    if (!normalized) return null;

    const matchedCountry = [...LOGIN_COUNTRIES]
        .sort((a, b) => b.prefix.length - a.prefix.length)
        .find((country) => normalized.startsWith(country.prefix));

    if (!matchedCountry) return null;

    return {
        country: matchedCountry,
        localDigits: normalized
            .slice(matchedCountry.prefix.length)
            .replace(/\D/g, ''),
    };
}

async function ensureLoginCountry(
    page: Page,
    country: LoginCountryOption,
): Promise<void> {
    const combobox = page.locator('form').getByRole('combobox').first();
    if ((await combobox.count()) === 0) {
        return;
    }

    const currentValue = (
        await combobox.innerText().catch(() => '')
    ).toLowerCase();
    if (
        currentValue.includes(country.prefix.toLowerCase()) ||
        currentValue.includes(country.code.toLowerCase())
    ) {
        return;
    }

    await page.evaluate((countryCode) => {
        globalThis.localStorage?.setItem('login_country_code', countryCode);
    }, country.code);

    await page.reload();
    await page.waitForSelector('input[name="password"]', { timeout: 20_000 });
}

export async function fillLoginIdentifier(
    page: Page,
    options: { email?: string; phone?: string } = {},
): Promise<void> {
    const email = options.email ?? E2E_EMAIL;
    const phone = options.phone ?? E2E_PHONE;

    const emailInput = page.locator('input[name="email"]');
    if ((await emailInput.count()) > 0) {
        await emailInput.first().fill(email);
        return;
    }

    const parsedPhone = parseLoginPhone(phone);
    if (parsedPhone) {
        await ensureLoginCountry(page, parsedPhone.country);
    }

    const telInput = page.locator('form input[type="tel"]').first();
    await expect(telInput).toBeVisible({ timeout: 10_000 });
    await telInput.fill(parsedPhone?.localDigits ?? phone.replace(/\D/g, ''));

    const hiddenTelephone = page.locator('input[name="telephone"]').first();
    if ((await hiddenTelephone.count()) > 0 && parsedPhone) {
        await expect(hiddenTelephone).toHaveValue(
            `${parsedPhone.country.prefix}${parsedPhone.localDigits.replace(/^0/, '')}`,
            { timeout: 10_000 },
        );
    }
}

export async function login(page: Page): Promise<void> {
    // Verify whether storageState already loaded a valid session.
    await page.goto('/dashboard');
    if (!page.url().includes('/login')) {
        return;
    }

    let lastError: unknown;

    for (let attempt = 1; attempt <= 3; attempt++) {
        await page.goto('/login');
        await page.waitForSelector('input[name="password"]', {
            timeout: 20_000,
        });

        await fillLoginIdentifier(page);
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
                /too many|trop de tentatives|veuillez patienter|please wait|seconds|secondes|r[ée]essayez|requests|429/i.test(
                    bodyText,
                );

            await page.waitForTimeout(isRateLimited ? 61_000 : 2_000 * attempt);
        }
    }

    throw (
        lastError ?? new Error('Unable to login after retries in E2E helper.')
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

interface CreateUserParams {
    prenom: string;
    nom: string;
    tel: string;
    email?: string;
    role?: string | RegExp;
    password?: string;
}

/**
 * Fills the info tab of the user form and advances to the password tab.
 * The page must already be on /users/create when this is called.
 */
export async function fillUserInfoAndAdvance(
    page: Page,
    {
        prenom,
        nom,
        tel,
        email,
        role = /manager/i,
    }: Omit<CreateUserParams, 'password'>,
): Promise<void> {
    const form = page.locator('#user-form');
    await selectOptionFromCombobox(
        page,
        form.getByRole('combobox').first(),
        /guinée$/i,
    );
    await page.locator('#prenom').fill(prenom);
    await page.locator('#nom').fill(nom);
    await page.locator('#telephone').fill(tel);
    if (email) {
        await page.locator('#email').fill(email);
    }
    await selectOptionFromCombobox(
        page,
        form.getByRole('combobox').nth(1),
        role,
    );
    await form.getByRole('combobox').nth(2).click();
    await page.locator('[role="option"]:visible').first().click();
    await form.locator('button[type="submit"]:visible').click();
    await expect(page.locator('#password')).toBeVisible();
}

export async function createUser(
    page: Page,
    {
        prenom,
        nom,
        tel,
        email,
        role = /manager/i,
        password = 'Password123',
    }: CreateUserParams,
): Promise<void> {
    await page.goto('/users/create');
    await fillUserInfoAndAdvance(page, { prenom, nom, tel, email, role });
    await page.locator('#password').fill(password);
    await page.locator('#password_confirmation').fill(password);
    await page
        .locator('#user-form button[type="submit"]:visible')
        .first()
        .click();
    await expect(page).toHaveURL(/\/users\/\d+\/edit$/);
}

export async function findUserInList(
    page: Page,
    query: string,
): Promise<Locator> {
    await page.goto('/users');
    const search = getVisibleSearchInput(page);
    await search.fill(query);
    const row = page
        .locator('tbody tr', {
            hasText: new RegExp(escapeRegExp(query), 'i'),
        })
        .first();
    await expect(row).toBeVisible();
    return row;
}

export async function findRowByName(
    page: Page,
    name: string,
): Promise<Locator> {
    const search = getVisibleSearchInput(page);
    await search.fill(name);
    return page
        .locator('tbody tr', { hasText: new RegExp(escapeRegExp(name), 'i') })
        .first();
}

export function registerCleanup(route: string, prefix: string): void {
    test.afterEach(async ({ browser }) => {
        try {
            const context = await browser.newContext();
            try {
                const p = await context.newPage();
                await cleanupRowsByPrefix(p, route, prefix);
            } finally {
                await context.close().catch(() => undefined);
            }
        } catch (e) {
            console.warn(`E2E cleanup warning (${route}):`, e);
        }
    });
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
