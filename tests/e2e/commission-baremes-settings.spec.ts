import { expect, test } from '@playwright/test';
import { login } from './helpers';

/**
 * Paramètres → Commissions (barèmes PAR_UNITE_VENDUE, Phase 2) — vérifie que
 * l'action "Définir" est visible sans survol (pas seulement au hover, cf.
 * correction UX) et que le cycle définir → afficher → modifier → afficher
 * fonctionne de bout en bout.
 */

test.beforeEach(async ({ page }) => {
    await login(page);
});

test('définir un barème sur une cellule vide, puis le modifier', async ({
    page,
}) => {
    await page.goto('/settings/commissions');
    await expect(
        page.getByRole('heading', { name: /^commissions$/i }),
    ).toBeVisible();

    const row = page
        .locator('tbody tr', { hasText: /toutes catégories/i })
        .first();
    await expect(row).toBeVisible();

    const proprietaireCell = row.getByRole('button').first();

    // Cellule vide : l'action "Définir" doit être visible sans survol —
    // jamais la seule façon de découvrir que la cellule est interactive.
    await expect(proprietaireCell).toContainText(/définir/i);
    await expect(proprietaireCell).not.toContainText('—');

    await proprietaireCell.click();
    const dialog = page.getByRole('dialog', { name: /propriétaire/i });
    await expect(dialog).toBeVisible({ timeout: 5_000 });

    await dialog.locator('#cr-montant').fill('650');
    await dialog.getByRole('button', { name: /enregistrer/i }).click();

    await expect(dialog).toBeHidden({ timeout: 10_000 });
    await expect(page.getByText(/barème enregistré/i)).toBeVisible({
        timeout: 5_000,
    });
    await expect(proprietaireCell).toContainText('650');
    await expect(proprietaireCell).not.toContainText('—');

    // Rouvrir la même cellule, maintenant pré-remplie : la modifier doit
    // remplacer la valeur affichée, jamais la dupliquer ni la conserver.
    await proprietaireCell.click();
    await expect(dialog).toBeVisible({ timeout: 5_000 });
    await expect(dialog.locator('#cr-montant')).toHaveValue('650');

    await dialog.locator('#cr-montant').fill('700');
    await dialog.getByRole('button', { name: /enregistrer/i }).click();

    await expect(dialog).toBeHidden({ timeout: 10_000 });
    await expect(proprietaireCell).toContainText('700');
    await expect(proprietaireCell).not.toContainText('650');
});
