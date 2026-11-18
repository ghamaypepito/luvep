import {test,expect} from '@playwright/test';
const origin='https://luvep.test:8443';
test('support can discuss, document, resolve and review analytics',async({page,context})=>{
 await page.goto(origin+'/admin/system-tickets');await page.locator('#email').fill('admin@example.test');await page.locator('#password').fill('Browser-Test-123');await page.locator('button[type=submit]').click();await expect(page).toHaveURL(/admin\/system-tickets/);
 const created=await page.evaluate(async()=>{const token=decodeURIComponent(document.cookie.split('; ').find(x=>x.startsWith('XSRF-TOKEN='))!.slice(11));const r=await fetch('/tickets',{method:'POST',headers:{'X-XSRF-TOKEN':token,'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({title:'Browser support workflow',description:'Synthetic test only',category:'bug',priority:'high'})});return r.ok;});expect(created).toBeTruthy();
 await page.reload();await page.getByRole('button',{name:'Browser support workflow',exact:true}).click();
 await page.getByLabel('Reply',{exact:true}).fill('Support is investigating.');await page.getByRole('button',{name:'Send reply',exact:true}).click();await expect(page.getByText('Support is investigating.',{exact:true})).toBeVisible();
 await page.getByLabel('Issue summary',{exact:true}).fill('Synthetic save issue');await page.getByLabel('Root cause',{exact:true}).fill('Synthetic cause');await page.getByLabel('Resolution',{exact:true}).fill('Synthetic fix');await page.getByLabel('Verification',{exact:true}).fill('Verified in browser');await page.getByLabel('Documentation summary',{exact:true}).fill('Reusable instructions');await page.getByRole('button',{name:'Save resolution report'}).click();await expect(page.getByRole('dialog').getByText('Resolution report saved.',{exact:true})).toBeVisible();
 await page.getByRole('combobox',{name:'Status',exact:true}).selectOption('done');await page.getByRole('button',{name:'Save & notify department'}).click();await expect(page.getByRole('dialog')).toHaveCount(0);
 await page.getByRole('link',{name:'Analytics',exact:true}).click();await expect(page.getByRole('heading',{name:/Analytics/i}).first()).toBeVisible();
 await page.screenshot({path:'/private/tmp/luvep-ticket-analytics.png',fullPage:true});
});
