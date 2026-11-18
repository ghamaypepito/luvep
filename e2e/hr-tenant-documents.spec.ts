import { test, expect } from '@playwright/test';

// Uses the isolated SQLite fixtures/server from playwright.hr.config.ts.
test('accommodation staff can upload and download a tenancy document', async ({ page }) => {
    await page.goto('https://luvep.test:8443/portal/accommodation/tenants');
    await page.locator('#email').fill('admin@example.test');
    await page.locator('#password').fill('Browser-Test-123');
    await page.locator('button[type=submit]').click();
    await expect(page).toHaveURL(/portal\/accommodation\/tenants/);
    await page.evaluate(async () => {
        const token = decodeURIComponent(document.cookie.split('; ').find(x => x.startsWith('XSRF-TOKEN='))!.slice(11));
        const headers = { 'X-XSRF-TOKEN': token, 'Content-Type': 'application/json', Accept: 'application/json' };
        const response = await fetch('/portal/accommodation/properties', { method: 'POST', headers, body: JSON.stringify({
            name: 'Synthetic document test house', rental_mode: 'per_room', room_type: 'single', bed_type: 'single', bathroom_type: 'shared', rent_single: 200, status: 'available', is_active: true,
        }) });
        if (!response.ok) throw new Error(`Property setup failed: ${response.status}`);
    });
    await page.goto('https://luvep.test:8443/portal/accommodation/tenants/create');
    const propertyId = await page.locator('select').first().locator('option').filter({ hasText: 'Synthetic document test house' }).getAttribute('value');
    expect(propertyId).toBeTruthy();
    const destination = await page.evaluate(async (propertyId) => {
        const token = decodeURIComponent(document.cookie.split('; ').find(x => x.startsWith('XSRF-TOKEN='))!.slice(11));
        const response = await fetch('/portal/accommodation/tenants', { method: 'POST', headers: { 'X-XSRF-TOKEN': token, 'Content-Type': 'application/json', Accept: 'text/html' }, body: JSON.stringify({ property_id: propertyId, first_name: 'Synthetic', family_name: 'Document', contract_type: 'fixed_term' }) });
        if (!response.ok) throw new Error(`Tenant setup failed: ${response.status}`);
        return response.url;
    }, propertyId);
    await page.goto(destination);
    await page.getByRole('button', { name: 'Documents', exact: true }).click();
    await expect(page.getByText('No documents uploaded for this tenancy yet.')).toBeVisible();
    await page.getByLabel('Document type', { exact: true }).selectOption('tenancy_agreement');
    const content = Buffer.from('%PDF-1.4\nSynthetic tenancy document\n%%EOF');
    await page.getByLabel('File (PDF, JPG, PNG, Word; up to 10 MB)', { exact: true }).setInputFiles({ name: 'synthetic-agreement.pdf', mimeType: 'application/pdf', buffer: content });
    await page.getByRole('button', { name: 'Upload document', exact: true }).click();
    await expect(page.getByText('synthetic-agreement.pdf', { exact: true })).toBeVisible();
    const downloadEvent = page.waitForEvent('download');
    await page.getByRole('link', { name: 'Download', exact: true }).click();
    const download = await downloadEvent;
    expect(download.suggestedFilename()).toBe('synthetic-agreement.pdf');
    expect(await download.failure()).toBeNull();
});
