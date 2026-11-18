import { test, expect } from '@playwright/test';

// Uses a seeded local database with MAIL_MAILER=log; never run against production.
const origin = process.env.CREATIVE_E2E_BASE_URL;
const email = process.env.CREATIVE_E2E_EMAIL;
const password = process.env.CREATIVE_E2E_PASSWORD;
test.use({ timezoneId: 'Asia/Manila' });
test.skip(!origin || !/^http:\/\/(127\.0\.0\.1|localhost):\d+$/.test(origin) || !email || !password, 'Requires the seeded local Creative Requests preview.');

test('caption and private references reach the task and DTR, preserving a Manila deadline', async ({ page }) => {
    const title = `Creative browser workflow ${Date.now()}`;
    await page.goto(`${origin}/login`);
    await page.locator('#email').fill(email!);
    await page.locator('#password').fill(password!);
    await page.locator('button[type=submit]').click();
    await page.waitForURL(/admin/);
    await page.goto(`${origin}/admin/system-tickets?type=digital`);
    await page.getByRole('button', { name: 'New creative request', exact: true }).click();
    await page.getByLabel('Project name').fill(title);
    await page.getByLabel('Deadline', { exact: false }).first().fill('2026-11-27');
    await page.getByLabel('What do you need designed?').fill('Create a consultation campaign graphic.');
    await page.locator('.cw-steps button').nth(1).click();
    await page.getByLabel('Caption', { exact: false }).fill('Plan your next chapter. #StudyInNZ');
    await page.locator('.cw-steps button').nth(2).click();
    await page.getByLabel('Social media graphic', { exact: true }).check();
    await page.locator('.cw-steps button').nth(3).click();
    await page.getByLabel('Sample designs / references').setInputFiles({ name: 'reference.txt', mimeType: 'text/plain', buffer: Buffer.from('Reference layout: clear headline and approved logo.') });
    await page.locator('.cw-steps button').nth(5).click();
    await page.getByLabel('Final approver').fill('Marketing lead');
    await page.getByRole('button', { name: 'Submit creative request', exact: true }).click();
    await page.waitForURL(/creative-requests\/\d+$/);
    const requestUrl = page.url();
    await expect(page.getByText('Plan your next chapter. #StudyInNZ', { exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Download reference.txt' })).toBeVisible();
    await page.goto(`${origin}/portal/hr/dtr`);
    await expect(page.getByRole('heading', { name: title, exact: true })).toBeVisible();
    await page.goto(`${origin}/admin/tasks?scope=mine`);
    const dueBefore = await page.evaluate((project) => JSON.parse(document.querySelector('#app')!.getAttribute('data-page')!).props.all_tasks.find((task: { title: string }) => task.title === project).due_at, title);
    await page.getByText(title, { exact: true }).first().click();
    await expect(page.getByRole('link', { name: 'Open creative brief, caption & reference designs' })).toBeVisible();
    await page.getByRole('button', { name: 'Save changes', exact: true }).click();
    await expect(page.getByRole('dialog', { name: 'Task details' })).toBeHidden();
    await page.reload();
    const dueAfter = await page.evaluate((project) => JSON.parse(document.querySelector('#app')!.getAttribute('data-page')!).props.all_tasks.find((task: { title: string }) => task.title === project).due_at, title);
    expect(dueAfter).toBe(dueBefore);
    await page.goto(requestUrl);
    await expect(page.getByText('Project deadline: 2026-11-27', { exact: true })).toBeVisible();
    for (const width of [1440, 768, 390, 320]) {
        await page.setViewportSize({ width, height: 1000 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    }
});
