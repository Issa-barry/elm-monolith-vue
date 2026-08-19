/**
 * Helpers spécifiques au parcours /install → back-office, testé de bout en bout sur une base
 * fraîchement migrée (jamais seedée, cf. playwright.install.config.ts). Le premier site est
 * désormais configuré DANS le wizard (étape "Site principal", cf. InstallationService::install()) :
 * plus de passage par /onboarding/site pour une installation neuve (ce parcours reste un filet de
 * sécurité pour les organisations historiques, couvert côté Feature par OnboardingSiteTest, pas
 * ici).
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
    /** Libellé exact de l'option à choisir dans le Select "Type de site" (ex: "Siège", "Usine"). */
    siteTypeLabel: string;
    siteVille: string;
    siteQuartier: string;
}

const OTP_FIXED_CODE = '123456'; // .env.e2e fixe OTP_FIXED_CODE=123456 (cf. config/otp.php)

/**
 * Remplit les 6 cases du composant OtpCodeInput une par une (chemin de saisie normal, pas de
 * collage) — Playwright's `Locator.fill()` respecte l'attribut `maxlength="1"` de chaque case,
 * donc `fill('123456')` sur la première case seule ne pousserait qu'un chiffre unique et le
 * bouton "Vérifier" resterait désactivé. Remplir chaque case correspond exactement à la saisie
 * clavier réelle que le composant gère nativement (avance automatique du focus).
 */
async function fillOtpCode(page: Page, code: string): Promise<void> {
    const boxes = page.locator('input[maxlength="1"]');
    for (let i = 0; i < code.length; i++) {
        await boxes.nth(i).fill(code[i]);
    }
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
 * (Domaine) → étape 4 (Site principal) → étape 5 (Résumé) → soumission. S'arrête sur Install/Success.
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

        await fillOtpCode(page, OTP_FIXED_CODE);
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

    // ── Étape 4 : Site principal ────────────────────────────────────────────
    // Sélectionne explicitement le type demandé (scenario.siteTypeLabel) plutôt que la première
    // option suggérée, pour pouvoir ensuite vérifier le nom généré automatiquement ("{Type} de
    // {Quartier}", cf. SiteNamingService::generateName()).
    await page
        .getByRole('button', { name: scenario.siteTypeLabel, exact: true })
        .click();
    await page.locator('#site-ville').fill(scenario.siteVille);
    await page.locator('#site-quartier').fill(scenario.siteQuartier);
    await page.getByRole('button', { name: /suivant/i }).click();

    // ── Étape 5 : Résumé ──────────────────────────────────────────────────────
    await expect(
        page.getByRole('button', { name: /terminer l'installation/i }),
    ).toBeEnabled({ timeout: 10_000 });
    await page.getByRole('button', { name: /terminer l'installation/i }).click();

    await expect(
        page.getByRole('heading', { name: /installation terminée/i }),
    ).toBeVisible({ timeout: 30_000 });
}

/**
 * Depuis Install/Success : clique "Se connecter", se reconnecte. Le premier site ayant déjà été
 * créé pendant /install (étape "Site principal"), l'utilisateur atterrit directement sur le
 * back-office — plus de détour par /onboarding/site pour une installation neuve.
 */
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

    await expect(page).toHaveURL(/\/backoffice\/dashboard/, { timeout: 20_000 });

    // Le nom généré automatiquement doit apparaître tel quel dans le bloc utilisateur du menu
    // latéral (NavUser.vue/UserInfo.vue), sans le type dupliqué (ex: jamais "Usine de Usine de
    // Matoto") — cf. Site::getLabelAttribute(). Locator scopé au bloc utilisateur
    // (data-test="sidebar-menu-button") plutôt qu'à toute la page : le dashboard affiche aussi le
    // site par défaut dans son en-tête (HeaderWidget.vue), un getByText() global sur toute la page
    // matcherait donc deux éléments (strict mode violation).
    await expect(
        page
            .locator('[data-test="sidebar-menu-button"]')
            .getByText(`${scenario.siteTypeLabel} de ${scenario.siteQuartier}`, {
                exact: true,
            }),
    ).toBeVisible({ timeout: 10_000 });
}

export { fillOtpCode, fillStep2Form, OTP_FIXED_CODE };
