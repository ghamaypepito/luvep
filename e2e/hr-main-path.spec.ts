import {test,expect} from '@playwright/test';
const origin='https://luvep.test:8443';
test('direct HR link logs in then opens calendar, profile, and attendance on main origin',async({page})=>{
 await page.goto(`${origin}/portal/hr/dashboard`);
 await expect(page).toHaveURL(/\/login$/);
 await page.locator('#email').fill('alex@example.test');await page.locator('#password').fill('Browser-Test-123');await page.locator('button[type=submit]').click();
 await expect(page).toHaveURL(`${origin}/portal/hr/dashboard`);
 await page.getByRole('link',{name:'Calendar',exact:true}).click();await expect(page).toHaveURL(/\/portal\/hr\/calendar/);
 await expect(page.getByRole('heading',{name:'Calendar',exact:true})).toBeVisible();
 await page.getByLabel('Calendar view').selectOption('week');await expect(page).toHaveURL(/view=week/);
 await page.getByLabel('Calendar view').selectOption('day');await expect(page).toHaveURL(/view=day/);
 await page.setViewportSize({width:390,height:844});
 await expect.poll(()=>page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBeTruthy();
 await page.screenshot({path:'/private/tmp/luvep-hr-calendar-mobile.png',fullPage:true});
 await page.setViewportSize({width:1440,height:1000});await page.getByLabel('Calendar view').selectOption('month');await expect(page).toHaveURL(/view=month/);await expect(page.getByLabel('Calendar view')).toHaveValue('month');
 await page.screenshot({path:'/private/tmp/luvep-hr-calendar-desktop.png',fullPage:true});
 await page.getByRole('link',{name:'My profile',exact:true}).click();await expect(page).toHaveURL(/\/portal\/hr\/profile$/);
 await expect(page.getByRole('button',{name:'Clock in',exact:true})).toBeVisible();
 await page.getByRole('button',{name:'Clock in',exact:true}).click();await expect(page.getByText('Working',{exact:true})).toBeVisible();
 await page.getByRole('button',{name:'Start break',exact:true}).click();await expect(page.getByRole('dialog')).toBeVisible();
 await page.getByRole('button',{name:'I’m back · resume work'}).click();await expect(page.getByText('Working',{exact:true})).toBeVisible();
});
test('main-site admin can open setup and safely exit employee preview',async({page})=>{
 await page.goto(`${origin}/portal/hr/dashboard`);await page.locator('#email').fill('admin@example.test');await page.locator('#password').fill('Browser-Test-123');await page.locator('button[type=submit]').click();await expect(page).toHaveURL(/\/portal\/hr\/dashboard$/);
 await page.getByRole('link',{name:'People',exact:true}).click();await expect(page).toHaveURL(/\/portal\/hr\/people$/);
 await page.locator('.hr-profile-trigger').click();await page.getByRole('link',{name:'Login as employee'}).click();await page.getByRole('button',{name:'View dashboard'}).first().click();await expect(page.getByText(/Read-only/)).toBeVisible();
 await page.getByRole('button',{name:'Return to admin'}).click();await expect(page.getByText(/Read-only/)).not.toBeVisible();await expect(page).toHaveURL(/\/portal\/hr\/dashboard$/);
});
