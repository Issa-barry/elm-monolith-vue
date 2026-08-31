import { expect, test } from '@playwright/test';
import { fillLoginIdentifier } from './helpers';

test('debug login phone composition', async ({ page }) => {
    await page.goto('/login');
    await page.waitForSelector('input[name="password"]', { timeout: 20_000 });

    await fillLoginIdentifier(page, { phone: '+33758855039' });
    await page.locator('input[name="password"]').fill('Staff@2025');

    const hidden = page.locator('input[name="telephone"]').first();
    const value = await hidden.getAttribute('value');
    const domValue = await hidden.evaluate((el: HTMLInputElement) => el.value);
    console.log('HIDDEN attr value:', value);
    console.log('HIDDEN dom .value:', domValue);

    const combobox = page.locator('form').getByRole('combobox').first();
    console.log('COMBOBOX text:', await combobox.innerText());

    const tel = page.locator('form input[type="tel"]').first();
    console.log('TEL dom .value:', await tel.evaluate((el: HTMLInputElement) => el.value));

    const [response] = await Promise.all([
        page.waitForResponse((r) => r.url().includes('/login') && r.request().method() === 'POST'),
        page.getByRole('button', { name: /se connecter/i }).first().click(),
    ]);
    console.log('POST /login status:', response.status());
    console.log('POST /login headers:', JSON.stringify(response.headers()));
    const respBody = await response.text().catch((e) => `<unreadable: ${e}>`);
    console.log('POST /login body (first 1000 chars):', respBody.slice(0, 1000));

    await page.waitForTimeout(2000);
    console.log('URL after submit:', page.url());
    console.log('BODY snippet:', (await page.locator('body').innerText()).slice(0, 400));
});
