import { expect, test } from '@playwright/test';

test.setTimeout(90_000);

test('help page renders and remains publicly accessible', async ({ page }) => {
    const response = await page.goto('/help');
    expect(response?.ok()).toBeTruthy();
    await expect(page).toHaveURL(/\/help$/, { timeout: 15_000 });
    await expect(page.locator('body')).toBeVisible();
});
