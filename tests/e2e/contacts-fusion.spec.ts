/**
 * Lot 9b (ADR 0060) — fusion manuelle de deux fiches Personne, de bout en bout : fiche A →
 * Fusionner → recherche et choix de B → arbitrage d'un champ → confirmation explicite → B archivée
 * et redirigée vers A → annulation depuis l'historique des fusions.
 *
 * Les deux fiches sont créées pour ce test (numéros uniques) : aucune donnée de démonstration n'est
 * modifiée.
 */
import { expect, test, type Page } from '@playwright/test';
import { login } from './helpers';

function numero(): string {
    return '+22465' + String(Date.now()).slice(-7);
}

async function poster(
    page: Page,
    url: string,
    donnees: Record<string, string>,
): Promise<void> {
    const cookies = await page.context().cookies();
    const xsrf = cookies.find((c) => c.name === 'XSRF-TOKEN');
    expect(xsrf, 'cookie XSRF-TOKEN').toBeTruthy();

    const reponse = await page.request.post(url, {
        form: donnees,
        headers: {
            'X-XSRF-TOKEN': decodeURIComponent(xsrf!.value),
            // JSON : une erreur de validation revient en 422 avec son message (pas de redirection).
            Accept: 'application/json',
        },
        maxRedirects: 0,
    });
    expect([201, 302], await reponse.text()).toContain(reponse.status());
}

/** Id de la fiche Personne trouvée par la liste « Personnes physiques ». */
async function ficheParRecherche(page: Page, terme: string): Promise<string> {
    await page.goto(
        `/backoffice/contacts/personnes?recherche=${encodeURIComponent(terme)}`,
    );
    await page.locator('tr', { hasText: terme }).first().click();
    await expect(page).toHaveURL(
        /\/backoffice\/contacts\/personnes\/[0-9a-z]{26}$/,
    );

    return page.url().split('/').pop()!;
}

test.describe('Fusion manuelle de deux fiches Personne', () => {
    test('fusion avec arbitrage, redirection de la fiche absorbée et annulation', async ({
        page,
    }) => {
        await login(page);
        await page.goto('/backoffice/dashboard');

        const suffixe = String(Date.now()).slice(-5);
        const telA = numero();
        const telB = '+22466' + telA.slice(-7);
        const nomA = `Fusiona Test${suffixe}`;
        const nomB = `Fusionb Test${suffixe}`;
        // Deux fonctions différentes (livreur, client) : aucun blocage F1.
        await poster(page, '/backoffice/livreurs', {
            nom_complet: nomA,
            telephone: telA,
        });
        await poster(page, '/backoffice/clients', {
            nom_complet: nomB,
            // Formulaire Client : numéro local (9 chiffres) et pays à part.
            telephone: telB.replace('+224', ''),
            code_pays: 'GN',
            ville: 'Dubreka',
        });
        const idA = await ficheParRecherche(page, nomA);
        const idB = await ficheParRecherche(page, nomB);

        // Fiche A → Fusionner
        await page.goto(`/backoffice/contacts/personnes/${idA}`);
        await page.getByTestId('fiche-fusionner').click();
        await expect(page.getByTestId('fusion-recherche')).toBeVisible();
        await expect(
            page.getByRole('heading', { name: new RegExp(nomA) }),
        ).toBeVisible();

        // Recherche et choix de B
        await page.getByTestId('fusion-recherche').fill(nomB);
        await page.getByTestId('fusion-rechercher').click();
        await page.getByTestId(`fusion-choisir-${idB}`).click();
        await expect(page.getByTestId('fusion-simulation')).toBeVisible();
        await expect(page.getByTestId('fusion-blocages')).toHaveCount(0);

        // Arbitrage : la ville de B ; le bouton reste bloqué sans confirmation explicite
        await page.getByTestId('fusion-champ-ville-b').click();
        await expect(page.getByTestId('fusion-fusionner')).toBeDisabled();
        await page.getByTestId('fusion-confirmer').click();
        await page.getByTestId('fusion-fusionner').click();
        await page.getByTestId('fusion-confirmer-dialogue').click();

        // Retour sur A, qui porte la ville de B ; l'adresse de B mène à A
        await expect(page).toHaveURL(
            new RegExp(`/backoffice/contacts/personnes/${idA}$`),
        );
        await expect(page.getByText('Dubreka').first()).toBeVisible();
        await page.goto(`/backoffice/contacts/personnes/${idB}`);
        await expect(page).toHaveURL(
            new RegExp(`/backoffice/contacts/personnes/${idA}$`),
        );

        // Historique → annulation
        await page.goto('/backoffice/contacts/fusions');
        await page
            .getByTestId('fusions-table')
            .getByRole('link')
            .first()
            .click();
        await expect(page.getByTestId('fusion-annulation')).toBeVisible();
        await page.getByTestId('fusion-annuler').click();
        await page.getByTestId('fusion-annuler-confirmer').click();
        await expect(page.getByText('Annulée', { exact: true })).toBeVisible();

        // B est de nouveau consultable
        await page.goto(`/backoffice/contacts/personnes/${idB}`);
        await expect(page).toHaveURL(
            new RegExp(`/backoffice/contacts/personnes/${idB}$`),
        );
    });
});
