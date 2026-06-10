import { expect, test, type Page } from '@playwright/test';
import { login } from './helpers';

test.setTimeout(180_000);

async function getChargementToggle(page: Page) {
    const section = page
        .locator('.overflow-hidden')
        .filter({ hasText: /quantite de chargement/i })
        .first();
    await expect(section).toBeVisible({ timeout: 10_000 });
    return section.getByRole('switch').first();
}

async function setChargementCompletRequired(
    page: Page,
    required: boolean,
): Promise<void> {
    await page.goto('/settings/ventes');
    await expect(page).toHaveURL(/\/settings\/ventes$/, { timeout: 20_000 });

    const toggle = await getChargementToggle(page);
    await expect(toggle).toBeVisible({ timeout: 10_000 });

    const checked = (await toggle.getAttribute('aria-checked')) === 'true';
    // toggle ON = autoriser partiel, toggle OFF = chargement complet requis
    const wantOn = !required;
    if (checked !== wantOn) {
        await toggle.click();
        await expect(toggle).toHaveAttribute(
            'aria-checked',
            wantOn ? 'true' : 'false',
            { timeout: 5_000 },
        );

        await page.getByRole('button', { name: /enregistrer/i }).last().click();
        await expect(page.locator('body')).toContainText(/mis a jour/i, {
            timeout: 10_000,
        });
    }
}

async function selectFirstVehiculeAndGetCapacity(
    page: Page,
): Promise<number | null> {
    const autocomplete = page.locator('#vente-form .p-autocomplete').first();
    await expect(autocomplete).toBeVisible({ timeout: 15_000 });
    await autocomplete.locator('button').first().click();

    const firstOption = page.locator('[role="option"]:visible').first();
    await expect(firstOption).toBeVisible({ timeout: 10_000 });
    await firstOption.click();

    // Attendre l'apparition du hint de capacité
    const hint = page.locator('text=/Capacit.*pack/i').first();
    const hintVisible = await hint
        .isVisible({ timeout: 5_000 })
        .catch(() => false);
    if (!hintVisible) return null;

    const text = await hint.innerText().catch(() => '');
    const match = text.match(/(\d+)\s*packs?\s*·/);
    return match ? parseInt(match[1], 10) : null;
}

test.describe('Paramétrage chargement complet', () => {
    test.afterEach(async ({ browser }) => {
        // Restaurer le paramètre à true (autoriser partiel) après chaque test
        const context = await browser.newContext();
        try {
            const p = await context.newPage();
            await login(p);
            await setChargementCompletRequired(p, false);
        } catch {
            // ne pas faire échouer le suite si le cleanup plante
        } finally {
            await context.close().catch(() => undefined);
        }
    });

    test('le toggle "Quantite de chargement" est visible dans les settings', async ({
        page,
    }) => {
        await login(page);
        await page.goto('/settings/ventes');
        await expect(page).toHaveURL(/\/settings\/ventes$/, {
            timeout: 20_000,
        });

        const toggle = await getChargementToggle(page);
        await expect(toggle).toBeVisible();
        await expect(toggle).toHaveAttribute('aria-checked', /true|false/);
    });

    test('le toggle peut être désactivé et la valeur est persistée', async ({
        page,
    }) => {
        await login(page);
        await setChargementCompletRequired(page, true); // désactiver

        // Recharger la page et vérifier la persistance
        await page.goto('/settings/ventes');
        await expect(page).toHaveURL(/\/settings\/ventes$/, {
            timeout: 20_000,
        });

        const toggle = await getChargementToggle(page);
        await expect(toggle).toHaveAttribute('aria-checked', 'false', {
            timeout: 10_000,
        });
    });

    test('quand désactivé, le bouton soumettre est bloqué avec qte partielle', async ({
        page,
    }) => {
        await login(page);
        await setChargementCompletRequired(page, true); // chargement complet requis

        await page.goto('/ventes/create');
        await expect(page).toHaveURL(/\/ventes\/create$/, {
            timeout: 20_000,
        });

        const capacity = await selectFirstVehiculeAndGetCapacity(page);

        if (capacity === null || capacity <= 1) {
            test.skip();
            return;
        }

        // Avec qty = capacité auto-remplie → bouton doit être actif
        const submitBtn = page
            .locator('#vente-form button[type="submit"]:visible')
            .first();
        await expect(submitBtn).toBeEnabled({ timeout: 5_000 });

        // Réduire la qté à 1 (en dessous de la capacité)
        const qteInput = page
            .locator('table tbody tr')
            .first()
            .locator('input')
            .first();
        await expect(qteInput).toBeVisible({ timeout: 5_000 });
        await qteInput.fill('1');
        await qteInput.press('Tab');

        // Le bouton doit être désactivé
        await expect(submitBtn).toBeDisabled({ timeout: 5_000 });

        // Le message d'erreur doit mentionner le chargement complet
        await expect(page.locator('body')).toContainText(
            /chargement complet requis/i,
            { timeout: 3_000 },
        );
    });

    test('quand activé, le bouton soumettre reste actif avec qte partielle', async ({
        page,
    }) => {
        await login(page);
        await setChargementCompletRequired(page, false); // autoriser partiel

        await page.goto('/ventes/create');
        await expect(page).toHaveURL(/\/ventes\/create$/, {
            timeout: 20_000,
        });

        const capacity = await selectFirstVehiculeAndGetCapacity(page);

        if (capacity === null || capacity <= 1) {
            test.skip();
            return;
        }

        // Réduire la qté à 1
        const qteInput = page
            .locator('table tbody tr')
            .first()
            .locator('input')
            .first();
        await expect(qteInput).toBeVisible({ timeout: 5_000 });
        await qteInput.fill('1');
        await qteInput.press('Tab');

        // Le bouton doit rester actif (partiel autorisé)
        const submitBtn = page
            .locator('#vente-form button[type="submit"]:visible')
            .first();
        await expect(submitBtn).toBeEnabled({ timeout: 5_000 });
    });
});
