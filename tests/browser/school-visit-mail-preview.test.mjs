import assert from 'node:assert/strict';
import { test } from 'node:test';
import { chromium } from '@playwright/test';
import { build } from 'esbuild';

const bundle = await build({
    stdin: {
        contents: `import React from 'react';
            import { createRoot } from 'react-dom/client';
            import SchoolMail from './resources/js/pages/portal/education/SchoolMail.jsx';
            createRoot(document.getElementById('root')).render(<SchoolMail
                filters={{visit_ids:[1],audience:'registered',purpose:'email_follow_up'}}
                recipients={[{id:'1',name:'Attendee Person',email:'private-attendee@example.test',eligible:true,schools:['Private school'],attendance:'Registered',
                    context:{full_name:'Attendee Person',email:'private-attendee@example.test',school:'Private school'}}]}
                templates={[{id:7,name:'Follow up',subject:'Hello {{full_name}}',review:'reviewed',
                    shell:'<!doctype html><html><head><title>&lt;!-- school-mail-subject --&gt;</title></head><body><!-- school-mail-body --></body></html>',
                    body:'<p>{{full_name}} from {{school}}</p><img src="https://tracker.example.test/{{email}}"><style>@import "https://tracker.example.test/style.css";</style>'}]}
            />);`,
        resolveDir: process.cwd(),
        loader: 'jsx',
    },
    bundle: true,
    write: false,
    jsx: 'automatic',
    plugins: [{ name: 'inertia-preview-fixture', setup(builder) {
        builder.onResolve({ filter: /^@inertiajs\/react$/ }, () => ({ path: 'inertia', namespace: 'fixture' }));
        builder.onLoad({ filter: /.*/, namespace: 'fixture' }, () => ({ contents: 'export const Head = () => null; export const router = {};' }));
    } }],
});

test('school email preview keeps attendee data out of the iframe and blocks remote resources', async () => {
    const channel = process.env.PLAYWRIGHT_CHANNEL;
    const browser = await chromium.launch({ headless: true, ...(channel ? { channel } : {}) });
    try {
        const page = await browser.newPage();
        const remoteRequests = [];
        await page.route('https://tracker.example.test/**', route => {
            remoteRequests.push(route.request().url());
            return route.abort();
        });
        await page.route('https://luvep.test/**', route => route.fulfill({ contentType: 'text/html', body: '<div id="root"></div>' }));
        await page.goto('https://luvep.test/');
        await page.addScriptTag({ content: bundle.outputFiles[0].text });
        await page.getByRole('button', { name: 'Select eligible matches' }).click();
        await page.getByLabel('Template').selectOption('7');

        const preview = page.locator('iframe[title="Email preview"]');
        assert.doesNotMatch(await preview.getAttribute('srcdoc'), /Attendee Person|private-attendee@example\.test|Private school/);
        const frame = preview.contentFrame();
        await frame.getByText('Jamie Rivera from Example College').waitFor();
        assert.equal(await frame.locator('img').evaluate(image => image.decode().then(() => true, () => false)), false);
        assert.deepEqual(remoteRequests, []);
    } finally {
        await browser.close();
    }
});
