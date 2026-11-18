import {test,expect} from '@playwright/test';
test('cold login loads only its required JavaScript within the initial budget',async({page})=>{
 const errors:string[]=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto('https://luvep.test:8443/login');
 await expect(page.locator('#email')).toBeVisible();
 await expect(page.locator('link[rel=stylesheet][href*="/build/"]')).toHaveCount(1);
 await expect.poll(()=>page.locator('div.min-h-screen').first().evaluate(e=>parseFloat(getComputedStyle(e).minHeight))).toBeGreaterThanOrEqual(720);
 const scripts=await page.evaluate(()=>performance.getEntriesByType('resource').filter((e:any)=>e.name.includes('/build/')&&new URL(e.name).pathname.endsWith('.js')).map((e:any)=>({url:new URL(e.name).pathname,bytes:e.decodedBodySize})));
 const total=scripts.reduce((sum,e)=>sum+e.bytes,0);
 console.log(JSON.stringify({loginScriptBytes:total,scripts}));
 expect(total).toBeGreaterThan(10000);expect(total).toBeLessThan(1500000);expect(errors).toEqual([]);
});
