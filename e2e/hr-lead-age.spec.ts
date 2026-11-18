import { test, expect } from '@playwright/test';
test('registered age appears in lead Personal Information', async ({page}) => {
 await page.goto('https://luvep.test:8443/admin/leads');
 await page.locator('#email').fill('admin@example.test');
 await page.locator('#password').fill('Browser-Test-123');
 await page.locator('button[type=submit]').click();
 await expect(page).toHaveURL(/admin\/leads/);
 await page.getByText('Age Verification', {exact:true}).first().click();
 await page.getByRole('button', {name:/^Profile information/}).click();
 await expect(page.getByText('1. Personal Information', {exact:true})).toBeVisible();
 await expect(page.getByText('37', {exact:true}).first()).toBeVisible();
});
