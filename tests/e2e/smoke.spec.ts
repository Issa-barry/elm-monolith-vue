import { expect, test } from '@playwright/test';

test('home page responds', async ({ page }) => {
    const response = await page.goto('/');

    expect(response).not.toBeNull();
    expect(response!.ok()).toBeTruthy();
    await expect(page.locator('body')).toBeVisible();
});

test('login page responds', async ({ page }) => {
    const response = await page.goto('/login');

    expect(response).not.toBeNull();
    expect(response!.ok()).toBeTruthy();
});
