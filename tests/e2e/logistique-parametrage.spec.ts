import { expect, test, type Page } from '@playwright/test';
import { login } from './helpers';

test.setTimeout(180_000);

/**
 * Depuis la séparation de cet écran de Paramètres > Ventes (cf. docs/commissions.md), ces
 * réglages n'ont plus aucune couverture E2E — seul tests/Feature/Settings/
 * LogistiqueParametrageTest.php les exerce côté backend. Ce fichier couvre le rendu et la
 * persistance côté UI, même principe que vente-parametrage-chargement.spec.ts.
 */

async function getApprobationToggle(page: Page) {
    const section = page
        .locator('.overflow-hidden')
        .filter({ hasText: /approbation des réceptions/i })
        .first();
    await expect(section).toBeVisible({ timeout: 10_000 });
    return section.getByRole('switch').first();
}

async function setApprobationObligatoire(
    page: Page,
    obligatoire: boolean,
): Promise<void> {
    await page.goto('/settings/logistique');
    await expect(page).toHaveURL(/\/settings\/logistique$/, {
        timeout: 20_000,
    });

    const toggle = await getApprobationToggle(page);
    const checked = (await toggle.getAttribute('aria-checked')) === 'true';
    if (checked === obligatoire) {
        return;
    }

    await toggle.click();
    await expect(toggle).toHaveAttribute(
        'aria-checked',
        obligatoire ? 'true' : 'false',
        { timeout: 5_000 },
    );

    await page.getByRole('button', { name: /enregistrer/i }).last().click();
    // Flash backend non accentué (cf. LogistiqueParametrageController::update()) — même
    // pattern que setImpayesControle()/setChargementCompletRequired() sur les autres écrans
    // de Paramètres > Ventes.
    await expect(page.locator('body')).toContainText(/mis a jour/i, {
        timeout: 10_000,
    });
}

test.describe('Paramètres > Logistique — déclencheur et approbation', () => {
    test.afterEach(async ({ page }) => {
        // Restaure le défaut historique (approbation obligatoire) après chaque test — même
        // principe que setImpayesControle dans vente-controle-impayes-flow.spec.ts.
        try {
            await setApprobationObligatoire(page, true);
        } catch {
            // ne pas faire échouer la suite si le cleanup plante
        }
    });

    test('affiche les 2 options du déclencheur de commission logistique', async ({
        page,
    }) => {
        await login(page);
        await page.goto('/settings/logistique');
        await expect(page).toHaveURL(/\/settings\/logistique$/, {
            timeout: 20_000,
        });

        await expect(page.locator('body')).toContainText(
            /à la validation du chargement/i,
            { timeout: 10_000 },
        );
        await expect(page.locator('body')).toContainText(/à la réception/i, {
            timeout: 10_000,
        });
    });

    test('le toggle "Approbation des réceptions" peut être désactivé et la valeur est persistée', async ({
        page,
    }) => {
        await login(page);
        await setApprobationObligatoire(page, false);

        await page.goto('/settings/logistique');
        await expect(page).toHaveURL(/\/settings\/logistique$/, {
            timeout: 20_000,
        });

        const toggle = await getApprobationToggle(page);
        await expect(toggle).toHaveAttribute('aria-checked', 'false', {
            timeout: 10_000,
        });
    });
});
