import { expect, test, type Locator, type Page } from '@playwright/test';

export const E2E_EMAIL = process.env.E2E_EMAIL ?? 'superadmin@admin.com';
export const E2E_PHONE = process.env.E2E_PHONE ?? '+33758855039';
export const E2E_PASSWORD = process.env.E2E_PASSWORD ?? 'Staff@2025';
export const E2E_FALLBACK_PHONES = Array.from(
    new Set([E2E_PHONE, '+224656555520', '+33769442565', '+224622176056']),
);

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

function tryFixMojibake(value: string): string {
    try {
        return Buffer.from(value, 'latin1').toString('utf8');
    } catch {
        return value;
    }
}

function matchesOptionText(
    optionText: string,
    expected: string | RegExp,
): boolean {
    const raw = optionText.trim();
    const fixed = tryFixMojibake(raw).trim();

    if (typeof expected === 'string') {
        const query = expected.trim().toLowerCase();
        return (
            raw.toLowerCase().includes(query) ||
            fixed.toLowerCase().includes(query)
        );
    }

    const safeRegex = new RegExp(
        expected.source,
        expected.flags.replaceAll('g', ''),
    );
    return safeRegex.test(raw) || safeRegex.test(fixed);
}
function visibleComboboxOptions(page: Page): Locator {
    return page.locator(
        '[role="listbox"]:visible [role="option"]:not([data-pc-section="emptymessage"]), [role="option"]:visible:not([data-pc-section="emptymessage"])',
    );
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
    const normalizedPhone = normalizeE164Phone(phone);
    if (parsedPhone) {
        await ensureLoginCountry(page, parsedPhone.country);
    }

    const telInput = page.locator('form input[type="tel"]').first();
    await expect(telInput).toBeVisible({ timeout: 10_000 });
    await telInput.fill(parsedPhone?.localDigits ?? phone.replace(/\D/g, ''));

    const hiddenTelephone = page.locator('input[name="telephone"]').first();
    const telephoneForSubmit = parsedPhone
        ? `${parsedPhone.country.prefix}${parsedPhone.localDigits.replace(/^0/, '')}`
        : normalizedPhone;

    if ((await hiddenTelephone.count()) > 0) {
        if (telephoneForSubmit) {
            await hiddenTelephone.evaluate((input, value) => {
                const field = input as HTMLInputElement;
                field.value = value;
                field.dispatchEvent(new Event('input', { bubbles: true }));
                field.dispatchEvent(new Event('change', { bubbles: true }));
            }, telephoneForSubmit);
        }

        if (parsedPhone) {
            await expect(hiddenTelephone).toHaveValue(telephoneForSubmit, {
                timeout: 10_000,
            });
        }
    }
}

/**
 * Connexion dédiée à l'organisation "Eau La Maman V2 Demo" (seule
 * organisation du projet avec le moteur de commissions V2 activé — cf.
 * ElmV2DemoSeeder). Volontairement distincte de `login()` : celle-ci
 * court-circuite si une session valide est déjà chargée (storageState par
 * défaut = admin "elm", Legacy), ce qui empêcherait jamais de basculer vers
 * ce second compte. N'écrit/ne lit aucun storageState partagé : à appeler à
 * chaque test qui a besoin du contexte V2 (pas de globalSetup dédié, pour ne
 * jamais risquer d'affecter les specs Legacy existantes).
 */
export async function loginAsElmV2Demo(page: Page): Promise<void> {
    let lastError: unknown;

    for (let attempt = 1; attempt <= 3; attempt++) {
        try {
            // Le storageState par défaut (.auth/user.json, écrit par global-setup.ts)
            // authentifie déjà le contexte comme admin "elm" — /login est une route
            // `guest` (routes/web.php) qui redirige un utilisateur déjà connecté vers
            // le dashboard sans jamais rendre le formulaire, cf. le même pattern dans
            // smoke.spec.ts ("avec storageState actif, /login redirigerait vers le
            // dashboard"). Sans ce clearCookies(), le password input n'apparaît jamais.
            await page.context().clearCookies();
            await page.goto('/login');
            await page.waitForSelector('input[name="password"]', {
                timeout: 20_000,
            });
            await fillLoginIdentifier(page, { phone: '+224600000201' });
            await page.locator('input[name="password"]').fill('ElmV2Demo@2025');

            const submitButton = page
                .getByRole('button', { name: /se connecter/i })
                .first();
            await expect(submitButton).toBeEnabled({ timeout: 10_000 });

            // Contrairement à `login()`, ce compte n'a pas de numéro de repli : le
            // seul moyen d'éviter le throttle `login` (5/min par téléphone+IP, cf.
            // FortifyServiceProvider::configureRateLimiting) est d'attendre la fin de
            // la fenêtre avant de retenter avec le MÊME numéro. Sans cette détection,
            // un simple 429 (page HTML brute "Too Many Requests", jamais transformée
            // en erreur Inertia — cf. LockoutResponse, jamais atteint par le pipeline
            // Fortify actuel) laisse `not.toHaveURL(/login/)` échouer en silence après
            // 15s, et les 3 tentatives ci-dessous n'attendent que 0.5-1.5s : elles
            // retombent toutes dans la même fenêtre de 60s et épuisent le budget de
            // retries pour rien (cause confirmée des échecs CI en cascade sur les
            // specs commission-* qui appellent toutes ce helper).
            const [response] = await Promise.all([
                page.waitForResponse(
                    (r) =>
                        r.url().includes('/login') &&
                        r.request().method() === 'POST',
                    { timeout: 15_000 },
                ),
                submitButton.click(),
            ]);

            if (response.status() === 429) {
                const retryAfterSeconds =
                    Number(response.headers()['retry-after']) || 60;
                await page.waitForTimeout((retryAfterSeconds + 1) * 1000);
                throw new Error(
                    `Rate limited (429) on /login POST, waited ${retryAfterSeconds}s before retrying.`,
                );
            }

            await expect(page).not.toHaveURL(/\/login(?:\?.*)?$/, {
                timeout: 15_000,
            });
            return;
        } catch (error) {
            lastError = error;
            await page.waitForTimeout(500 * attempt);
        }
    }

    throw lastError instanceof Error
        ? new Error(`loginAsElmV2Demo failed after retries.\nCause: ${lastError.message}`)
        : new Error('loginAsElmV2Demo failed after retries.');
}

export async function login(page: Page): Promise<void> {
    // Verify whether storageState already loaded a valid session.
    await page.goto('/backoffice/dashboard');
    if (!page.url().includes('/login')) {
        return;
    }

    let lastError: unknown;
    let lastPageBody = '';

    for (let attempt = 1; attempt <= 3; attempt++) {
        for (const phone of E2E_FALLBACK_PHONES) {
            await page.goto('/login');
            await page.waitForSelector('input[name="password"]', {
                timeout: 20_000,
            });

            await fillLoginIdentifier(page, { phone });
            await page.locator('input[name="password"]').fill(E2E_PASSWORD);

            const submitButton = page
                .getByRole('button', { name: /se connecter/i })
                .first();
            await expect(submitButton).toBeEnabled({ timeout: 10_000 });

            // Même piège que loginAsElmV2Demo() ci-dessus (cf. son commentaire) : un 429
            // (throttle `login` de Fortify, 5/min par identifiant+IP) reste une page HTML
            // brute jamais transformée en erreur Inertia, donc pas forcément détectable au
            // seul texte de la page. On l'intercepte directement pour attendre
            // `retry-after` avant de retenter — sans quoi les tentatives suivantes (sur ce
            // numéro comme sur les autres, si le throttle est partagé par IP) ré-échouent
            // immédiatement en boucle jusqu'à épuisement des 3 x N tentatives disponibles.
            const [response] = await Promise.all([
                page.waitForResponse(
                    (r) =>
                        r.url().includes('/login') &&
                        r.request().method() === 'POST',
                    { timeout: 15_000 },
                ),
                submitButton.click(),
            ]);

            if (response.status() === 429) {
                const retryAfterSeconds =
                    Number(response.headers()['retry-after']) || 60;
                lastError = new Error(
                    `Rate limited (429) on /login POST for ${phone}, waited ${retryAfterSeconds}s before retrying.`,
                );
                await page.waitForTimeout((retryAfterSeconds + 1) * 1000);
                continue;
            }

            try {
                await expect(page).not.toHaveURL(/\/login(?:\?.*)?$/, {
                    timeout: 12_000,
                });
                return;
            } catch (error) {
                lastError = error;

                const bodyText = await page
                    .locator('body')
                    .innerText()
                    .catch(() => '');
                lastPageBody = bodyText;
                const isRateLimited =
                    /too many|trop de tentatives|veuillez patienter|please wait|seconds|secondes|essayez|requests|429/i.test(
                        bodyText,
                    );

                await page.waitForTimeout(isRateLimited ? 200 : 500 * attempt);
            }
        }
    }

    const snippet = lastPageBody.trim().replace(/\s+/g, ' ').slice(0, 500);
    const message = `Unable to login after retries in E2E helper. Phones tried: ${E2E_FALLBACK_PHONES.join(', ')}. Last page snippet: ${snippet || 'n/a'}`;
    throw lastError instanceof Error
        ? new Error(`${message}\nCause: ${lastError.message}`)
        : new Error(message);
}

/**
 * Closes the DataFilters drawer (Sheet) if it is currently open, and waits
 * for its overlay to disappear so it can no longer intercept clicks on
 * elements behind it (e.g. the inline agence MultiSelect in the toolbar).
 */
export async function closeFilterDrawerIfOpen(page: Page): Promise<void> {
    const overlay = page.locator('[data-slot="sheet-overlay"]').first();
    if (!(await overlay.isVisible({ timeout: 1_000 }).catch(() => false))) {
        return;
    }

    const closeBtn = page.getByTestId('filters-drawer-close').first();
    if (await closeBtn.isVisible({ timeout: 1_000 }).catch(() => false)) {
        await closeBtn.click();
    } else {
        await page.keyboard.press('Escape');
    }

    await overlay
        .waitFor({ state: 'detached', timeout: 5_000 })
        .catch(() => undefined);
}

/**
 * Opens the DataFilters drawer, selects an option in the multi-select for the
 * given field key, then clicks "Appliquer les filtres" (which also closes the
 * drawer). Mirrors the pattern used by tests/e2e/vente-filtre-statut.spec.ts.
 */
export async function applyDrawerFilterOption(
    page: Page,
    fieldKey: string,
    optionName: string | RegExp,
): Promise<void> {
    await page
        .getByRole('button', { name: /filtres/i })
        .first()
        .click();
    const combobox = page
        .getByTestId(`filter-field-${fieldKey}`)
        .locator('[data-pc-name="multiselect"]')
        .first();
    await selectOptionFromCombobox(page, combobox, optionName);
    await page.keyboard.press('Escape');
    await page.getByTestId('filters-apply').click();
}

/**
 * Trouve le champ de recherche/texte visible de la page courante.
 *
 * Standard UI (AGENTS.md §2) : les pages de liste migrées vers
 * `DataFilters trigger-only` n'affichent plus leurs champs en barre — ils
 * vivent dans le drawer Filtres. Sur ces pages, aucun input ne matche tant
 * que le drawer n'est pas ouvert : on ouvre alors le bouton Filtres de
 * l'en-tête avant de chercher le champ, pour que ce helper continue de
 * fonctionner à l'identique pour tous ses appelants (pages migrées ou non).
 */
export async function getVisibleSearchInput(page: Page): Promise<Locator> {
    const direct = page
        .locator(
            // Le testid `filter-inline-*` est aussi posé sur le wrapper <div> des filtres
            // select/multi-select (cf. DataFilters.vue) — restreint à `input` pour ne jamais
            // matcher autre chose qu'un vrai champ texte remplissable (ex: le filtre Statut,
            // qui peut désormais apparaître avant "Rechercher" dans le DOM, cf. standard UI
            // Agence → Statut → autres filtres inline).
            '[data-testid="search-input"]:visible, input[data-testid^="filter-inline-"]:visible, input[placeholder*="rechercher" i]:not([data-testid="global-search"]):visible, input[placeholder*="recherche" i]:not([data-testid="global-search"]):visible',
        )
        .first();

    // `waitFor` (contrairement à `isVisible({ timeout })`, un contrôle
    // ~immédiat) attend activement que l'élément apparaisse — nécessaire ici
    // car cette fonction est souvent appelée juste après une navigation
    // Inertia, pendant que Vue est encore en train de monter l'en-tête ; un
    // simple contrôle instantané renverrait `false` par excès de prudence et
    // figerait ce helper sur un sélecteur mort en permanence pour l'appel.
    const directVisible = await direct
        .waitFor({ state: 'visible', timeout: 8_000 })
        .then(() => true)
        .catch(() => false);
    if (directVisible) {
        return direct;
    }

    const filtresBtn = page.getByRole('button', { name: /^filtres/i }).first();
    const filtresVisible = await filtresBtn
        .waitFor({ state: 'visible', timeout: 8_000 })
        .then(() => true)
        .catch(() => false);
    if (filtresVisible) {
        await filtresBtn.click();
        // Restreint aux vrais champs texte (type: 'text' de DataFilters, cf.
        // `filter-field-<key>` posé sur ce champ précis) et exclut
        // explicitement les inputs internes PrimeVue (`[data-pc-section]`,
        // ex: le hidden input readonly du MultiSelect Agence/Statut) — sans
        // ça `.first()` peut résoudre vers un input non éditable et bloquer
        // indéfiniment un `.fill()`.
        const inDrawer = page
            .locator(
                '[data-testid="filters-drawer"] [data-testid^="filter-field-"] input[type="text"]:visible:not([data-pc-section]), [data-testid="filters-drawer"] [data-testid^="filter-field-"] input[type="search"]:visible:not([data-pc-section])',
            )
            .first();
        const inDrawerVisible = await inDrawer
            .waitFor({ state: 'visible', timeout: 5_000 })
            .then(() => true)
            .catch(() => false);
        if (inDrawerVisible) {
            return inDrawer;
        }
    }

    return direct;
}

export async function openRowActions(row: Locator): Promise<void> {
    await row.locator('button').last().click({ timeout: 3000 });
}

export async function selectOptionFromCombobox(
    page: Page,
    combobox: Locator,
    optionName?: string | RegExp,
): Promise<void> {
    await combobox.scrollIntoViewIfNeeded().catch(() => undefined);

    const visibleOptions = visibleComboboxOptions(page);
    for (let attempt = 0; attempt < 3; attempt++) {
        await combobox.click({ timeout: 3_000, force: true });
        const hasVisibleOptions = await visibleOptions
            .first()
            .isVisible({ timeout: 5_000 })
            .catch(() => false);
        if (hasVisibleOptions) {
            break;
        }

        await combobox.press('ArrowDown').catch(() => undefined);
        const hasOptionsAfterKeyboard = await visibleOptions
            .first()
            .isVisible({ timeout: 3_000 })
            .catch(() => false);
        if (hasOptionsAfterKeyboard) {
            break;
        }

        // Strategy 3: PrimeVue AutoComplete — close the panel first (the input
        // click in Strategy 1 may have opened it; the dropdown button TOGGLES, so
        // clicking it while the panel is open would close it instead of loading
        // options). Escape first, then click the dropdown button to reliably open
        // the panel and fire @complete.
        await page.keyboard.press('Escape').catch(() => undefined);
        await page
            .locator('[role="listbox"]')
            .first()
            .waitFor({ state: 'hidden', timeout: 1_000 })
            .catch(() => undefined);
        await combobox
            .locator('xpath=following-sibling::button')
            .first()
            .click({ timeout: 2_000, force: true })
            .catch(() => undefined);
        const hasOptionsAfterDropdownBtn = await visibleOptions
            .first()
            .isVisible({ timeout: 5_000 })
            .catch(() => false);
        if (hasOptionsAfterDropdownBtn) {
            break;
        }

        // Close panel before next attempt
        await page.keyboard.press('Escape').catch(() => undefined);
        await page
            .locator('[role="listbox"]')
            .first()
            .waitFor({ state: 'hidden', timeout: 1_000 })
            .catch(() => undefined);
    }

    await expect(visibleOptions.first()).toBeVisible({ timeout: 15_000 });

    let option = visibleOptions.first();

    if (optionName) {
        const optionCount = await visibleOptions.count();
        let selected: Locator | null = null;

        for (let i = 0; i < optionCount; i++) {
            const candidate = visibleOptions.nth(i);
            const text = (await candidate.innerText().catch(() => '')).trim();
            if (text && matchesOptionText(text, optionName)) {
                selected = candidate;
                break;
            }
        }

        if (!selected) {
            const preview = await visibleOptions
                .allInnerTexts()
                .then((items) => items.slice(0, 8).join(' | '))
                .catch(() => 'no options');
            throw new Error(
                `Unable to find combobox option: ${String(optionName)}. Visible options: ${preview}`,
            );
        }

        option = selected;
    }

    await expect(option).toBeVisible({ timeout: 15_000 });
    await option.click({ timeout: 3_000 });
    // Wait for the listbox to close before returning, so the next combobox
    // interaction does not see stale options from this dropdown.
    await page
        .locator('[role="listbox"]')
        .first()
        .waitFor({ state: 'hidden', timeout: 2_000 })
        .catch(() => undefined);
}

/**
 * Navigates to the first site in the list, opens its Véhicules tab,
 * and returns the site URL so the caller can return to it later.
 * Requires data-testid="site-vehicles-tab" and data-testid="add-site-vehicle-btn".
 */
export async function navigateToFirstSiteVehiclesTab(
    page: Page,
): Promise<string> {
    await page.goto('/backoffice/sites');
    // networkidle borné explicitement et non-fatal : une connexion persistante
    // (WebSocket/polling notifications) empêcherait sinon cette attente de se
    // résoudre, et sans timeout propre elle se contente de consommer le budget
    // du hook appelant (beforeAll) jusqu'à son propre timeout générique — un
    // échec "hook timeout exceeded" opaque au lieu d'une erreur localisée ici.
    await page
        .waitForLoadState('networkidle', { timeout: 15_000 })
        .catch(() => undefined);

    const firstRow = page
        .locator('tbody tr:not(.p-datatable-emptymessage)')
        .first();
    await expect(firstRow).toBeVisible({ timeout: 10_000 });
    await openRowActions(firstRow);

    await page
        .getByRole('menuitem', { name: /^voir$/i })
        .first()
        .click({ timeout: 10_000 });
    await page.waitForURL(/\/sites\/[a-z0-9]+$/, { timeout: 15_000 });

    const siteUrl = page.url();

    await page.getByTestId('site-vehicles-tab').click({ timeout: 10_000 });
    await expect(page.getByTestId('add-site-vehicle-btn')).toBeVisible({
        timeout: 10_000,
    });

    return siteUrl;
}

export async function ensureModuleEnabled(
    page: Page,
    moduleKey: string,
): Promise<void> {
    await page.goto('/settings/modules');
    await expect(page).toHaveURL(/\/settings\/modules$/);

    const row = page
        .locator('div.divide-y > div', { hasText: moduleKey })
        .first();

    await expect(row).toBeVisible({ timeout: 15_000 });

    const toggle = row.getByRole('switch').first();
    await expect(toggle).toBeVisible({ timeout: 10_000 });

    const current = await toggle.getAttribute('aria-checked');
    if (current !== 'true') {
        await toggle.click({ timeout: 5_000 });
        await expect(toggle).toHaveAttribute('aria-checked', 'true', {
            timeout: 15_000,
        });
    }
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
    const formComboboxes = form.getByRole('combobox');

    await selectOptionFromCombobox(
        page,
        formComboboxes.first(),
        /guin(?!.*bissau)/i,
    );
    await page.locator('#prenom').fill(prenom);
    await page.locator('#nom').fill(nom);
    await page.locator('#telephone').fill(tel);
    if (email) {
        await page.locator('#email').fill(email);
    }

    const roleComboboxByText = formComboboxes
        .filter({
            hasText:
                /choisir un role|role|manager|comptable|commercial|administrateur/i,
        })
        .first();
    const roleCombobox =
        (await roleComboboxByText.count()) > 0
            ? roleComboboxByText
            : formComboboxes.nth(1);

    await selectOptionFromCombobox(page, roleCombobox, role);

    const siteComboboxByText = formComboboxes
        .filter({ hasText: /choisir un site|site/i })
        .first();
    if ((await siteComboboxByText.count()) > 0) {
        await selectOptionFromCombobox(page, siteComboboxByText);
    } else if ((await formComboboxes.count()) >= 3) {
        await selectOptionFromCombobox(page, formComboboxes.nth(2));
    }

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
    await page.goto('/backoffice/users/create');
    await fillUserInfoAndAdvance(page, { prenom, nom, tel, email, role });
    await page.locator('#password').fill(password);
    await page.locator('#password_confirmation').fill(password);
    await page
        .locator('#user-form button[type="submit"]:visible')
        .first()
        .click();
    await expect(page).toHaveURL(/\/users\/[a-z0-9]+\/edit$/);
}

export async function findUserInList(
    page: Page,
    query: string,
): Promise<Locator> {
    await page.goto('/backoffice/users');
    const search = await getVisibleSearchInput(page);
    await search.fill(query);
    await search.press('Enter');
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
    const search = await getVisibleSearchInput(page);
    await search.fill(name);
    await search.press('Enter');
    await page.waitForLoadState('networkidle');
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

    const guard = new RegExp(escapeRegExp(prefix), 'i');

    for (let i = 0; i < 6; i++) {
        // Récupéré à CHAQUE itération (jamais réutilisé d'un tour à l'autre) : sur les
        // pages migrées vers DataFilters trigger-only, `getVisibleSearchInput` doit
        // rouvrir le drawer Filtres — un `Locator` scopé au drawer capturé une seule
        // fois avant la boucle redevient invisible dès que la suppression précédente
        // recharge la page (le drawer se referme), et un `.fill()` sans timeout dessus
        // attend alors indéfiniment, bloqué jusqu'au timeout du hook `afterEach` (cause
        // confirmée des shards E2E CI qui expiraient silencieusement, cf. historique de
        // réactivation de la suite complète).
        const searchInput = await getVisibleSearchInput(page);
        await searchInput.fill(prefix);
        await searchInput.press('Enter');
        await page.waitForLoadState('networkidle');

        const row = page
            .locator('tbody tr:has(button)', { hasText: guard })
            .first();

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
    }
}
