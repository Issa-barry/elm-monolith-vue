/**
 * global-setup.ts
 * Runs once before all tests.
 * Logs in and stores auth state in .auth/user.json.
 */
import { chromium, type FullConfig } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

import { E2E_PASSWORD, fillLoginIdentifier } from './helpers';

export default async function globalSetup(config: FullConfig) {
    const baseURL = config.projects[0].use.baseURL ?? 'http://127.0.0.1:8080';

    const authDir = path.join(process.cwd(), '.auth');
    if (!fs.existsSync(authDir)) {
        fs.mkdirSync(authDir, { recursive: true });
    }

    const browser = await chromium.launch();
    const context = await browser.newContext({ baseURL });
    const page = await context.newPage();

    let lastError: unknown;
    for (let attempt = 1; attempt <= 3; attempt++) {
        await page.goto('/login');
        await page.waitForSelector('input[name="password"]', {
            timeout: 20_000,
        });

        await fillLoginIdentifier(page);
        await page.locator('input[name="password"]').fill(E2E_PASSWORD);
        await page
            .getByRole('button', { name: /se connecter/i })
            .first()
            .click();

        try {
            await page.waitForURL((url) => !url.pathname.startsWith('/login'), {
                timeout: 30_000,
            });
            break;
        } catch (err) {
            lastError = err;
            const body = await page
                .locator('body')
                .innerText()
                .catch(() => '');
            const rateLimited =
                /trop de tentatives|too many|veuillez patienter|please wait|seconds|secondes|r[ée]essayez/i.test(
                    body,
                );
            await page.waitForTimeout(rateLimited ? 61_000 : 2_000 * attempt);
        }
    }

    if (page.url().includes('/login')) {
        await browser.close();
        throw (
            lastError ??
            new Error('global-setup: unable to login after 3 attempts.')
        );
    }

    await context.storageState({ path: path.join(authDir, 'user.json') });
    await browser.close();
}
