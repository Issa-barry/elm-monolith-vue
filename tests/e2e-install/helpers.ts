/**
 * Helpers spécifiques au parcours /install → /onboarding/site → back-office, testé de bout en
 * bout sur une base fraîchement migrée (jamais seedée, cf. playwright.install.config.ts).
 *
 * Vérification email : formulaire principal (prénom/nom/téléphone/email/mot de passe) rempli en
 * entier, puis "Suivant" bascule — uniquement si un email est renseigné — vers un état dédié
 * "Vérifiez votre email" (OtpCodeInput, repris du parcours Invitation) avant de continuer
 * automatiquement vers l'étape Domaine d'activité.
 */
import { expect, type Page } from '@playwright/test';
import { fillLoginIdentifier } from '../e2e/helpers';

export interface InstallScenario {
    orgNom: string;
    prenom: string;
    nom: string;
    countryLabel?: 'Guinée' | 'Sierra Leone'; // Guinée = déjà sélectionnée par défaut
    localDigits: string;
    fullPhone: string; // pour la reconnexion (fillLoginIdentifier)
    email?: string;
    password: string;
    domaineLabel: string;
    siteVille: string;
    siteQuartier: string;
}

const OTP_FIXED_CODE = '123456'; // .env.e2e fixe OTP_FIXED_CODE=123456 (cf. config/otp.php)

/** Case de saisie du code (composant OtpCodeInput, sans id — ciblée par sa forme). */
function firstOtpBox(page: Page) {
    return page.locator('input[maxlength="1"]').first();
}

/** Remplit prénom/nom/téléphone/(email)/mot de passe et clique "Suivant". */
async function fillStep2Form(page: Page, scenario: InstallScenario): Promise<void> {
    await page.locator('#admin-prenom').fill(scenario.prenom);
    await page.locator('#admin-nom').fill(scenario.nom);

    if (scenario.countryLabel === 'Sierra Leone') {
        // Sélecteur pays restreint à Guinée/Sierra Leone (PAYS_INSTALL, Wizard.vue) — la
        // Guinée est déjà sélectionnée par défaut, donc seul ce cas ouvre le dropdown.
        // #admin-telephone (le champ numéro) et le Select pays sont frères, dans le même
        // conteneur `.flex.gap-2` (cf. PhoneCountryInput.vue).
        await page
            .locator('#admin-telephone')
            .locator('..')
            .getByRole('combobox')
            .click();
        await page.getByRole('option', { name: /sierra leone/i }).click();
    }

    await page.locator('#admin-telephone').fill(scenario.localDigits);
    await expect(page.getByText(/format du numéro valide/i)).toBeVisible({
        timeout: 15_000,
    });

    if (scenario.email) {
        await page.locator('#admin-email').fill(scenario.email);
    }

    await page.locator('#admin-password').fill(scenario.password);
    // Le mot de passe est saisi une seule fois — aucun champ de confirmation nulle part, et
    // aucune case de code OTP visible tant que "Suivant" n'a pas été cliqué.
    await expect(page.locator('#admin-password-confirmation')).toHaveCount(0);
    await expect(page.locator('input[maxlength="1"]')).toHaveCount(0);
}

/**
 * Étape 1 (Entreprise) → étape 2 (Super Admin, avec vérification email optionnelle) → étape 3
 * (Domaine) → étape 4 (Résumé) → soumission. S'arrête sur Install/Success.
 */
export async function completeInstallWizard(
    page: Page,
    scenario: InstallScenario,
): Promise<void> {
    await page.goto('/install');
    await expect(
        page.getByRole('heading', { name: /installation de l'application/i }),
    ).toBeVisible({ timeout: 20_000 });

    // ── Étape 1 : Entreprise ──────────────────────────────────────────────────
    await page.locator('#org-nom').fill(scenario.orgNom);
    await page.getByRole('button', { name: /suivant/i }).click();

    // ── Étape 2 : Super Admin ─────────────────────────────────────────────────
    await fillStep2Form(page, scenario);
    await page.getByRole('button', { name: /suivant|envoi du code/i }).click();

    if (scenario.email) {
        // ── Sous-état : vérification de l'email ──────────────────────────────
        await expect(
            page.getByRole('heading', { name: /vérifiez votre email/i }),
        ).toBeVisible({ timeout: 15_000 });
        await expect(page.getByText(scenario.email)).toBeVisible();

        await firstOtpBox(page).fill(OTP_FIXED_CODE);
        await page.getByRole('button', { name: /^vérifier$/i }).click();
        await expect(page.getByText(/adresse email vérifiée/i)).toBeVisible({
            timeout: 15_000,
        });
    }

    // Auto-avance (avec ou sans email) jusqu'à l'étape Domaine d'activité.
    await expect(
        page.getByRole('button', { name: new RegExp(scenario.domaineLabel, 'i') }),
    ).toBeVisible({ timeout: 15_000 });

    // ── Étape 3 : Domaine d'activité ──────────────────────────────────────────
    await page
        .getByRole('button', { name: new RegExp(scenario.domaineLabel, 'i') })
        .click();
    await page.getByRole('button', { name: /suivant/i }).click();

    // ── Étape 4 : Résumé ──────────────────────────────────────────────────────
    await expect(
        page.getByRole('button', { name: /terminer l'installation/i }),
    ).toBeEnabled({ timeout: 10_000 });
    await page.getByRole('button', { name: /terminer l'installation/i }).click();

    await expect(
        page.getByRole('heading', { name: /installation terminée/i }),
    ).toBeVisible({ timeout: 30_000 });
}

/** Depuis Install/Success : clique "Se connecter", se reconnecte, atteint l'onboarding. */
export async function loginAfterInstall(
    page: Page,
    scenario: InstallScenario,
): Promise<void> {
    await page.getByRole('link', { name: /se connecter/i }).click();
    await expect(page).toHaveURL(/\/login(?:\?.*)?$/, { timeout: 15_000 });

    await page.waitForSelector('input[name="password"]', { timeout: 20_000 });
    await fillLoginIdentifier(page, { phone: scenario.fullPhone });
    await page.locator('input[name="password"]').fill(scenario.password);
    await page.getByRole('button', { name: /se connecter/i }).click();

    await expect(page).toHaveURL(/\/onboarding\/site/, { timeout: 20_000 });
}

/** Onboarding minimal (type + ville + quartier) → dashboard. */
export async function completeOnboarding(
    page: Page,
    scenario: InstallScenario,
): Promise<void> {
    await expect(
        page.getByRole('heading', { name: /configurons votre premier site/i }),
    ).toBeVisible({ timeout: 15_000 });

    await page.getByRole('combobox').first().click();
    await page.getByRole('option').first().click();

    await page.locator('#site-ville').fill(scenario.siteVille);
    await page.locator('#site-quartier').fill(scenario.siteQuartier);

    await page.getByRole('button', { name: /terminer et accéder à elm/i }).click();

    await expect(page).toHaveURL(/\/backoffice\/dashboard/, { timeout: 20_000 });
}

export { firstOtpBox, fillStep2Form, OTP_FIXED_CODE };
