/**
 * Scénario 1 (cf. demande) — installation complète SANS email :
 *   /install → Entreprise → Super Admin (sans email, Guinée) → mot de passe saisi une seule
 *   fois → Domaine → Résumé → installation → /onboarding/site → Type + Ville + Quartier →
 *   back-office. Aucune confirmation de mot de passe et aucune vérification email ne doivent
 *   être demandées.
 *
 * Run: npx playwright test --config=playwright.install.config.ts tests/e2e-install/install-no-email.spec.ts
 * Base de données requise : fraîchement migrée, jamais seedée (migrate:fresh --env=e2e, sans --seed).
 */
import { expect, test } from '@playwright/test';
import { completeInstallWizard, completeOnboarding, loginAfterInstall } from './helpers';

test.setTimeout(120_000);

const scenario = {
    orgNom: 'Eau la maman E2E',
    prenom: 'Issa',
    nom: 'BARRY',
    localDigits: '622000001',
    fullPhone: '+224622000001',
    password: 'Sup3r$ecretPwd99',
    domaineLabel: 'Commerce',
    siteVille: 'Conakry',
    siteQuartier: 'Matoto',
};

test('installation sans email, aucune confirmation de mot de passe ni vérification email requise', async ({
    page,
}) => {
    await completeInstallWizard(page, scenario);
    await loginAfterInstall(page, scenario);
    await completeOnboarding(page, scenario);

    await expect(page.locator('body')).toBeVisible();
});
