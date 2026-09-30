import { expect, test, type Page } from '@playwright/test';
import { montantPattern } from './helpers';

/**
 * Commissions → Monitoring : une commission PARTIELLE (partage Livreur 700 GNF pour un barème de
 * 800 GNF) apparaît comme anomalie ouverte, avec son motif exact et la configuration en cause ;
 * une relance sans correction échoue et reste tracée ; navigation monitoring ⇄ fiche commande.
 * La régularisation après correction est couverte par CommissionMonitoringTest (PHPUnit).
 *
 * Contexte : fixture e2e-only /e2e/fixtures/partages-livreur, organisation dédiée (commande
 * PARTIELLE sur le véhicule V4), cf. reconfiguration-partages.spec.ts.
 */

interface Fixture {
    vehicules: Record<'V1' | 'V2' | 'V3' | 'V4', { id: string; nom: string }>;
    commande_partielle_id: string;
}

const URL_MONITORING = '/backoffice/comptabilite/commissions/monitoring';

async function preparerFixture(page: Page): Promise<Fixture> {
    const cookie = (await page.context().cookies()).find(
        (c) => c.name === 'XSRF-TOKEN',
    );
    if (!cookie) throw new Error('Cookie XSRF-TOKEN introuvable.');
    const response = await page.request.post('/e2e/fixtures/partages-livreur', {
        headers: {
            'X-XSRF-TOKEN': decodeURIComponent(cookie.value),
            Accept: 'application/json',
        },
    });
    expect(response.ok(), await response.text()).toBeTruthy();
    return (await response.json()) as Fixture;
}

async function nombreTentatives(page: Page): Promise<number> {
    const titre = await page
        .getByTestId('monitoring-detail')
        .getByText(/Historique des tentatives \(\d+\)/)
        .textContent();
    return Number(titre?.match(/\((\d+)\)/)?.[1] ?? 0);
}

test('monitoring : commission partielle listée, motif détaillé, relance échouée tracée, lien commande', async ({
    page,
}) => {
    test.setTimeout(120_000);
    await page.context().clearCookies();
    await page.goto('/login');
    const f = await preparerFixture(page);
    const v4 = f.vehicules.V4.nom;

    await page.goto(URL_MONITORING);
    await expect(page.getByTestId('monitoring-kpi-non_generee')).toContainText(
        '1',
    );

    const ligne = page
        .getByTestId('monitoring-table')
        .locator('tbody tr', { hasText: v4 });
    await expect(ligne).toHaveCount(1);
    await expect(ligne).toContainText('Livreurs');
    await expect(ligne).toContainText('Partage Livreur non conforme au barème');
    await expect(ligne).toContainText(new RegExp(montantPattern(8000)));
    await expect(ligne).toContainText('Non générée');

    await ligne.click();
    const detail = page.getByTestId('monitoring-detail');
    await expect(detail).toContainText(
        "Il reste 100 GNF à attribuer sur l'enveloppe Livreur de 800 GNF (attribué : 700 GNF).",
    );
    await expect(detail).toContainText(/Chauffeur V4/);
    await expect(detail).toContainText(/Convoyeur V4/);
    const avant = await nombreTentatives(page);

    // Relance sans correction : échec affiché, anomalie conservée, tentative de plus.
    await detail.getByTestId('monitoring-detail-relancer').click();
    await expect(
        page.getByText(/La génération a de nouveau échoué/),
    ).toBeVisible();
    await expect(detail).toContainText(
        `Historique des tentatives (${avant + 1})`,
    );
    await expect(detail).toContainText('Relance manuelle');

    // Monitoring → fiche commande → monitoring.
    await detail.getByTestId('monitoring-voir-commande').click();
    await expect(page).toHaveURL(
        new RegExp(`/backoffice/ventes/${f.commande_partielle_id}`),
    );
    await expect(
        page.getByText('Commission partiellement générée'),
    ).toBeVisible();
    await page.getByTestId('commande-lien-monitoring').click();
    await expect(page).toHaveURL(/commissions\/monitoring\?.*statut=toutes/);
    await expect(
        page
            .getByTestId('monitoring-table')
            .locator('tbody tr', { hasText: v4 }),
    ).toHaveCount(1);
});
