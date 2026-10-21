import { test, expect } from '@playwright/test';

test('both task comment composers submit with a stale HTML CSRF token', async ({ page }) => {
    await page.goto('https://luvep.test:8443/admin/tasks');
    await page.locator('#email').fill('admin@example.test');
    await page.locator('#password').fill('Browser-Test-123');
    await page.locator('button[type=submit]').click();
    await expect(page).toHaveURL(/admin\/tasks/);
    // Simulate the initial document token surviving an Inertia session refresh.
    await page.evaluate(() => document.querySelector('meta[name="csrf-token"]')!.setAttribute('content', 'stale-before-login'));
    const card = page.getByRole('button', { name: /^Comment CSRF regression,/ });
    await card.getByTitle('View comments').click();
    await page.getByPlaceholder('Add a comment… (⌘/Ctrl + Enter to send)').fill('Popover persisted comment');
    let saved = page.waitForResponse(r => /\/api\/tasks\/\d+\/comments$/.test(r.url()) && r.request().method() === 'POST');
    await page.getByRole('button', { name: 'Send', exact: true }).click();
    expect((await saved).status()).toBe(201);
    await expect(page.getByText('Popover persisted comment', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Close comments' }).click();
    await card.click();
    await page.getByPlaceholder('Add a comment…', { exact: true }).fill('Detail persisted comment');
    saved = page.waitForResponse(r => /\/api\/tasks\/\d+\/comments$/.test(r.url()) && r.request().method() === 'POST');
    await page.getByRole('button', { name: 'Send comment', exact: true }).click();
    expect((await saved).status()).toBe(201);
    await expect(page.getByText('Detail persisted comment', { exact: true })).toBeVisible();
    await page.reload();
    await page.getByRole('button', { name: /^Comment CSRF regression,/ }).click();
    await expect(page.getByText('Popover persisted comment', { exact: true })).toBeVisible();
    await expect(page.getByText('Detail persisted comment', { exact: true })).toBeVisible();
});
