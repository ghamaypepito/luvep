import {test,expect} from '@playwright/test';
const main='https://luvep.test:8443';
test('lazy pages preserve public and department layouts without runtime errors',async({page})=>{
 const errors:string[]=[];page.on('pageerror',e=>errors.push(e.message));
 for(const path of ['/','/activities','/login']){
  const response=await page.goto(main+path);expect(response?.status()).toBe(200);await expect(page.locator('#app')).not.toBeEmpty();
 }
 await page.locator('#email').fill('admin@example.test');await page.locator('#password').fill('Browser-Test-123');await page.locator('button[type=submit]').click();await page.waitForURL(u=>!u.pathname.includes('login'));
 for(const path of ['/admin/dashboard','/portal/immigration/dashboard','/portal/sales/dashboard','/portal/education/dashboard','/portal/english/dashboard','/portal/accommodation/dashboard','/portal/finance/dashboard','/portal/hr/dashboard']){
  const response=await page.goto(main+path);expect(response?.status(),path).toBe(200);await expect(page.locator('#app')).not.toBeEmpty();await expect(page.getByRole('button',{name:/sign out|log\s*out/i}).first()).toBeVisible();
 }
 expect(errors).toEqual([]);
});
