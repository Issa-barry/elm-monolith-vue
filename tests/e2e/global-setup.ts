/**
 * global-setup.ts
 * Runs once before all tests.
 * Logs in and stores auth state in .auth/user.json.
 */
import { chromium, type FullConfig } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

import { login } from './helpers';

export default async function globalSetup(config: FullConfig) {
    const baseURL = config.projects[0].use.baseURL ?? 'http://127.0.0.1:8080';

    const authDir = path.join(process.cwd(), '.auth');
    if (!fs.existsSync(authDir)) {
        fs.mkdirSync(authDir, { recursive: true });
    }

    const browser = await chromium.launch();
    const context = await browser.newContext({ baseURL });
    const page = await context.newPage();

    try {
        await login(page);
    } catch (error) {
        await browser.close();
        throw error;
    }

    await context.storageState({ path: path.join(authDir, 'user.json') });
    await browser.close();
}
