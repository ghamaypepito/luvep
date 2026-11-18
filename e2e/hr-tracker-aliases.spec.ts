import { test, expect } from '@playwright/test';

test('partner visa client sees one requirement and both historical uploads', async ({ page }) => {
    await page.goto('https://luvep.test:8443/portal/lead/requirements');
    await page.locator('#email').fill('alias-client@example.test');
    await page.locator('#password').fill('Browser-Test-123');
    await page.locator('button[type=submit]').click();
    await page.waitForURL(url => !url.pathname.includes('login'));
    await page.goto('https://luvep.test:8443/portal/lead/requirements');
    await expect(page.getByText('Sponsor NZ Visa', {exact:true})).toHaveCount(1);
    await expect(page.getByText('NZ Visa of Partner', {exact:true})).toHaveCount(0);
    await expect(page.getByText('Original sponsor visa.pdf', {exact:true})).toBeVisible();
    await expect(page.getByText('Historical partner visa.pdf', {exact:true})).toBeVisible();
});
