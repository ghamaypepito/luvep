import { test } from 'node:test';
import assert from 'node:assert/strict';
import { build } from 'esbuild';
import { chromium } from '@playwright/test';

const bundle = await build({stdin:{contents:`import React from 'react'; import {createRoot} from 'react-dom/client'; import PhoneField from './resources/js/components/PhoneField.jsx'; window.values=[]; const root=createRoot(document.getElementById('root')); window.renderField=(value='',key='initial')=>root.render(<PhoneField key={key} value={value} onChange={v=>window.values.push(v)}/>); window.renderField();`,resolveDir:process.cwd(),loader:'jsx'},bundle:true,write:false,format:'iife',jsx:'automatic'});
async function withField(run) {
    const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
    try { const page=await browser.newPage(); await page.setContent('<div id="root"></div>'); await page.addScriptTag({content:bundle.outputFiles[0].text}); await run(page); } finally {await browser.close();}
}
test('pasting an international number selects its country code without duplicating it',async()=>withField(async page=>{
    await page.getByPlaceholder('Phone number').fill('+63 917 555 1234');
    assert.equal(await page.evaluate(()=>window.values.at(-1)),'+63 9175551234');
    assert.equal(await page.getByPlaceholder('Phone number').inputValue(),'9175551234');
}));
test('clearing a phone number emits an empty value for required-field validation',async()=>withField(async page=>{
    await page.getByPlaceholder('Phone number').fill('211234567');
    await page.getByPlaceholder('Phone number').fill('');
    assert.equal(await page.evaluate(()=>window.values.at(-1)),'');
}));

test('country selection preserves a national number and formatted input is normalized before capping digits',async()=>withField(async page=>{
    await page.getByPlaceholder('Phone number').fill('917 555 1234');
    await page.getByRole('button').first().click();
    await page.getByPlaceholder('Search country or code…').fill('Philippines');
    await page.getByRole('button',{name:/Philippines/}).click();
    assert.equal(await page.evaluate(()=>window.values.at(-1)),'+63 9175551234');
    await page.getByPlaceholder('Phone number').fill('12345678901234567890');
    assert.equal(await page.evaluate(()=>window.values.at(-1)),'+63 1234567890123');
}));

test('unsupported international code is preserved on paste, remount, edit, and clear',async()=>withField(async page=>{
    await page.getByPlaceholder('Phone number').fill('+968 9123 4567');
    assert.equal(await page.evaluate(()=>window.values.at(-1)),'+96891234567');
    assert.equal(await page.getByRole('button',{name:'International',exact:true}).count(),1);
    await page.evaluate(()=>window.renderField('+96891234567','remount'));
    await page.waitForFunction(()=>document.querySelector('input[type=tel]').value==='+96891234567');
    await page.getByPlaceholder('Phone number').fill('+96891234568');
    assert.equal(await page.evaluate(()=>window.values.at(-1)),'+96891234568');
    await page.getByPlaceholder('Phone number').fill('');
    assert.equal(await page.evaluate(()=>window.values.at(-1)),'');
}));
test('restored draft and external reset update the displayed phone',async()=>withField(async page=>{
    await page.evaluate(()=>window.renderField('+63 9175551234'));
    await page.waitForFunction(()=>document.querySelector('input[type=tel]').value==='9175551234',{},{timeout:2000});
    await page.evaluate(()=>window.renderField('+96891234567'));
    await page.waitForFunction(()=>document.querySelector('input[type=tel]').value==='+96891234567',{},{timeout:2000});
    await page.evaluate(()=>window.renderField(''));
    await page.waitForFunction(()=>document.querySelector('input[type=tel]').value==='',{},{timeout:2000});
}));
