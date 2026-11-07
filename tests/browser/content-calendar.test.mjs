import { test } from 'node:test';
import assert from 'node:assert/strict';
import { build } from 'esbuild';
import { readFileSync } from 'node:fs';
import { chromium } from '@playwright/test';

const bundle = await build({
    stdin: {
        contents: `import React from 'react';
            import { createRoot } from 'react-dom/client';
            import ContentCalendar from './resources/js/pages/admin/editorial/ContentCalendar.jsx';
            window.renderCalendar = (props) => createRoot(document.getElementById('root')).render(<ContentCalendar {...props} />);`,
        resolveDir: process.cwd(),
        loader: 'jsx',
    },
    alias: { '@': process.cwd() + '/resources/js' },
    bundle: true,
    write: false,
    format: 'iife',
    jsx: 'automatic',
    loader: { '.css': 'empty' },
    plugins: [{
        name: 'inertia-context',
        setup(build) {
            build.onResolve({ filter: /^@inertiajs\/react$/ }, () => ({ path: 'inertia', namespace: 'test' }));
            build.onLoad({ filter: /.*/, namespace: 'test' }, () => ({
                resolveDir: process.cwd(),
                contents: `import React from 'react';
                    export const Head = () => null;
                    export const Link = ({ href, children, ...props }) => React.createElement('a', { href, ...props }, children);
                    export const router = { reload() {} };`,
            }));
        },
    }],
});

const calendarCss = readFileSync('resources/js/components/editorial/content-calendar.css', 'utf8');

const article = {
    id: 1, title: 'Writing a study plan', slug: 'study-plan', summary: 'Plan the next step.',
    body_html: '<p>Start with your goal.</p>', primary_keyword: 'study plan', meta_title: null,
    meta_description: null, author: 'Luvep', image_url: null, image_alt: null,
    planned_date: '2026-11-08', pillar: 'Study', brief: { prompt: 'Explain the steps.' },
    revision: 1, status: 'draft', published_slug: null,
};
const articlesPath = '/admin/content-calendar/articles';

async function withCalendar(run, { canPublish = false } = {}) {
    const channel = process.env.PLAYWRIGHT_CHANNEL;
    const browser = await chromium.launch({ headless: true, ...(channel ? { channel } : {}) });
    try {
        const page = await browser.newPage();
        page.setDefaultTimeout(4000);
        await page.route('http://calendar.test/admin/content-calendar', route => route.fulfill({
            contentType: 'text/html',
            body: '<div id="root"></div>',
        }));
        await page.route('http://calendar.test' + articlesPath + '/1', route => route.fulfill({ json: article }));
        await page.goto('http://calendar.test/admin/content-calendar');
        await page.addScriptTag({ content: bundle.outputFiles[0].text });
        await page.addStyleTag({ content: calendarCss });
        await page.evaluate(props => window.renderCalendar(props), {
            month: '2026-11',
            topics: [article],
            undated: [{ id: 2, title: 'Unscheduled article', status: 'draft', planned_date: null }],
            canPublish,
        });
        await run(page);
    } finally {
        await browser.close();
    }
}

test('the month shows dated and undated topics and supports keyboard entry and dismissal', async () => {
    await withCalendar(async page => {
        await page.getByRole('heading', { name: 'November 2026' }).waitFor();
        await page.getByText('Mon', { exact: true }).waitFor();
        assert.equal(await page.locator('time[datetime]:visible').count(), 42);
        await page.setViewportSize({ width: 390, height: 844 });
        await page.getByText('Mon', { exact: true }).waitFor({ state: 'hidden' });
        assert.equal(await page.locator('time[datetime]:visible').count(), 1);
        assert.equal(await page.getByRole('link', { name: 'Previous month' }).getAttribute('href'), '/admin/content-calendar?month=2026-10');
        assert.equal(await page.getByRole('link', { name: 'Next month' }).getAttribute('href'), '/admin/content-calendar?month=2026-12');
        await page.getByRole('region', { name: 'Undated drafts' }).getByRole('button', { name: /Unscheduled article/ }).waitFor();

        const entry = page.getByRole('button', { name: /Writing a study plan/ });
        await entry.focus();
        await page.keyboard.press('Enter');
        const dialog = page.getByRole('dialog');
        await dialog.getByLabel('Title', { exact: true }).waitFor();
        assert.equal(await dialog.getByLabel('Title', { exact: true }).inputValue(), article.title);
        await dialog.getByText('Explain the steps.').waitFor();
        await page.keyboard.press('Escape');
        await dialog.waitFor({ state: 'hidden' });
        assert.equal(await entry.evaluate(el => document.activeElement === el), true);
    });
});

test('manual article creation preserves pasted article structure', async () => {
    await withCalendar(async page => {
        await page.route('http://calendar.test' + articlesPath, async route => {
            assert.equal(route.request().method(), 'POST');
            const draft = route.request().postDataJSON();
            assert.equal(draft.title, 'New planning guide');
            assert.match(draft.body_html, /<h2>Planning<\/h2>/);
            assert.match(draft.body_html, /<ul><li>/);
            assert.match(draft.body_html, /href="https:\/\/example.com\/guide"/);
            assert.match(draft.body_html, /<table>.*<th><p>Option<\/p><\/th>.*<td><p>Study<\/p><\/td>.*<\/table>/);
            await route.fulfill({ json: { ...article, ...draft, id: 3 } });
        });

        await page.getByRole('button', { name: 'New article' }).click();
        const dialog = page.getByRole('dialog');
        await dialog.getByLabel('Title', { exact: true }).fill('New planning guide');
        const body = dialog.getByRole('textbox', { name: 'Article body' });
        await body.focus();
        await body.evaluate(element => {
            const clipboardData = new DataTransfer();
            clipboardData.setData('text/html', '<h2>Planning</h2><ul><li>See the <a href="https://example.com/guide">guide</a></li></ul><table><tr><th>Option</th><th>Next step</th></tr><tr><td>Study</td><td>Compare schools</td></tr></table>');
            element.dispatchEvent(new ClipboardEvent('paste', { clipboardData, bubbles: true, cancelable: true }));
        });
        await dialog.getByRole('button', { name: 'Save draft' }).click();
        await dialog.getByRole('status').getByText('Draft saved.').waitFor();
    });
});

test('review uses the saved revision and hides approval from a staff editor', async () => {
    await withCalendar(async page => {
        await page.route('http://calendar.test' + articlesPath + '/1/submit', async route => {
            assert.deepEqual(route.request().postDataJSON(), { revision: 1 });
            await route.fulfill({ json: { ...article, status: 'in_review' } });
        });
        await page.getByRole('button', { name: /Writing a study plan/ }).click();
        const dialog = page.getByRole('dialog');
        await dialog.getByRole('button', { name: 'Submit for review' }).click();
        await dialog.getByText('In review', { exact: true }).waitFor();
        assert.equal(await dialog.getByRole('button', { name: 'Approve' }).count(), 0);
        assert.equal(await dialog.getByRole('button', { name: 'Publish' }).count(), 0);
    });
});
