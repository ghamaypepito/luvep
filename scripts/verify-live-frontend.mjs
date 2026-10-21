import assert from 'node:assert/strict';
import { chromium } from '@playwright/test';

const origin = new URL(process.env.LUVEP_CHECK_URL || 'https://luvep.com').origin;
const browser = await chromium.launch({
    headless: true,
    ...(process.env.PLAYWRIGHT_CHANNEL ? { channel: process.env.PLAYWRIGHT_CHANNEL } : {}),
});
let failure;
try {
    for (let attempt = 1; attempt <= 3; attempt++) {
        const context = await browser.newContext({ ignoreHTTPSErrors: process.env.LUVEP_CHECK_LOCAL === '1' });
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        try {
            const response = await page.goto(origin + '/login', { waitUntil: 'domcontentloaded', timeout: 30000 });
            assert.equal(response?.status(), 200, 'Login HTTP response');
            await page.locator('#email').waitFor({ state: 'visible', timeout: 15000 });
            await page.locator('#password').waitFor({ state: 'visible', timeout: 15000 });
            assert.equal(await page.locator('#luvep-startup').count(), 1, 'Startup recovery markup exists');
            await page.locator('#luvep-startup').waitFor({ state: 'hidden', timeout: 15000 });
            assert.equal(await page.evaluate(() => [...document.styleSheets].some(sheet => sheet.href && new URL(sheet.href).pathname.startsWith('/build/'))), true, 'Production stylesheet loaded');
            assert.deepEqual(errors, [], 'No frontend runtime errors');
            console.log(`Frontend render check passed on attempt ${attempt}: login form visible, stylesheet loaded, recovery screen hidden.`);
            failure = null;
            break;
        } catch (error) {
            failure = error;
            console.error(`Frontend render check attempt ${attempt} failed: ${error.message}`);
        } finally {
            await context.close();
        }
    }
} finally {
    await browser.close();
}
if (failure) throw failure;
