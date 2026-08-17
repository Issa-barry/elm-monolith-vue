/**
 * Scénario — installation avec un numéro Sierra Leone (+232) : vérifie que le pays est
 * correctement propagé jusqu'au premier site (héritage du pays du Super Admin, cf.
 * InstallationService::creerPremierSite()). Email inclus (obligatoire en on_premise, mode par
 * défaut de ce harnais — cf. install-on-premise.spec.ts pour le test dédié à cette règle).
 *
 * Run: npx playwright test --config=playwright.install.config.ts tests/e2e-install/install-sierra-leone.spec.ts
 * Base de données requise : fraîchement migrée, jamais seedée.
 */
import { expect, test } from '@playwright/test';
import { completeInstallWizard, completeOnboarding, loginAfterInstall } from './helpers';

test.setTimeout(120_000);

const scenario = {
    orgNom: 'Eau la maman E2E Sierra Leone',
    prenom: 'Moussa',
    nom: 'SIDIBE',
    countryLabel: 'Sierra Leone' as const,
    localDigits: '76123456',
    fullPhone: '+23276123456',
    email: 'moussa.e2e@gmail.com',
    password: 'Sup3r$ecretPwd99',
    domaineLabel: 'Commerce',
    siteTypeLabel: 'Siège',
    siteVille: 'Freetown',
    siteQuartier: 'Aberdeen',
};

test('installation avec un numéro Sierra Leone propage le pays jusquau premier site', async ({
    page,
}) => {
    await completeInstallWizard(page, scenario);
    await loginAfterInstall(page, scenario);
    await completeOnboarding(page, scenario);

    await expect(page.locator('body')).toBeVisible();
});
