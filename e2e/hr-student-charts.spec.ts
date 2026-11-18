import {test,expect} from '@playwright/test';
test('student line graph follows the selected department and counts only its members',async({page})=>{
 await page.goto('https://luvep.test:8443/portal/education/students');
 await page.locator('#email').fill('admin@example.test');await page.locator('#password').fill('Browser-Test-123');await page.locator('button[type=submit]').click();
 await expect(page.getByRole('img',{name:'Line graph of student count per Education stage'})).toBeVisible();
 await page.getByRole('button',{name:/^English \d+$/}).click();
 const english=page.getByRole('img',{name:'Line graph of student count per English stage'});await expect(english).toBeVisible();
 await expect(english.locator('..').locator('..')).toContainText('1 placed · 0 unstaged · 1 total');
 await page.getByRole('button',{name:/^Immigration \d+$/}).click();
 const immigration=page.getByRole('img',{name:'Line graph of student count per Immigration stage'});await expect(immigration).toBeVisible();
 await expect(immigration).toContainText('Interim Visa Issued');await expect(immigration.locator('..').locator('..')).toContainText('1 placed · 0 unstaged · 1 total');
});
