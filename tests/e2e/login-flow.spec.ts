/**
 * login-flow.spec.ts
 * Covers login scenarios beyond smoke tests:
 *   - wrong credentials
 *   - validation (empty phone, empty password)
 *   - remember me (checked / unchecked)
 *   - redirect to intended URL
 *
 * Run with: npx playwright test tests/e2e/login-flow.spec.ts --workers=1
 */
import { expect, type Page, test } from '@playwright/test';
import { E2E_PASSWORD, E2E_PHONE, fillLoginIdentifier } from './helpers';

test.setTimeout(300_000);

// Login flow tests must start unauthenticated, regardless of global storage state.
test.use({ storageState: { cookies: [], origins: [] } });

const SESSION_COOKIE = 'eau-la-maman-session';
const AUTH_ERROR_OR_THROTTLE_REGEX =
    /(num[ée]ro de t[ée]l[ée]phone ou mot de passe incorrect|these credentials do not match our records|trop de tentatives|too many attempts|too many requests|429|please wait|veuillez patienter|r[ée]essayez)/i;
const RATE_LIMIT_REGEX =
    /trop de tentatives|too many attempts|too many requests|429|please wait|veuillez patienter|seconds|secondes|r[ée]essayez/i;

async function clickSubmit(page: Page): Promise<void> {
    const btn = page.getByRole('button', { name: /se connecter/i }).first();
    await expect(btn).toBeEnabled({ timeout: 10_000 });
    await btn.click({ force: true });
}

async function checkRememberMe(page: Page): Promise<void> {
    const checkboxByRole = page
        .getByRole('checkbox', { name: /se souvenir de moi/i })
        .first();

    if ((await checkboxByRole.count()) > 0) {
        await checkboxByRole.click({ timeout: 5_000 });
        await expect(checkboxByRole).toHaveAttribute('aria-checked', 'true', {
            timeout: 5_000,
        });
        return;
    }

    const fallback = page.locator('#remember').first();
    await fallback.click({ timeout: 5_000 });
    await expect(fallback).toHaveAttribute('data-state', 'checked', {
        timeout: 5_000,
    });
}

async function fillAndSubmitLogin(
    page: Page,
    phone: string,
    password: string,
    opts: { checkRemember?: boolean } = {},
): Promise<void> {
    await page.goto('/login');
    await page.waitForSelector('input[name="password"]', { timeout: 20_000 });

    await fillLoginIdentifier(page, { phone });
    await page.locator('input[name="password"]').fill(password);

    if (opts.checkRemember) {
        await checkRememberMe(page);
    }

    await clickSubmit(page);
}

async function submitFromCurrentLoginPage(
    page: Page,
    phone: string,
    password: string,
): Promise<void> {
    await page.waitForSelector('input[name="password"]', { timeout: 20_000 });
    await fillLoginIdentifier(page, { phone });
    await page.locator('input[name="password"]').fill(password);
    await clickSubmit(page);
}

async function ensureAuthenticatedWithRetry(
    page: Page,
    opts: { checkRemember?: boolean } = {},
): Promise<void> {
    let lastError: unknown;

    for (let attempt = 1; attempt <= 3; attempt++) {
        await fillAndSubmitLogin(page, E2E_PHONE, E2E_PASSWORD, opts);

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
            const rateLimited = RATE_LIMIT_REGEX.test(bodyText);

            if (!rateLimited) {
                throw error;
            }

            await page.waitForTimeout(61_000);
        }
    }

    throw (
        lastError ??
        new Error('Unable to authenticate in login-flow remember-me test.')
    );
}

async function submitCurrentLoginWithRetry(
    page: Page,
    phone: string,
    password: string,
): Promise<void> {
    let lastError: unknown;

    for (let attempt = 1; attempt <= 3; attempt++) {
        await submitFromCurrentLoginPage(page, phone, password);

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
            const rateLimited = RATE_LIMIT_REGEX.test(bodyText);

            if (!rateLimited) {
                throw error;
            }

            await page.waitForTimeout(61_000);
        }
    }

    throw (
        lastError ??
        new Error(
            'Unable to submit login form after retries on intended URL flow.',
        )
    );
}

test('wrong credentials -> stays on /login with error message', async ({
    page,
}) => {
    await page.goto('/login');
    await page.waitForSelector('input[name="password"]', { timeout: 20_000 });

    await fillLoginIdentifier(page, { phone: E2E_PHONE });
    await page.locator('input[name="password"]').fill('WrongPassword_e2e!999');
    await clickSubmit(page);

    await expect(page).toHaveURL(/\/login(?:\?.*)?$/, { timeout: 30_000 });
    await expect(page.locator('body')).toContainText(
        AUTH_ERROR_OR_THROTTLE_REGEX,
        {
            timeout: 15_000,
        },
    );
});

test('access protected route -> login -> redirected back to intended URL', async ({
    page,
}) => {
    await page.goto('/users');

    await expect(page).toHaveURL(/\/login(?:\?.*)?$/, { timeout: 20_000 });

    await submitCurrentLoginWithRetry(page, E2E_PHONE, E2E_PASSWORD);

    await expect(page).toHaveURL(/\/users(?:\/|$|\?)/, { timeout: 30_000 });
});

test('empty phone (password filled) -> server validation error on telephone', async ({
    page,
}) => {
    await page.goto('/login');
    await page.waitForSelector('input[name="password"]', { timeout: 20_000 });

    await page.locator('input[name="password"]').fill(E2E_PASSWORD);
    await clickSubmit(page);

    await expect(page).toHaveURL(/\/login(?:\?.*)?$/, { timeout: 15_000 });

    await expect(
        page
            .locator(
                '[data-slot="input-error"]:visible, .text-destructive:visible',
            )
            .first(),
    ).toBeVisible({
        timeout: 10_000,
    });
});

test('empty password (phone filled) -> stays on /login', async ({ page }) => {
    await page.goto('/login');
    await page.waitForSelector('input[name="password"]', { timeout: 20_000 });

    await fillLoginIdentifier(page, { phone: E2E_PHONE });

    await page.evaluate(() => {
        document
            .querySelector<HTMLInputElement>('input[name="password"]')
            ?.removeAttribute('required');
    });

    await clickSubmit(page);

    await expect(page).toHaveURL(/\/login(?:\?.*)?$/, { timeout: 15_000 });
});

test('remember me unchecked -> clear cookies -> redirected to /login', async ({
    page,
}) => {
    await ensureAuthenticatedWithRetry(page);

    await page.context().clearCookies();

    await page.goto('/users');
    await expect(page).toHaveURL(/\/login(?:\?.*)?$/, { timeout: 20_000 });
});

test('remember me checked -> session cookie removed -> still authenticated', async ({
    page,
}) => {
    await ensureAuthenticatedWithRetry(page, { checkRemember: true });

    const allCookies = await page.context().cookies();
    const nonSessionCookies = allCookies.filter(
        (c) =>
            c.name !== SESSION_COOKIE &&
            !c.name.toLowerCase().includes('session'),
    );

    await page.context().clearCookies();

    if (nonSessionCookies.length > 0) {
        await page.context().addCookies(nonSessionCookies);
    }

    await page.goto('/users');
    await expect(page).not.toHaveURL(/\/login/, { timeout: 30_000 });
    await expect(page).toHaveURL(/\/users/, { timeout: 10_000 });
});
