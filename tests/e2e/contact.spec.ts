import { expect, test } from '@playwright/test';

test.setTimeout(60_000);

test('navigation vers la page contact depuis la topbar', async ({ page }) => {
    await page.goto('/');

    const contactMenu = page.locator('header a', { hasText: /contact/i }).first();
    await expect(contactMenu).toBeVisible();
    await contactMenu.click();

    await expect(page).toHaveURL(/\/contact$/);
});

test('la page contact affiche les infos et les champs obligatoires', async ({ page }) => {
    await page.goto('/contact');

    await expect(page.getByRole('heading', { name: /contactez-nous/i })).toBeVisible();
    await expect(page.getByText('+224 620 00 00 00')).toBeVisible();
    await expect(page.getByText('contact@eaulamaman.com')).toBeVisible();
    await expect(page.getByText(/conakry,\s*matoto,\s*guinee/i)).toBeVisible();

    const phoneInput = page.locator('#phone');
    const messageInput = page.locator('#message');

    await expect(phoneInput).toHaveAttribute('required', '');
    await expect(messageInput).toHaveAttribute('required', '');

    await phoneInput.fill('+224620001122');
    await messageInput.fill('Test E2E contact - message complet');
    await page.getByRole('button', { name: /envoyer le message/i }).click();

    // Should show success feedback OR stay on contact page
    await expect(page).toHaveURL(/\/contact$/);
});
