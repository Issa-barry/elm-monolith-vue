/**
 * Scénario On-Premise (mode par défaut de ce harnais, cf. .env.e2e sans APP_DEPLOYMENT_MODE) —
 * l'email du Super Admin est obligatoire :
 *   /install → Entreprise → Super Administrateur → email vide bloque "Suivant" (libellé "Email *",
 *   jamais "(facultatif)") → email renseigné + mot de passe → Suivant → vérification email →
 *   Domaine d'activité → Résumé → installation → /onboarding/site → Type=Usine, Ville=Conakry,
 *   Quartier=Matoto → back-office affichant "Usine de Matoto" (jamais "Usine de Usine Matoto").
 *
 * Run: npx playwright test --config=playwright.install.config.ts tests/e2e-install/install-on-premise.spec.ts
 * Base de données requise : fraîchement migrée, jamais seedée.
 */
import { expect, test } from '@playwright/test';
import { completeInstallWizard, completeOnboarding, loginAfterInstall } from './helpers';

test.setTimeout(120_000);

test('email vide bloque "Suivant" en on_premise, avec le libellé obligatoire', async ({
    page,
}) => {
    await page.goto('/install');
    await page.locator('#org-nom').fill('Eau la maman E2E On-Premise Requis');
    await page.getByRole('button', { name: /suivant/i }).click();

    // Libellé "Email *", jamais "Email (facultatif)" en on_premise.
    await expect(page.locator('label[for="admin-email"]')).toContainText('*');
    await expect(page.getByText('Email (facultatif)')).toHaveCount(0);

    await page.locator('#admin-prenom').fill('Issa');
    await page.locator('#admin-nom').fill('BARRY');
    await page.locator('#admin-telephone').fill('622000010');
    await expect(page.getByText(/format du numéro valide/i)).toBeVisible({
        timeout: 15_000,
    });
    await page.locator('#admin-password').fill('Sup3r$ecretPwd99');

    // Email laissé vide : "Suivant" doit rester désactivé (email obligatoire).
    await expect(page.getByRole('button', { name: /suivant/i })).toBeDisabled();

    await page.locator('#admin-email').fill('requis.e2e@gmail.com');
    await expect(page.getByRole('button', { name: /suivant/i })).toBeEnabled();
});

test('installation complète avec Type=Usine, Ville=Conakry, Quartier=Matoto affiche "Usine de Matoto"', async ({
    page,
}) => {
    const scenario = {
        orgNom: 'Eau la maman E2E On-Premise',
        prenom: 'Issa',
        nom: 'BARRY',
        localDigits: '666177006',
        fullPhone: '+224666177006',
        email: 'onpremise.e2e@gmail.com',
        password: 'Sup3r$ecretPwd99',
        domaineLabel: 'Industrie',
        siteTypeLabel: 'Usine',
        siteVille: 'Conakry',
        siteQuartier: 'Matoto',
    };

    await completeInstallWizard(page, scenario);
    await loginAfterInstall(page, scenario);
    await completeOnboarding(page, scenario);

    // completeOnboarding() a déjà vérifié la présence exacte de "Usine de Matoto" dans le bloc
    // utilisateur (UserInfo.vue) — on vérifie ici, en plus, l'absence explicite du doublon signalé.
    await expect(page.getByText('Usine de Usine de Matoto')).toHaveCount(0);
    await expect(page.getByText('Usine de Usine Matoto')).toHaveCount(0);
});
