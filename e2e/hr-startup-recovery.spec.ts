import { test, expect } from '@playwright/test';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
const main = 'https://luvep.test:8443';

test('reset entry script and missing CSS show recovery; reload restores login', async ({ page }, testInfo) => {
    await page.route('**/build/assets/app-*.js', route => route.abort('connectionreset'));
    await page.route('**/build/assets/*.css', route => route.abort('connectionreset'));
    await page.goto(main + '/login', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { name: 'We couldn’t finish loading Luvep.' })).toBeVisible({ timeout: 3000 });
    await expect(page.getByRole('button', { name: 'Reload Luvep' })).toBeVisible();
    const recovery = page.locator('#luvep-startup');
    await expect(recovery).toBeVisible();
    expect(await recovery.evaluate(el => getComputedStyle(el).position)).toBe('fixed');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({ path: testInfo.outputPath('startup-recovery-mobile.png') });
    expect(await recovery.evaluate(el => el.scrollWidth <= window.innerWidth)).toBe(true);
    await page.unroute('**/build/assets/app-*.js');
    await page.unroute('**/build/assets/*.css');
    await page.getByRole('button', { name: 'Reload Luvep' }).click();
    await expect(page.locator('#email')).toBeVisible();
    await expect(recovery).toBeHidden();
});

test('a stalled entry script shows a timed recovery message', async ({ page }) => {
    await page.clock.install();
    let release!: () => void;
    const gate = new Promise<void>(resolve => { release = resolve; });
    await page.route('**/build/assets/app-*.js', async route => {
        await gate;
        await route.continue().catch(() => {});
    });
    await page.goto(main + '/login', { waitUntil: 'commit' });
    await expect(page.locator('#luvep-startup')).toBeVisible();
    await page.clock.fastForward(16000);
    await expect(page.getByRole('button', { name: 'Reload Luvep' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Loading is taking longer than usual.' })).toBeVisible();
    release();
    await expect(page.locator('#email')).toBeVisible();
    await expect(page.locator('#luvep-startup')).toBeHidden();
});

test('normal startup removes the recovery screen and keeps login interactive', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(main + '/login');
    await expect(page.locator('#email')).toBeVisible();
    await expect(page.locator('#luvep-startup')).toBeHidden();
    await page.locator('#email').fill('browser-check@example.test');
    await expect(page.locator('#email')).toHaveValue('browser-check@example.test');
    expect(errors).toEqual([]);
});

test('deployment checker confirms actual login rendering', async () => {
    const { stdout } = await promisify(execFile)('node', ['scripts/verify-live-frontend.mjs'], {
        env: { ...process.env, PLAYWRIGHT_CHANNEL: 'chrome', LUVEP_CHECK_LOCAL: '1', LUVEP_CHECK_URL: 'https://localhost:8443' },
        timeout: 35000,
    });
    expect(stdout).toContain('Frontend render check passed');
});

test('a resource failure before body parsing shows recovery despite a stalled module', async ({ page }) => {
    let release!: () => void;
    const gate = new Promise<void>(resolve => { release = resolve; });
    await page.route('**/login', async route => {
        const response = await route.fetch({ url: 'https://localhost:8443/login', headers: { ...route.request().headers(), host: 'luvep.test:8443' } });
        const html = await response.text();
        // Deterministically deliver a stylesheet error before the body panel exists.
        const error = `<script>document.querySelector('link[rel="stylesheet"][href*="/build/"]').dispatchEvent(new Event('error'));</script>`;
        await route.fulfill({ response, body: html.replace('</head>', error + '</head>') });
    });
    await page.route('**/build/assets/app-*.js', async route => {
        await gate;
        await route.continue().catch(() => {});
    });
    try {
        await page.goto(main + '/login', { waitUntil: 'commit' });
        await expect(page.getByRole('heading', { name: 'We couldn’t finish loading Luvep.' })).toBeVisible({ timeout: 3000 });
        await expect(page.getByRole('button', { name: 'Reload Luvep' })).toBeVisible();
    } finally {
        release();
    }
});

test('an explicit startup crash after ready reopens recovery and cannot be hidden by a late ready callback', async ({ page }) => {
    await page.goto(main + '/login');
    await expect(page.locator('#email')).toBeVisible();
    await expect(page.locator('#luvep-startup')).toBeHidden();
    await page.evaluate(() => (window as any).luvepStartup.fail());
    await expect(page.getByRole('heading', { name: 'We couldn’t finish loading Luvep.' })).toBeVisible({ timeout: 3000 });
    await page.evaluate(() => (window as any).luvepStartup.ready());
    await expect(page.getByRole('button', { name: 'Reload Luvep' })).toBeVisible();
});
