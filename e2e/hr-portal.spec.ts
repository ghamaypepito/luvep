import { test, expect } from '@playwright/test';
const main='https://luvep.test:8443';const hr='https://hr.luvep.test:8443';
async function login(page,email='alex@example.test'){await page.goto(`${main}/login`);await page.locator('#email').fill(email);await page.locator('#password').fill('Browser-Test-123');await page.locator('button[type=submit]').click();await page.waitForURL(u=>!u.pathname.includes('login'));}
test('clock and breaks remain consistent across both portals and reload',async({page})=>{
 await login(page);await expect(page.getByRole('button',{name:'Clock in',exact:true})).toBeVisible();await page.getByRole('button',{name:'Clock in',exact:true}).click();await expect(page.getByText('Working',{exact:true})).toBeVisible();
 await page.getByRole('button',{name:'Start break',exact:true}).click();await expect(page.getByRole('dialog',{name:'You’re on a break'})).toBeVisible();
 await page.reload();await expect(page.getByRole('dialog',{name:'You’re on a break'})).toBeVisible();await page.getByRole('button',{name:'Keep break running'}).click();await expect(page.getByText('Work time is paused.')).toBeVisible();
 await page.goto(hr);await expect(page.getByRole('dialog',{name:'You’re on a break'})).toBeVisible();await page.getByRole('button',{name:'I’m back · resume work'}).click();await expect(page.getByRole('dialog')).not.toBeVisible();await expect(page.getByText('Working',{exact:true})).toBeVisible();
 page.once('dialog',d=>d.accept());await page.getByRole('button',{name:'Clock out',exact:true}).click();await expect(page.getByText('Off shift',{exact:true})).toBeVisible();
 await page.getByRole('link',{name:'My time records',exact:true}).click();await expect(page.getByRole('heading',{name:'My time records'})).toBeVisible();await expect(page.getByLabel('Work location')).toBeVisible();
});
test('admin preview is read only and can return to administration',async({page})=>{
 await login(page,'admin@example.test');await page.goto(hr);await page.locator('.hr-profile-trigger').click();await page.getByRole('link',{name:'Login as employee'}).click();await page.getByRole('button',{name:'View dashboard'}).first().click();await expect(page.getByText(/Viewing Alex Rivera/)).toBeVisible();await expect(page.getByRole('button',{name:'Clock in',exact:true})).not.toBeVisible();await page.getByRole('button',{name:'Return to admin'}).click();await expect(page.getByText(/Viewing Alex Rivera/)).not.toBeVisible();
});
test('mobile employee navigation and forms fit without page overflow',async({page})=>{
 await page.setViewportSize({width:375,height:812});await login(page);await page.goto(`${hr}/leave`);await expect(page.getByRole('heading',{name:'My leave'})).toBeVisible();expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBeTruthy();await expect(page.getByRole('button',{name:'Submit request'})).toBeDisabled();
});

test('failed clock actions show feedback and another tab refreshes the break state',async({page,context})=>{
 await login(page);await page.goto(hr);await page.route('**/clock',route=>route.request().method()==='POST'?route.abort():route.continue());
 await page.getByRole('button',{name:'Clock in',exact:true}).click();await expect(page.getByRole('alert')).toContainText('could not confirm');await expect(page.getByText('Off shift',{exact:true})).toBeVisible();await page.unroute('**/clock');
 await page.getByRole('button',{name:'Clock in',exact:true}).click();await expect(page.getByText('Working',{exact:true})).toBeVisible();const other=await context.newPage();await other.goto(hr);await other.getByRole('button',{name:'Start break',exact:true}).click();await expect(other.getByRole('dialog')).toBeVisible();
 await page.bringToFront();await page.evaluate(()=>window.dispatchEvent(new Event('focus')));await expect(page.getByRole('dialog')).toBeVisible();const before=await page.getByTestId('work-timer').innerText();await page.waitForTimeout(1200);expect(await page.getByTestId('work-timer').innerText()).toBe(before);
 await page.getByRole('button',{name:'I’m back · resume work'}).click();page.once('dialog',d=>d.accept());await page.getByRole('button',{name:'Clock out',exact:true}).click();await expect(page.getByText('Off shift',{exact:true})).toBeVisible();await other.close();
});
