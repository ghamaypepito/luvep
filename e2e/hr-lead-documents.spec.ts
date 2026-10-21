import { test, expect } from '@playwright/test';

// The HR harness provides an isolated application database and synthetic lead.
// Exercise the real shared Sales/Education profile, upload, threads and review.
test('lead documents support add, upload, reviewer notes, reject and delete', async ({ page }) => {
    const origin = 'https://luvep.test:8443';
    await page.goto(`${origin}/admin/leads`);
    await page.locator('#email').fill('admin@example.test');
    await page.locator('#password').fill('Browser-Test-123');
    await page.locator('button[type=submit]').click();
    await expect(page).toHaveURL(/admin\/leads/);
    await page.getByText('Age Verification', { exact: true }).first().click();
    await expect(page).toHaveURL(/\/leads\/\d+/);
    const leadId = new URL(page.url()).pathname.match(/leads\/(\d+)/)![1];

    await page.goto(`${origin}/portal/sales/leads/${leadId}?tab=documents`);
    await page.getByRole('button', { name: 'Add document', exact: true }).click();
    await page.getByPlaceholder('e.g. Marriage Certificate').fill('Browser review evidence');
    await page.getByPlaceholder('e.g. Personal Documents').fill('Browser documents');
    let saved = page.waitForResponse(r => /\/documents\/custom$/.test(r.url()) && r.request().method() === 'POST');
    await page.getByRole('button', { name: 'Add document', exact: true }).last().click();
    expect((await saved).status()).toBeLessThan(400);
    await page.getByRole('button', { name: /Browser documents/ }).click();
    let row = page.getByRole('row').filter({ hasText: 'Browser review evidence' });
    await expect(row).toBeVisible();
    saved = page.waitForResponse(r => /\/documents\/checklist\/.+\/upload$/.test(r.url()) && r.request().method() === 'POST');
    await row.locator('input[type=file]').first().setInputFiles({
        name: 'review-evidence.pdf', mimeType: 'application/pdf',
        buffer: Buffer.from('%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF'),
    });
    expect((await saved).status()).toBeLessThan(400);
    await expect(row.getByTitle('More', { exact: true })).toBeVisible();

    await page.goto(`${origin}/portal/education/leads/${leadId}?tab=documents`);
    await page.getByRole('button', { name: /Browser documents/ }).click();
    row = page.getByRole('row').filter({ hasText: 'Browser review evidence' });
    await row.getByRole('button', { name: 'Add a note', exact: true }).click();
    await page.getByPlaceholder('Write a note about this document…').fill('Browser verified reviewer note');
    saved = page.waitForResponse(r => /\/threads$/.test(r.url()) && r.request().method() === 'POST');
    await page.getByRole('button', { name: 'Post note', exact: true }).click();
    expect((await saved).status()).toBeLessThan(400);
    await expect(page.getByText('Browser verified reviewer note', { exact: true })).toBeVisible();

    await page.goto(`${origin}/admin/leads/${leadId}?tab=documents`);
    await page.getByRole('button', { name: /Browser documents/ }).click();
    row = page.getByRole('row').filter({ hasText: 'Browser review evidence' });
    await row.getByTitle('More', { exact: true }).click();
    page.once('dialog', dialog => dialog.accept('Wrong initial assessment file'));
    saved = page.waitForResponse(r => /\/documents\/\d+\/status$/.test(r.url()) && r.request().method() === 'POST');
    await page.getByRole('button', { name: 'Reject file', exact: true }).click();
    expect((await saved).status()).toBeLessThan(400);
    await expect(page.getByText('Document rejected', { exact: true })).toBeVisible();
    const reviewed = await page.evaluate(async id => (await fetch(`/admin/leads/${id}/documents/json`)).json(), leadId);
    expect(reviewed.documents).toHaveLength(1);
    expect(reviewed.documents[0]).toMatchObject({ status: 'Rejected', note: 'Wrong initial assessment file' });

    await row.getByTitle('More', { exact: true }).click();
    page.once('dialog', dialog => dialog.accept());
    saved = page.waitForResponse(r => /\/documents\/\d+$/.test(r.url()) && r.request().method() === 'DELETE');
    await page.getByRole('button', { name: 'Delete file', exact: true }).click();
    expect((await saved).status()).toBeLessThan(400);
    await expect(row.getByTitle('More', { exact: true })).toHaveCount(0);
    const remaining = await page.evaluate(async id => (await fetch(`/admin/leads/${id}/documents/json`)).json(), leadId);
    expect(remaining.documents).toEqual([]);
});
