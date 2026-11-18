import { test, expect, Page } from '@playwright/test';
const origin = 'https://luvep.test:8443';
test.beforeEach(async ({page}) => { page.setDefaultTimeout(10000); });
async function login(page: Page, path: string, email = 'admin@example.test') {
    await page.goto(origin + path);
    await page.locator('#email').fill(email);
    await page.locator('#password').fill('Browser-Test-123');
    await page.locator('button[type=submit]').click();
    await page.waitForURL(url => !url.pathname.includes('login'));
}

test('internal note keeps multiple programs and retired proposal files remain accessible', async ({ page }) => {
    await login(page, '/admin/leads');
    await page.getByPlaceholder('Search Opportunities').fill('Notes');
    await page.getByText('Notes Verification', { exact: true }).first().click();
    await expect(page).toHaveURL(/\/leads\/\d+/);
    const leadUrl = page.url().split('?')[0];
    const panel = page.locator('section').filter({has: page.getByRole('heading', {name:/Internal notes/})});
    await panel.getByLabel('Programs to discuss').selectOption('Browser Nursing');
    await panel.getByLabel('Programs to discuss').selectOption('Browser Business');
    await panel.getByPlaceholder('Type here').fill('Discuss both programs internally.');
    await panel.getByRole('button', {name:'Post',exact:true}).click();
    await expect(panel.getByText('Programs: Browser Nursing · Browser Business')).toBeVisible();
    await page.reload();
    await expect(panel.getByText('Programs: Browser Nursing · Browser Business')).toBeVisible();
    await page.goto(leadUrl + '?tab=documents');
    await page.getByRole('button', {name:/Agreements/}).click();
    await expect(page.getByText('Study Proposal', {exact:true})).toHaveCount(0);
    await expect(page.getByText('Historical study proposal.pdf', {exact:true}).filter({visible:true})).toBeVisible();
    await page.goto(origin + '/track/EP-NOTESBROWSER');
    await page.getByRole('button', {name:/^Requirements/}).first().click();
    for (const title of ['Passport', 'Curriculum Vitae', 'Academic Certificates', 'Academic Transcripts', 'Employment Certificate']) {
        await expect(page.getByRole('row').filter({hasText:title})).toBeVisible();
    }
    await expect(page.getByText('Study Proposal', {exact:true})).toHaveCount(0);
    await expect(page.getByText('Historical study proposal.pdf', {exact:true}).filter({visible:true})).toBeVisible();
    await expect(page.getByText('Discuss both programs internally.', {exact:true})).toHaveCount(0);
    const cvRow = page.getByRole('row').filter({hasText:'Curriculum Vitae'});
    await cvRow.getByRole('button', {name:'Upload',exact:true}).click();
    await page.locator('input[type=file][multiple]').setInputFiles({name:'client-cv.pdf',mimeType:'application/pdf',buffer:Buffer.from('%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF')});
    const uploaded = page.waitForResponse(r=>r.url().includes('/document') && r.request().method()==='POST');
    await page.getByRole('button',{name:'Upload',exact:true}).last().click();
    expect((await uploaded).status()).toBeLessThan(400);
    await expect(page.getByText('client-cv.pdf',{exact:true}).filter({visible:true})).toBeVisible();
});

test('bulk assigning selected leads makes them visible only to their recruiting agent', async ({ page, browser }) => {
    await login(page, '/portal/sales/leads', 'alex@example.test');
    for (const name of ['Bulk Alpha', 'Bulk Beta']) {
        await page.getByRole('row').filter({hasText:name}).getByRole('checkbox').check();
    }
    await page.getByRole('button', {name:'Set / edit agent',exact:true}).click();
    const modal = page.getByRole('heading',{name:'Set recruiting agent',exact:true}).locator('xpath=../../..');
    await modal.getByRole('combobox').selectOption({label:'Notes Recruiting Agent'});
    const saved = page.waitForResponse(r=>r.url().endsWith('/leads/bulk-agent') && r.request().method()==='POST');
    await modal.getByRole('button',{name:'Apply',exact:true}).click();
    expect((await saved).status()).toBeLessThan(400);
    await page.reload();
    for (const name of ['Bulk Alpha','Bulk Beta']) {
        await expect(page.getByRole('row').filter({hasText:name}).getByText('Notes Recruiting Agent',{exact:true})).toBeVisible();
    }
    const context = await browser.newContext({ignoreHTTPSErrors:true});
    const agentPage = await context.newPage();
    try {
        await login(agentPage, '/portal/agent/leads', 'notes-agent@example.test');
        await expect(agentPage.getByText('Bulk Alpha',{exact:true})).toBeVisible();
        await expect(agentPage.getByText('Bulk Beta',{exact:true})).toBeVisible();
        await expect(agentPage.getByText('Private Other Referral',{exact:true})).toHaveCount(0);
    } finally { await context.close(); }
});

