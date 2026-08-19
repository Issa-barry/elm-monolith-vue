/**
 * Scénario 2 — installation AVEC email : formulaire principal rempli en entier (y compris le
 * mot de passe), puis "Suivant" envoie le code et bascule vers l'état dédié "Vérifiez votre
 * email" (OtpCodeInput, repris du parcours Invitation) avant de continuer automatiquement.
 *
 * Run: npx playwright test --config=playwright.install.config.ts tests/e2e-install/install-with-email.spec.ts
 * Base de données requise : fraîchement migrée, jamais seedée.
 */
import { expect, test } from '@playwright/test';
import {
    completeInstallWizard,
    fillOtpCode,
    fillStep2Form,
    loginAfterInstall,
    OTP_FIXED_CODE,
} from './helpers';

test.setTimeout(120_000);

const scenario = {
    orgNom: 'Eau la maman E2E Email',
    prenom: 'Aminata',
    nom: 'DIALLO',
    localDigits: '622000003',
    fullPhone: '+224622000003',
    email: 'aminata2.e2e@gmail.com',
    password: 'Sup3r$ecretPwd99',
    domaineLabel: 'Commerce',
    siteTypeLabel: 'Siège',
    siteVille: 'Conakry',
    siteQuartier: 'Kipé',
};

test('un mauvais code est refusé avant validation du bon code', async ({ page }) => {
    // Email distinct du scénario "installation complète" ci-dessous : le renvoi de code est
    // soumis à un délai anti-spam de 30s par email (cf. OtpService) — deux tests utilisant la
    // même adresse à quelques secondes d'intervalle se bloqueraient l'un l'autre (429).
    const badCodeScenario = {
        ...scenario,
        orgNom: 'Eau la maman E2E Email Codes',
        localDigits: '622000004',
        email: 'aminata1.e2e@gmail.com',
    };

    await page.goto('/install');
    await page.locator('#org-nom').fill(badCodeScenario.orgNom);
    await page.getByRole('button', { name: /suivant/i }).click();

    await fillStep2Form(page, badCodeScenario);
    await page.getByRole('button', { name: /suivant|envoi du code/i }).click();

    await expect(
        page.getByRole('heading', { name: /vérifiez votre email/i }),
    ).toBeVisible({ timeout: 15_000 });

    await fillOtpCode(page, '000000');
    await page.getByRole('button', { name: /^vérifier$/i }).click();
    await expect(page.getByText(/le code saisi est incorrect/i)).toBeVisible({
        timeout: 10_000,
    });
    await expect(page.getByText(/adresse email vérifiée/i)).toHaveCount(0);

    // Toujours sur l'état de vérification : ni la confirmation de mot de passe (déjà saisi
    // avant l'envoi du code) ni un retour au formulaire principal ne sont requis pour corriger.
    await fillOtpCode(page, OTP_FIXED_CODE);
    await page.getByRole('button', { name: /^vérifier$/i }).click();
    await expect(page.getByText(/adresse email vérifiée/i)).toBeVisible({
        timeout: 10_000,
    });
});

test('modifier l’adresse email après envoi invalide l’ancien code', async ({ page }) => {
    const ancienEmail = 'ancien.e2e@gmail.com';
    const nouvelEmail = 'nouveau.e2e@gmail.com';
    const changeScenario = {
        ...scenario,
        orgNom: 'Eau la maman E2E Email Modif',
        localDigits: '622000005',
        email: ancienEmail,
    };

    await page.goto('/install');
    await page.locator('#org-nom').fill(changeScenario.orgNom);
    await page.getByRole('button', { name: /suivant/i }).click();

    await fillStep2Form(page, changeScenario);
    await page.getByRole('button', { name: /suivant|envoi du code/i }).click();
    await expect(page.getByText(ancienEmail)).toBeVisible({ timeout: 15_000 });

    await page.getByRole('button', { name: /modifier l'adresse email/i }).click();

    // De retour sur le formulaire principal : rien n'a été perdu.
    await expect(page.locator('#admin-prenom')).toHaveValue(changeScenario.prenom);
    await expect(page.locator('#admin-password')).toHaveValue(changeScenario.password);

    await page.locator('#admin-email').fill(nouvelEmail);
    await page.getByRole('button', { name: /suivant|envoi du code/i }).click();
    await expect(page.getByText(nouvelEmail)).toBeVisible({ timeout: 15_000 });

    // OTP_FIXED_CODE (.env.e2e) rend tous les codes générés identiques en E2E — impossible donc
    // de distinguer ici "ancien code" et "nouveau code" par leur seule valeur. La garantie réelle
    // (un code est scopé par l'email exact auquel il a été envoyé, cf. OtpService/
    // InstallationService::EMAIL_OTP_CONTEXT) est vérifiée au niveau Feature, pas ici :
    // InstallEmailVerificationTest::test_changer_dadresse_ninherite_pas_de_la_verification_precedente.
    // Ce test-ci couvre le comportement UI observable : après "Modifier l'adresse email" + nouvelle
    // adresse, un nouveau code est bien demandé et le parcours se termine normalement.
    await fillOtpCode(page, OTP_FIXED_CODE);
    await page.getByRole('button', { name: /^vérifier$/i }).click();
    await expect(page.getByText(/adresse email vérifiée/i)).toBeVisible({
        timeout: 10_000,
    });
});

test('installation avec email : code envoyé, vérifié, puis parcours complet jusquau back-office', async ({
    page,
}) => {
    await completeInstallWizard(page, scenario);
    await loginAfterInstall(page, scenario);

    await expect(page.locator('body')).toBeVisible();
});
