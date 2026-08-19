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

test('le champ montant bloque les caractères non numériques à la frappe et au collage', async ({
    page,
}) => {
    await page.goto('/settings/commissions');

    const row = page
        .locator('tbody tr', { hasText: /toutes catégories/i })
        .first();
    await row.getByRole('button').first().click();

    const dialog = page.getByRole('dialog', { name: /propriétaire/i });
    await expect(dialog).toBeVisible({ timeout: 5_000 });
    const montantInput = dialog.locator('#cr-montant');

    // Frappe caractère par caractère (pressSequentially déclenche de vrais
    // événements keydown, contrairement à fill()) : seuls les chiffres
    // doivent apparaître, le reste bloqué avant même d'entrer dans le champ.
    await montantInput.pressSequentially('5+5-5.5,5e5 5abc5');
    await expect(montantInput).toHaveValue('55555555');

    await montantInput.fill('');

    // Collage : un texte collé contenant autre chose que des chiffres est
    // nettoyé (seuls les chiffres conservés), jamais inséré tel quel.
    await montantInput.evaluate((el) => {
        const input = el as HTMLInputElement;
        const dataTransfer = new DataTransfer();
        dataTransfer.setData('text', '5+555-88');
        input.dispatchEvent(
            new ClipboardEvent('paste', {
                clipboardData: dataTransfer,
                bubbles: true,
                cancelable: true,
            }),
        );
    });
    await expect(montantInput).toHaveValue('555588');
});
