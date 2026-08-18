import { expect, test, type Page } from '@playwright/test';
import {
    login,
    randomDigits,
    registerCleanup,
    selectOptionFromCombobox,
} from './helpers';

const PREFIX = 'e2eimpayes';

test.setTimeout(180_000);

registerCleanup('/backoffice/clients', PREFIX);
registerCleanup('/backoffice/vehicules', PREFIX);

// ── Paramètres > Ventes — contrôle des impayés ──────────────────────────────

async function getImpayesToggle(page: Page) {
    const section = page
        .locator('.overflow-hidden')
        .filter({ hasText: /controle des impayes/i })
        .first();
    await expect(section).toBeVisible({ timeout: 10_000 });
    return section.getByRole('switch').first();
}

/**
 * Configure explicitement le contrôle des impayés (actif + seuil) via l'écran Paramètres >
 * Ventes — jamais en supposant l'état par défaut, pour que ce test reste indépendant de
 * l'ordre d'exécution des autres specs E2E (même principe que setChargementCompletRequired
 * dans vente-parametrage-chargement.spec.ts).
 */
async function setImpayesControle(
    page: Page,
    actif: boolean,
    seuil: number,
): Promise<void> {
    await page.goto('/settings/ventes');
    await expect(page).toHaveURL(/\/settings\/ventes$/, { timeout: 20_000 });

    const toggle = await getImpayesToggle(page);
    const checked = (await toggle.getAttribute('aria-checked')) === 'true';
    const seuilInput = page.locator('#seuil-impayes-input');
    const currentSeuil = actif
        ? await seuilInput.inputValue().catch(() => '')
        : '';
    const seuilDejaBon =
        !actif || currentSeuil.replace(/\D/g, '') === String(seuil);

    // Rien à faire si l'état visé est déjà en place — comme setChargementCompletRequired
    // dans vente-parametrage-chargement.spec.ts, évite une soumission (et son aller-retour
    // réseau) inutile à chaque nettoyage post-test.
    if (checked === actif && seuilDejaBon) {
        return;
    }

    if (checked !== actif) {
        await toggle.click();
        await expect(toggle).toHaveAttribute(
            'aria-checked',
            actif ? 'true' : 'false',
            { timeout: 5_000 },
        );
    }

    if (actif) {
        await seuilInput.click();
        await seuilInput.fill(String(seuil));
        await seuilInput.blur();
    }

    await page.getByRole('button', { name: /enregistrer/i }).last().click();
    await expect(page.locator('body')).toContainText(/mis a jour/i, {
        timeout: 10_000,
    });
}

test.describe('Paramètres > Ventes — défauts contrôle des impayés et commissions', () => {
    test.afterEach(async ({ page }) => {
        // Restaure les défauts (actif, seuil 0) après chaque test de ce describe pour ne
        // jamais laisser un seuil de test polluer le reste de la suite E2E. Réutilise la
        // page déjà authentifiée du test (storageState global, cf. playwright.config.ts) au
        // lieu d'un nouveau contexte + login à froid — plus simple et plus fiable qu'un
        // second cycle d'authentification complet juste pour un nettoyage.
        try {
            await setImpayesControle(page, true, 0);
        } catch {
            // ne pas faire échouer la suite si le cleanup plante
        }
    });

    test('affiche le contrôle des impayés actif et le seuil à 0 par défaut', async ({
        page,
    }) => {
        await login(page);
        await page.goto('/settings/ventes');
        await expect(page).toHaveURL(/\/settings\/ventes$/, {
            timeout: 20_000,
        });

        const toggle = await getImpayesToggle(page);
        await expect(toggle).toHaveAttribute('aria-checked', 'true', {
            timeout: 10_000,
        });

        const seuilInput = page.locator('#seuil-impayes-input');
        await expect(seuilInput).toHaveAttribute('placeholder', '0');
    });

    test('affiche "À l\'encaissement de la facture" comme commission de vente par défaut', async ({
        page,
    }) => {
        await login(page);
        await page.goto('/settings/ventes');
        await expect(page).toHaveURL(/\/settings\/ventes$/, {
            timeout: 20_000,
        });

        await expect(
            page.locator('body'),
        ).toContainText(/l'encaissement de la facture/i, { timeout: 10_000 });
        await expect(page.locator('body')).toContainText(/à la réception/i, {
            timeout: 10_000,
        });
    });

    test('le seuil configuré est persisté après rechargement', async ({
        page,
    }) => {
        await login(page);
        await setImpayesControle(page, true, 1_500_000);

        await page.goto('/settings/ventes');
        await expect(page).toHaveURL(/\/settings\/ventes$/, {
            timeout: 20_000,
        });

        // Intl.NumberFormat('fr-FR') sépare les milliers par une espace fine insécable
        // (U+202F), pas une espace normale — un littéral "1 500 000" ne matcherait jamais.
        const seuilInput = page.locator('#seuil-impayes-input');
        await expect(seuilInput).toHaveValue(/^1\D500\D000$/, {
            timeout: 10_000,
        });
    });
});

// ── Véhicule — seuil de dette spécifique (dérogation) ───────────────────────

async function createVehiculeInApp(
    page: Page,
    nomVehicule: string,
    immatriculation: string,
): Promise<void> {
    await page.goto('/backoffice/vehicules/create');
    await expect(page).toHaveURL(/\/vehicules\/create$/, { timeout: 20_000 });

    await page.locator('#nom_vehicule').fill(nomVehicule);
    await page.locator('#immatriculation').fill(immatriculation);
    await selectOptionFromCombobox(page, page.locator('#type_vehicule'));
    if (
        await page
            .locator('#site_id')
            .isVisible()
            .catch(() => false)
    ) {
        await selectOptionFromCombobox(page, page.locator('#site_id'));
    }
    await page.getByRole('checkbox', { name: /livraison vente/i }).check();

    await page.getByTestId('vehicle-form-submit').click();
    await page.waitForURL(/\/vehicules\/[a-z0-9]+$/, { timeout: 15_000 });
}

test.describe('Véhicule — seuil de dette spécifique', () => {
    test('un véhicule sans seuil spécifique affiche le rappel du seuil global (héritage)', async ({
        page,
    }) => {
        await login(page);
        const unique = `${Date.now()}-${randomDigits(3)}`;

        await createVehiculeInApp(
            page,
            `${PREFIX} Seuil ${unique}`,
            `E2EIMP-A-${unique.slice(-5)}`,
        );
        await page.goto(`${page.url()}/edit`);
        await expect(page).toHaveURL(/\/vehicules\/[a-z0-9]+\/edit$/, {
            timeout: 15_000,
        });
        await page.waitForLoadState('networkidle');

        await expect(page.locator('body')).toContainText(
            /seuil de dette spécifique/i,
            { timeout: 15_000 },
        );
        await expect(page.locator('body')).toContainText(
            /seuil global actuel/i,
            { timeout: 10_000 },
        );
        const seuilVehiculeInput = page.locator('#seuil_dette_derogation');
        await expect(seuilVehiculeInput).toHaveValue('');
    });

    test('configurer un seuil spécifique sur un véhicule persiste après rechargement', async ({
        page,
    }) => {
        await login(page);
        const unique = `${Date.now()}-${randomDigits(3)}`;

        await createVehiculeInApp(
            page,
            `${PREFIX} Derogation ${unique}`,
            `E2EIMP-B-${unique.slice(-5)}`,
        );
        const showUrl = page.url();

        await page.goto(`${showUrl}/edit`);
        await expect(page).toHaveURL(/\/vehicules\/[a-z0-9]+\/edit$/, {
            timeout: 15_000,
        });
        await page.waitForLoadState('networkidle');

        // PrimeVue InputNumber : click() -> pressSequentially() -> blur() pour committer le
        // v-model (fill() seul pose la valeur DOM sans déclencher le parsing interne).
        const seuilVehiculeInput = page.locator('#seuil_dette_derogation');
        await seuilVehiculeInput.click();
        await seuilVehiculeInput.pressSequentially('2000000');
        await seuilVehiculeInput.blur();

        await page
            .locator('#vehicule-form button[type="submit"]:visible')
            .first()
            .click();
        await expect(page.locator('body')).toContainText(/mis à jour/i, {
            timeout: 10_000,
        });

        await page.reload();
        await expect(page.locator('#seuil_dette_derogation')).toHaveValue(
            '2000000',
            { timeout: 10_000 },
        );
    });
});

// ── Blocage réel d'une vente au-delà du seuil ───────────────────────────────
// Scénario porté sur une vente CLIENT (sans véhicule) : c'est le seul chemin qui facture
// immédiatement en IMPAYEE dès la création (CommandeVenteService::creerFactureDirecte()) —
// une vente rattachée à un véhicule ne bascule sa facture en IMPAYEE qu'à la validation du
// chargement, une étape supplémentaire hors du périmètre de ce scénario. La dérogation
// spécifique à un véhicule (seuil_dette_derogation) applique EXACTEMENT la même règle de
// blocage — couverte en profondeur par tests/Feature/SolvabiliteImpayesTest.php et
// tests/Unit/SolvabiliteServiceTest.php.

async function createClientInApp(page: Page, nomComplet: string, tel: string): Promise<void> {
    await page.goto('/backoffice/clients/create');
    await page.locator('#nom_complet').fill(nomComplet);

    const paysCombo = page.locator('#client-form').getByRole('combobox').first();
    await selectOptionFromCombobox(page, paysCombo, /guin(?!.*bissau)/i);

    await page.locator('#telephone').fill(tel);
    await page
        .locator('#client-form button[type="submit"]:visible')
        .first()
        .click();
    await expect(page).toHaveURL(/\/clients\/[a-z0-9]+\/edit$/, { timeout: 15_000 });
}

/**
 * Sélectionne le client puis attend la résolution de l'appel check-solvabilite qu'il
 * déclenche (onClientSelect) — sans ce wait, les assertions suivantes (bouton
 * désactivé, message impayés) peuvent s'exécuter avant que `clientSolvabilite` ne soit
 * mis à jour côté client, où `blocked` retombe silencieusement sur `false` par défaut
 * (cf. Ventes/Create.vue, `clientSolvabilite.value?.blocked ?? false`).
 */
async function selectClientOnVenteForm(page: Page, nomComplet: string): Promise<void> {
    const input = page.getByPlaceholder('Nom, prénom, téléphone…');
    await input.click();
    await input.fill(nomComplet);
    const option = page
        .locator('[role="option"]:visible', { hasText: nomComplet })
        .first();
    await expect(option).toBeVisible({ timeout: 10_000 });

    const solvabiliteResponse = page.waitForResponse(
        (res) =>
            res.url().includes('/ventes/check-solvabilite') &&
            res.url().includes('client_id='),
        { timeout: 15_000 },
    );
    await option.click();
    await solvabiliteResponse;
}

test('vente client sous le seuil autorisée, la même dette dépassant un seuil abaissé bloque une nouvelle vente', async ({
    page,
}) => {
    await login(page);
    const unique = `${Date.now()}-${randomDigits(3)}`;
    const nomClient = `${PREFIX} Client ${unique}`;

    await createClientInApp(page, nomClient, randomDigits(9));

    // Seuil large : la première vente (qui facture immédiatement en IMPAYEE) doit passer.
    await setImpayesControle(page, true, 10_000_000);

    await page.goto('/backoffice/ventes/create');
    await expect(page).toHaveURL(/\/ventes\/create$/, { timeout: 20_000 });
    await selectClientOnVenteForm(page, nomClient);

    await page
        .locator('#vente-form button[type="submit"]:visible')
        .first()
        .click();
    await expect(page).toHaveURL(/\/ventes\/[a-z0-9]+$/, { timeout: 15_000 });

    // Seuil abaissé à 0 : la dette de la vente précédente dépasse désormais le seuil — toute
    // nouvelle vente pour ce même client doit être bloquée.
    await setImpayesControle(page, true, 0);

    await page.goto('/backoffice/ventes/create');
    await expect(page).toHaveURL(/\/ventes\/create$/, { timeout: 20_000 });
    await selectClientOnVenteForm(page, nomClient);

    await expect(page.locator('body')).toContainText(/impay/i, {
        timeout: 10_000,
    });
    const submitBtn = page
        .locator('#vente-form button[type="submit"]:visible')
        .first();
    await expect(submitBtn).toBeDisabled({ timeout: 10_000 });
});
