/**
 * login-flow.spec.ts
 * Covers login scenarios beyond the happy path in smoke.spec.ts:
 *   - wrong credentials
 *   - field validation (empty phone, empty password)
 *   - remember me (checked / unchecked)
 *   - redirect to intended URL after login
 *
 * Run with: npx playwright test tests/e2e/login-flow.spec.ts --workers=1
 */
import { expect, type Page, test } from '@playwright/test';
import { E2E_PASSWORD, E2E_PHONE } from './helpers';

test.setTimeout(120_000);

// Session cookie name derived from APP_NAME ("Eau-la-maman" → slug → "eau-la-maman-session")
const SESSION_COOKIE = 'eau-la-maman-session';

// ─── Helpers locaux ────────────────────────────────────────────────────────────

/**
 * Injecte le numéro de téléphone dans l'input caché via la même technique que
 * le helper login() de helpers.ts (contournement du binding Vue réactif).
 */
async function injectPhone(page: Page, phone: string): Promise<void> {
    await page.evaluate((ph) => {
        const el = document.querySelector(
            'input[name="telephone"]',
        ) as HTMLInputElement | null;
        if (!el) return;
        const set = Object.getOwnPropertyDescriptor(
            HTMLInputElement.prototype,
            'value',
        )?.set;
        set?.call(el, ph);
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
    }, phone);
}

/**
 * Attend que la page /login soit prête et clique sur "Se connecter".
 */
async function clickSubmit(page: Page): Promise<void> {
    const btn = page
        .getByRole('button', { name: /se connecter/i })
        .first();
    await expect(btn).toBeEnabled({ timeout: 10_000 });
    await btn.click();
}

/**
 * Navigue vers /login, remplit le formulaire avec les credentials fournis
 * et clique sur le bouton de soumission.
 * Utilisé pour les tests qui partent d'une page blanche (pas encore sur /login).
 */
async function fillAndSubmitLogin(
    page: Page,
    phone: string,
    password: string,
    opts: { checkRemember?: boolean } = {},
): Promise<void> {
    await page.goto('/login');
    await page.waitForSelector('input[name="password"]', { timeout: 20_000 });

    await injectPhone(page, phone);
    await page.locator('input[name="password"]').fill(password);

    if (opts.checkRemember) {
        const checkbox = page.locator('#remember');
        await checkbox.click();
        await expect(checkbox).toHaveAttribute('data-state', 'checked', {
            timeout: 5_000,
        });
    }

    await clickSubmit(page);
}

/**
 * Soumet le formulaire /login depuis la page courante (sans re-naviguer).
 * Utile quand on est déjà redirigé vers /login après tentative d'accès protégée.
 */
async function submitFromCurrentLoginPage(
    page: Page,
    phone: string,
    password: string,
): Promise<void> {
    await page.waitForSelector('input[name="password"]', { timeout: 20_000 });
    await injectPhone(page, phone);
    await page.locator('input[name="password"]').fill(password);
    await clickSubmit(page);
}

// ─── Mauvais identifiants ──────────────────────────────────────────────────────

test('wrong credentials → stays on /login with error message', async ({
    page,
}) => {
    await page.goto('/login');
    await page.waitForSelector('input[name="password"]', { timeout: 20_000 });

    await injectPhone(page, E2E_PHONE);
    await page.locator('input[name="password"]').fill('WrongPassword_e2e!999');
    await clickSubmit(page);

    // Doit rester sur /login
    await expect(page).toHaveURL(/\/login(?:\?.*)?$/, { timeout: 30_000 });

    // Le message "Numéro de téléphone ou mot de passe incorrect." doit être visible
    await expect(
        page.locator('body'),
    ).toContainText(/numéro de téléphone ou mot de passe incorrect/i, {
        timeout: 15_000,
    });
});

// ─── Validation champs vides ───────────────────────────────────────────────────

test('empty phone (password filled) → server validation error on telephone', async ({
    page,
}) => {
    await page.goto('/login');
    await page.waitForSelector('input[name="password"]', { timeout: 20_000 });

    // Remplit uniquement le mot de passe (téléphone reste vide)
    await page.locator('input[name="password"]').fill(E2E_PASSWORD);
    await clickSubmit(page);

    // Reste sur /login
    await expect(page).toHaveURL(/\/login(?:\?.*)?$/, { timeout: 15_000 });

    // Une erreur de validation doit apparaître (champ téléphone requis)
    await expect(page.locator('[data-slot="input-error"]:visible, .text-destructive:visible').first()).toBeVisible({
        timeout: 10_000,
    });
});

test('empty password (phone filled) → stays on /login', async ({ page }) => {
    await page.goto('/login');
    await page.waitForSelector('input[name="password"]', { timeout: 20_000 });

    await injectPhone(page, E2E_PHONE);
    // Mot de passe intentionnellement non rempli

    // Retire l'attribut required pour laisser la soumission atteindre le serveur
    await page.evaluate(() => {
        document
            .querySelector<HTMLInputElement>('input[name="password"]')
            ?.removeAttribute('required');
    });

    await clickSubmit(page);

    // Doit rester sur /login (erreur serveur sur mot de passe)
    await expect(page).toHaveURL(/\/login(?:\?.*)?$/, { timeout: 15_000 });
});

// ─── Remember me ──────────────────────────────────────────────────────────────

test('remember me unchecked → clear cookies → redirected to /login', async ({
    page,
}) => {
    // Login sans cocher "Se souvenir de moi" (comportement par défaut)
    await fillAndSubmitLogin(page, E2E_PHONE, E2E_PASSWORD);
    await expect(page).not.toHaveURL(/\/login/, { timeout: 30_000 });

    // Simule l'expiration de session en effaçant tous les cookies
    await page.context().clearCookies();

    // L'accès à une route protégée doit rediriger vers /login
    await page.goto('/users');
    await expect(page).toHaveURL(/\/login(?:\?.*)?$/, { timeout: 20_000 });
});

test('remember me checked → session cookie removed → still authenticated', async ({
    page,
}) => {
    // Login en cochant "Se souvenir de moi"
    await fillAndSubmitLogin(page, E2E_PHONE, E2E_PASSWORD, {
        checkRemember: true,
    });
    await expect(page).not.toHaveURL(/\/login/, { timeout: 30_000 });

    // Récupère tous les cookies après authentification
    const allCookies = await page.context().cookies();

    // Supprime uniquement le cookie de session (laravel_session)
    const nonSessionCookies = allCookies.filter(
        (c) => c.name !== SESSION_COOKIE && !c.name.toLowerCase().includes('session'),
    );

    await page.context().clearCookies();

    if (nonSessionCookies.length > 0) {
        await page.context().addCookies(nonSessionCookies);
    }

    // Avec le cookie "remember_web_*" conservé, l'accès doit rester authentifié
    await page.goto('/users');
    await expect(page).not.toHaveURL(/\/login/, { timeout: 30_000 });
    await expect(page).toHaveURL(/\/users/, { timeout: 10_000 });
});

// ─── Redirect intended URL ─────────────────────────────────────────────────────

test('access protected route → login → redirected back to intended URL', async ({
    page,
}) => {
    // Accès sans authentification à une route protégée
    await page.goto('/users');

    // Fortify redirige vers /login (avec l'URL intended stockée en session)
    await expect(page).toHaveURL(/\/login(?:\?.*)?$/, { timeout: 20_000 });

    // Se connecte depuis la page /login courante (sans re-naviguer)
    await submitFromCurrentLoginPage(page, E2E_PHONE, E2E_PASSWORD);

    // Fortify redirige vers l'URL initialement demandée (/users), pas /dashboard
    await expect(page).toHaveURL(/\/users(?:\/|$|\?)/, { timeout: 30_000 });
});
