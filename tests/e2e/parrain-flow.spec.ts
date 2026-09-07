import { expect, test, type Page } from '@playwright/test';
import {
    login,
    navigateToFirstSiteVehiclesTab,
    randomDigits,
    selectOptionFromCombobox,
} from './helpers';

test.setTimeout(120_000);

/**
 * Parrainage véhicule — phase 1 (cf. docs/parrainage-vehicule.md). Le point métier central à
 * couvrir en E2E est celui explicitement demandé par l'audit : la recherche par téléphone AVANT
 * création évite tout doublon de Personne, et une même Personne peut parrainer plusieurs
 * véhicules. Un seul test enchaîne les deux véhicules pour ne pas dépendre de l'ordre
 * d'exécution entre fichiers/tests (aucun helper de nettoyage par téléphone n'existe pour
 * Personne, contrairement aux véhicules).
 */

async function creerVehicule(page: Page, nomVehicule: string, immatriculation: string): Promise<void> {
    await navigateToFirstSiteVehiclesTab(page);
    await page.getByTestId('add-site-vehicle-btn').click();
    await page.waitForURL(/\/vehicules\/create\?site_id=/, { timeout: 15_000 });

    await page.locator('#nom_vehicule').fill(nomVehicule);
    await page.locator('#immatriculation').fill(immatriculation);
    await selectOptionFromCombobox(page, page.locator('#type_vehicule'));
    await page.getByRole('checkbox', { name: /livraison vente/i }).check();

    const submitBtn = page.getByTestId('vehicle-form-submit');
    await expect(submitBtn).toBeEnabled();
    await submitBtn.click();

    await page.waitForURL(/\/vehicules\/[a-z0-9]+$/, { timeout: 15_000 });
}

test('parrainage véhicule : création (téléphone inconnu) puis réutilisation sans doublon sur un second véhicule', async ({
    page,
}) => {
    const unique = `${Date.now()}-${randomDigits(3)}`;
    const telephoneParrain = `6${randomDigits(8)}`;
    const nomParrain = `E2E Parrain ${unique.slice(-6)}`;

    await login(page);

    // ── Véhicule A : aucun parrain connu → recherche négative → création ────────
    await creerVehicule(page, `E2E VH Parrain A ${unique}`, `E2EPR-${unique.slice(-6)}A`);

    await page.getByTestId('parrain-tab-btn').click();
    await expect(page.getByText('Aucun parrain associé à ce véhicule.')).toBeVisible();

    await page.getByTestId('ajouter-parrain-btn').click();
    await expect(page.getByRole('dialog')).toBeVisible();

    await page
        .getByTestId('parrain-recherche-telephone-input')
        .fill(telephoneParrain);
    await page.getByTestId('parrain-rechercher-btn').click();
    await expect(page.getByText('Aucune personne trouvée avec ce numéro.')).toBeVisible({
        timeout: 10_000,
    });

    await page.getByTestId('parrain-creer-personne-btn').click();
    await page.getByTestId('parrain-creation-nom-input').fill(nomParrain);
    await page.getByTestId('parrain-creer-et-associer-btn').click();

    await page.waitForURL(/\/vehicules\/[a-z0-9]+$/, { timeout: 15_000 });
    await expect(page.getByTestId('parrain-nom')).toHaveText(nomParrain);
    await expect(page.getByTestId('parrain-telephone')).toContainText(
        telephoneParrain.slice(-4),
    );

    // ── Véhicule B : même téléphone → doit retrouver le parrain A, pas en créer un second ──
    await creerVehicule(page, `E2E VH Parrain B ${unique}`, `E2EPR-${unique.slice(-6)}B`);

    await page.getByTestId('parrain-tab-btn').click();
    await page.getByTestId('ajouter-parrain-btn').click();

    await page
        .getByTestId('parrain-recherche-telephone-input')
        .fill(telephoneParrain);
    await page.getByTestId('parrain-rechercher-btn').click();

    // Personne retrouvée : son identité (créée sur le véhicule A) est affichée telle quelle.
    await expect(page.getByText(nomParrain).first()).toBeVisible({ timeout: 10_000 });
    await expect(page.getByText(/existe déjà dans l.organisation/)).toBeVisible();

    await page.getByTestId('parrain-utiliser-personne-btn').click();
    await page.waitForURL(/\/vehicules\/[a-z0-9]+$/, { timeout: 15_000 });

    await expect(page.getByTestId('parrain-nom')).toHaveText(nomParrain);
});
