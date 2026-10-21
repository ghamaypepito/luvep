import { test, expect } from '@playwright/test';

test('weekly immigration report displays named outcomes and LIA endorsements', async ({ page }) => {
    await page.goto('https://luvep.test:8443/portal/immigration/reports');
    await page.locator('#email').fill('admin@example.test');
    await page.locator('#password').fill('Browser-Test-123');
    await page.locator('button[type=submit]').click();
    await expect(page).toHaveURL(/portal\/immigration\/reports/);
    await expect(page.getByRole('button', { name: 'This week', exact: true })).toBeVisible();
    await expect(page.getByText('Endorsed to LIA', { exact: true }).first()).toBeVisible();
    await expect(page.getByText('Endorsed to Hendry', { exact: true })).toHaveCount(0);
    await expect(page.getByText('Endorsed for assessment', { exact: true })).toHaveCount(0);
    const outcomes = page.getByText('Visas approved', { exact: true }).first().locator('..').locator('..');
    // No hovering: the visible cards themselves identify every outcome.
    for (const [label, name] of [['Visas approved', 'Report Approved'], ['Interim visa granted', 'Report Interim'], ['Request for Information (RFI)', 'Report RFI'], ['Visa declined', 'Report Declined']]) {
        const card = page.getByText(label, { exact: true }).first().locator('..');
        await expect(card.getByText(name, { exact: true })).toBeVisible();
    }
    const range = await page.getByRole('button', { name: 'This week', exact: true }).boundingBox();
    const outcomesBox = await outcomes.boundingBox();
    expect(range!.y).toBeLessThan(outcomesBox!.y);
});
