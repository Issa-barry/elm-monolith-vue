import { expect, test, type APIResponse, type Page } from '@playwright/test';
import {
    escapeRegExp,
    findRowByName,
    getVisibleSearchInput,
    login,
    randomDigits,
} from './helpers';

const PREFIX = 'e2elivflow';

test.setTimeout(180_000);

type HttpMethod = 'POST' | 'PATCH' | 'DELETE';

interface LivreurCreatePayload {
    prenom: string;
    nom: string;
    telephone: string;
}

async function getCsrfToken(page: Page): Promise<string> {
    // app.blade.php ne contient pas de <meta name="csrf-token">.
    // Laravel expose le token via le cookie XSRF-TOKEN (VerifyCsrfToken middleware).
    const readCookie = async () => {
        const cookies = await page.context().cookies();
        return cookies.find((c) => c.name === 'XSRF-TOKEN')?.value;
    };

    let encoded = await readCookie();
    if (!encoded) {
        // Forcer une navigation pour initialiser la session/cookie si nécessaire.
        await page.goto('/livreurs');
        encoded = await readCookie();
    }

    if (!encoded) {
        throw new Error('XSRF-TOKEN cookie not found. Ensure login() was called first.');
    }

    // decodeURIComponent convertit l'encodage URL du cookie.
    return decodeURIComponent(encoded);
}

async function sendLivreurRequest(
    page: Page,
    method: HttpMethod,
    url: string,
    payload?: Record<string, unknown>,
): Promise<APIResponse> {
    const csrfToken = await getCsrfToken(page);

    return page.request.fetch(url, {
        method,
        data: payload,
        headers: {
            Accept: 'application/json',
            // Laravel accepte X-XSRF-TOKEN (valeur du cookie décodée).
            'X-XSRF-TOKEN': csrfToken,
            'X-Requested-With': 'XMLHttpRequest',
        },
    });
}

async function createLivreurViaApi(
    page: Page,
    payload: LivreurCreatePayload,
): Promise<number> {
    const response = await sendLivreurRequest(page, 'POST', '/livreurs', payload);
    expect(response.status()).toBe(201);

    const body = await response.json();
    expect(body.id).toBeTruthy();

    return Number(body.id);
}

async function toggleLivreurViaApi(page: Page, livreurId: number): Promise<boolean> {
    const response = await sendLivreurRequest(
        page,
        'PATCH',
        `/livreurs/${livreurId}/toggle`,
    );
    expect(response.ok()).toBeTruthy();

    const body = await response.json();
    return Boolean(body.is_active);
}

async function deleteLivreurViaApi(page: Page, livreurId: number): Promise<void> {
    const response = await sendLivreurRequest(page, 'DELETE', `/livreurs/${livreurId}`);
    expect(response.ok()).toBeTruthy();
}

test('create livreur with all fields -> verify in list', async ({ page }) => {
    const uid = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom = `Flow${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);
    const livreurId = await createLivreurViaApi(page, {
        prenom,
        nom,
        telephone: tel,
    });

    await page.goto('/livreurs');
    try {
        const row = await findRowByName(page, prenom);
        await expect(row).toBeVisible();
        await expect(row).toContainText(/actif/i);
    } finally {
        await deleteLivreurViaApi(page, livreurId);
    }
});

test('toggle livreur status via API -> data persists in list', async ({ page }) => {
    const uid = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom = `Edit${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);
    const livreurId = await createLivreurViaApi(page, {
        prenom,
        nom,
        telephone: tel,
    });

    try {
        const isActive = await toggleLivreurViaApi(page, livreurId);
        expect(isActive).toBe(false);

        await page.goto('/livreurs');
        const updatedRow = await findRowByName(page, prenom);
        await expect(updatedRow).toBeVisible();
        await expect(updatedRow).toContainText(/inactif/i);

        await page.reload();
        const persistedRow = await findRowByName(page, prenom);
        await expect(persistedRow).toContainText(/inactif/i);
    } finally {
        await deleteLivreurViaApi(page, livreurId);
    }
});

test('delete livreur via API -> removed from list', async ({ page }) => {
    const uid = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom = `Del${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);
    const livreurId = await createLivreurViaApi(page, {
        prenom,
        nom,
        telephone: tel,
    });

    await page.goto('/livreurs');
    const createdRow = await findRowByName(page, prenom);
    await expect(createdRow).toBeVisible();

    await deleteLivreurViaApi(page, livreurId);

    await page.goto('/livreurs');
    const search = getVisibleSearchInput(page);
    await search.fill(prenom);

    await expect(
        page.locator('tbody tr', {
            hasText: new RegExp(escapeRegExp(prenom), 'i'),
        }),
    ).toHaveCount(0);
});

test('create livreur without required fields -> returns validation errors', async ({
    page,
}) => {
    await login(page);

    const response = await sendLivreurRequest(page, 'POST', '/livreurs', {
        prenom: '',
        nom: '',
        telephone: '',
    });

    expect(response.status()).toBe(422);
    const body = await response.json();

    expect(body.errors).toBeTruthy();
    expect(body.errors.nom).toBeTruthy();
    expect(body.errors.prenom).toBeTruthy();
    expect(body.errors.telephone).toBeTruthy();
});

