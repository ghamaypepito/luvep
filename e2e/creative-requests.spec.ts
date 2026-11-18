import { test, expect } from '@playwright/test';

// Uses a seeded local database with MAIL_MAILER=log; never run against production.
const origin = process.env.CREATIVE_E2E_BASE_URL;
const email = process.env.CREATIVE_E2E_EMAIL;
const password = process.env.CREATIVE_E2E_PASSWORD;
test.use({ timezoneId: 'Asia/Manila' });
test.skip(!origin || !/^http:\/\/(127\.0\.0\.1|localhost):\d+$/.test(origin) || !email || !password, 'Requires the seeded local Creative Requests preview.');

test('updated short brief and private references reach the task and DTR without inventing a deadline', async ({ page }) => {
    const title = `Creative browser workflow ${Date.now()}`;
    let dtrWrites = 0;
    page.on('request', request => {
        if (request.method() === 'POST' && new URL(request.url()).pathname === '/portal/hr/dtr') dtrWrites++;
    });
    await page.goto(`${origin}/login`);
    await page.locator('#email').fill(email!);
    await page.locator('#password').fill(password!);
    await page.locator('button[type=submit]').click();
    await page.waitForURL(/admin/);
    await page.goto(`${origin}/admin/system-tickets?type=digital`);
    await page.getByRole('button', { name: 'New creative request', exact: true }).click();
    await expect(page.getByRole('dialog', { name: 'Creative brief ticket', exact: true })).toBeVisible();
    await expect(page.getByLabel('Project name')).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('button', { name: 'New creative request', exact: true })).toBeFocused();
    await page.getByRole('button', { name: 'New creative request', exact: true }).click();
    for (const width of [1440, 768, 390, 320]) {
        await page.setViewportSize({ width, height: 1000 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
        await expect(page.getByRole('button', { name: 'Close', exact: true })).toBeVisible();
    }
    await page.setViewportSize({ width: 1440, height: 1080 });
    await page.getByLabel('Project name').fill(title);
    await page.screenshot({ path: test.info().outputPath('creative-request-popup.png') });
    await expect(page.locator('[name=deadline]')).toHaveCount(0);
    await expect(page.locator('[name=approver]')).toHaveCount(0);
    await page.getByRole('textbox', { name: 'Project overview', exact: false }).fill('Create a consultation campaign graphic.');
    await page.locator('.cw-steps button').nth(1).click();
    await page.getByLabel('Required copy', { exact: false }).fill('Plan your next chapter. #StudyInNZ');
    await page.locator('.cw-steps button').nth(2).click();
    await page.getByLabel('Digital media', { exact: false }).check();
    await page.locator('.cw-steps button').nth(3).click();
    await page.getByLabel('Sample designs / references').setInputFiles({ name: 'reference.txt', mimeType: 'text/plain', buffer: Buffer.from('Reference layout: clear headline and approved logo.') });
    await page.locator('.cw-steps button').nth(4).click();
    await page.getByRole('textbox', { name: 'Curated assets', exact: true }).fill('https://example.test/approved-assets');
    await page.getByLabel('Additional notes', { exact: true }).fill('Use the approved logo.');
    await expect(page.getByRole('button', { name: 'Submit creative request', exact: true })).toBeEnabled();
    await page.getByRole('button', { name: 'Submit creative request', exact: true }).click();
    await page.waitForURL(/creative-requests\/\d+$/);
    const requestUrl = page.url();
    await expect(page.locator('header .cw-eyebrow')).toContainText('Digital · CR-');
    const requestRef = (await page.locator('header .cw-eyebrow').innerText()).match(/CR-[A-Z0-9]+/)![0];
    await expect(page.getByText('Plan your next chapter. #StudyInNZ', { exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Download reference.txt' })).toBeVisible();
    await page.goto(`${origin}/portal/hr/dtr`);
    await expect(page.getByRole('heading', { name: title, exact: true })).toBeVisible();
    const assigned = page.locator('.cw-assigned-row').filter({ has: page.getByRole('heading', { name: title, exact: true }) });
    await assigned.getByRole('button', { name: 'Add to daily record', exact: true }).click();
    const added = page.locator('.hr-task-editor').filter({ has: page.locator('textarea').filter({ hasText: title }) });
    await expect(added.locator('textarea')).toBeFocused();
    await expect(added.locator('input[type=time]').first()).toHaveValue('');
    await expect(added.locator('input[type=time]').last()).toHaveValue('');
    await expect(added.getByLabel('Client or reference')).toHaveValue(requestRef);
    await page.screenshot({ path: test.info().outputPath('creative-request-dtr-draft.png') });
    await assigned.getByRole('button', { name: 'Add to daily record', exact: true }).click();
    await expect(added).toHaveCount(1);
    expect(dtrWrites).toBe(0);
    for (const width of [1440, 768, 390, 320]) {
        await page.setViewportSize({ width, height: 1000 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    }
    await page.setViewportSize({ width: 1440, height: 1080 });
    await page.getByRole('button', { name: 'Cancel editing', exact: true }).click();
    await page.reload();
    await expect(page.locator('.hr-task-editor')).toHaveCount(0);
    expect(dtrWrites).toBe(0);
    await page.goto(`${origin}/admin/tasks?scope=mine`);
    const dueBefore = await page.evaluate((project) => JSON.parse(document.querySelector('#app')!.getAttribute('data-page')!).props.all_tasks.find((task: { title: string }) => task.title === project).due_at, title);
    await page.getByText(title, { exact: true }).first().click();
    await expect(page.getByRole('link', { name: 'Open creative brief, caption & reference designs' })).toBeVisible();
    await page.getByRole('button', { name: 'Save changes', exact: true }).click();
    await expect(page.getByRole('dialog', { name: 'Task details' })).toBeHidden();
    await page.reload();
    const dueAfter = await page.evaluate((project) => JSON.parse(document.querySelector('#app')!.getAttribute('data-page')!).props.all_tasks.find((task: { title: string }) => task.title === project).due_at, title);
    expect(dueBefore).toBeNull();
    expect(dueAfter).toBe(dueBefore);
    await page.goto(requestUrl);
    await expect(page.getByText('No deadline', { exact: true })).toBeVisible();
    for (const width of [1440, 768, 390, 320]) {
        await page.setViewportSize({ width, height: 1000 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    }
    await page.goto(`${origin}/admin/system-tickets?type=digital`);
    const row = page.locator('.cw-request-row').filter({ has: page.getByText(title, { exact: true }) });
    for (const width of [1440, 768, 390, 320]) {
        await page.setViewportSize({ width, height: 1000 });
        await expect(row.getByRole('button', { name: 'Mark delivered', exact: true })).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    }
    await page.setViewportSize({ width: 1440, height: 1080 });
    await row.getByRole('button', { name: 'Delete', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Cancel', exact: true })).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toBeHidden();
    await expect(row.getByRole('button', { name: 'Delete', exact: true })).toBeFocused();
    await row.getByRole('button', { name: 'Mark delivered', exact: true }).click();
    await expect(row.locator('.cw-status')).toHaveText('Delivered');
    await page.goto(`${origin}/portal/hr/dtr`);
    await expect(page.getByRole('heading', { name: title, exact: true })).toHaveCount(0);
    await page.goto(`${origin}/admin/system-tickets?type=digital`);
    await row.getByRole('button', { name: 'Delete', exact: true }).click();
    await page.getByRole('button', { name: 'Delete request', exact: true }).click();
    await expect(row).toHaveCount(0);
    expect((await page.request.get(requestUrl)).status()).toBe(404);
    await page.getByRole('link', { name: 'Deleted requests', exact: true }).click();
    await expect(row.locator('.cw-status')).toHaveText('Delivered');
    await expect(row.getByRole('link')).toHaveCount(0);
    await row.getByRole('button', { name: 'Restore', exact: true }).click();
    await expect(row).toHaveCount(0);
    await page.goto(requestUrl);
    await expect(page.getByText('Plan your next chapter. #StudyInNZ', { exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Download reference.txt' })).toBeVisible();
    await expect(page.locator('header .cw-status')).toHaveText('Delivered');
});
