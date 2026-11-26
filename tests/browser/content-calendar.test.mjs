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
                    export const router = { reload(options) { options?.onFinish?.(); } };`,
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

async function withCalendar(run, { canPublish = false, topics = [article], ghlPosts = [], socialPosts = [] } = {}) {
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
        await page.route('http://calendar.test/social/ghl/posts?*', route => route.fulfill({ json: { posts: ghlPosts, page: 1, has_more: false, synced_at: '2026-11-19T02:00:00Z' } }));
        await page.goto('http://calendar.test/admin/content-calendar');
        await page.addScriptTag({ content: bundle.outputFiles[0].text });
        await page.addStyleTag({ content: calendarCss });
        await page.evaluate(props => window.renderCalendar(props), {
            month: '2026-11',
            topics,
            socialPosts,
            undated: [{ id: 2, title: 'Unscheduled article', status: 'draft', planned_date: null }],
            canPublish,
        });
        await run(page);
    } finally {
        await browser.close();
    }
}

test('GHL posts appear in Manila time with a responsive read-only preview', async () => {
    await withCalendar(async page => {
        const post = page.getByRole('button', { name: /Plan your next chapter.*GHL/ });
        await post.waitFor();
        assert.equal(await post.locator('..').locator('..').locator('..').locator('time').getAttribute('datetime'), '2026-11-01');
        await post.click();
        const modal = page.getByRole('dialog', { name: 'GHL social post' });
        await modal.waitFor();
        assert.equal(await modal.locator('input,textarea').count(), 0);
        assert.equal(await modal.getByRole('link', { name: /Open GHL Social Planner/ }).count(), 1);
        for (const width of [1440, 768, 390, 320]) {
            await page.setViewportSize({ width, height: 844 });
            const box = await modal.boundingBox();
            assert.ok(box.x >= 0 && box.x + box.width <= width, 'GHL preview fits viewport');
            const footer = await modal.locator('footer').boundingBox();
            assert.ok(footer.y + footer.height <= 844, 'GHL actions remain visible');
        }
        await page.getByRole('button', { name: 'Close GHL post' }).click();
        await page.evaluate(() => new Promise(resolve => requestAnimationFrame(resolve)));
        assert.equal(await post.evaluate(node => node === document.activeElement), true);
    }, { ghlPosts: [{ id: 'ghl-post', caption: 'Plan your next chapter with EP.', status: 'published', date: '2026-10-31T16:05:00Z', post_type: 'post', media: [], account_name: 'Luv, Ep' }] });
});

test('the month shows dated and undated topics and supports keyboard entry and dismissal', async () => {
    await withCalendar(async page => {
        await page.getByRole('heading', { name: 'November 2026' }).waitFor();
        await page.getByText('Mon', { exact: true }).waitFor();
        assert.equal(await page.locator('time[datetime]:visible').count(), 42);
        await page.setViewportSize({ width: 390, height: 844 });
        await page.getByText('Mon', { exact: true }).waitFor();
        assert.equal(await page.locator('time[datetime]:visible').count(), 42);
        assert.equal(await page.getByRole('link', { name: 'Previous month' }).getAttribute('href'), '/admin/content-calendar?month=2026-10');
        assert.equal(await page.getByRole('link', { name: 'Next month' }).getAttribute('href'), '/admin/content-calendar?month=2026-12');
        await page.getByRole('region', { name: 'Undated drafts' }).getByRole('button', { name: /Unscheduled article/ }).waitFor();

        const entry = page.getByRole('button', { name: /Writing a study plan/ });
        await entry.focus();
        await page.keyboard.press('Enter');
        const dialog = page.getByRole('dialog');
        await dialog.getByLabel('Title', { exact: true }).waitFor();
        assert.equal(await dialog.getByLabel('Title', { exact: true }).inputValue(), article.title);
        assert.equal(await dialog.getByLabel('Article brief', { exact: true }).inputValue(), 'Explain the steps.');
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
            assert.match(draft.body_html, /<img[^>]*src="https:\/\/example.com\/campus.jpg"[^>]*alt="Campus courtyard"/);
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
        await dialog.getByRole('button', { name: 'Add image' }).click();
        await dialog.getByLabel('Image URL', { exact: true }).fill('https://example.com/campus.jpg');
        await dialog.getByLabel('Image description', { exact: true }).fill('Campus courtyard');
        await dialog.getByRole('button', { name: 'Insert image' }).click();
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


test('dragging a draft moves its planned date and a draft with a live version stays locked', async () => {
    await withCalendar(async page => {
        // Keep both drag endpoints visible; Chromium can cancel native drags on target autoscroll.
        await page.setViewportSize({ width: 1280, height: 1200 });
        let requested = false;
        await page.route('http://calendar.test' + articlesPath + '/1/planned-date', async route => {
            assert.equal(route.request().method(), 'PATCH');
            assert.deepEqual(route.request().postDataJSON(), { revision: 1, planned_date: '2026-11-12' });
            requested = true;
            await route.fulfill({ json: { ...article, planned_date: '2026-11-12', revision: 2 } });
        });
        const draft = page.getByRole('button', { name: /Writing a study plan/ });
        const locked = page.getByRole('button', { name: /Live article/ });
        assert.equal(await draft.getAttribute('draggable'), 'true');
        assert.notEqual(await locked.getAttribute('draggable'), 'true');
        const target = page.locator('.editorial-calendar-day').filter({ has: page.locator('time[datetime="2026-11-12"]') });
        await draft.dragTo(target);
        await page.getByRole('status').getByText(/Moved to.*November 12/).waitFor();
        assert.equal(requested, true);
    }, { topics: [article, { ...article, id: 9, title: 'Live article', published_slug: 'live-article' }] });
});


test('a social post in review can be dragged or moved with the date field', async () => {
    const id = '12345678-1234-1234-1234-123456789abc';
    const title = 'Plan with Luvep';
    await withCalendar(async page => {
        await page.setViewportSize({ width: 1280, height: 1200 });
        let saved = { id, campaign: title, status: 'in_review', can_move: true, version: 3, calendar_date: '2026-11-08', calendar_time: '09:00', caption: 'Original copy' };
        const payloads = [];
        const url = 'http://calendar.test/social/marketing/posts/' + id;
        await page.route('http://calendar.test/social/ghl/accounts', route => route.fulfill({ json: { publishing_enabled: true, accounts: [] } }));
        await page.route(url, route => route.fulfill({ json: { post: saved } }));
        await page.route(url + '/planned-date', async route => {
            assert.equal(route.request().method(), 'PATCH');
            const data = route.request().postDataJSON();
            payloads.push(data);
            saved = { ...saved, calendar_date: data.planned_date, version: saved.version + 1 };
            await route.fulfill({ json: { post: saved } });
        });
        const post = page.getByRole('button', { name: title + ', Social post, For review', exact: true });
        const scheduled = page.getByRole('button', { name: /Sent already.*Scheduled in GHL/ });
        assert.equal(await post.getAttribute('draggable'), 'true');
        assert.equal(await scheduled.getAttribute('draggable'), 'false');
        await post.dragTo(page.locator('.editorial-calendar-day').filter({ has: page.locator('time[datetime="2026-11-12"]') }));
        await page.getByText(/Moved to.*November 12/).waitFor();
        await post.focus();
        await page.keyboard.press('Enter');
        const dialog = page.getByRole('dialog', { name: 'Social post' });
        const date = dialog.getByLabel('Date · Manila');
        await date.waitFor();
        assert.equal(await date.inputValue(), '2026-11-12');
        await date.fill('2026-12-08');
        await dialog.getByRole('button', { name: 'Save date', exact: true }).click();
        await dialog.getByText('Date updated in Luvep.').waitFor();
        assert.equal(await dialog.getByLabel('Time · Manila').inputValue(), '09:00');
        assert.equal(await dialog.getByLabel('Caption', { exact: true }).inputValue(), 'Original copy');
        assert.deepEqual(payloads, [
            { version: 3, planned_date: '2026-11-12' },
            { version: 4, planned_date: '2026-12-08' },
        ]);
    }, { socialPosts: [
        { id, title, status: 'in_review', can_move: true, version: 3, planned_date: '2026-11-08', planned_time: '09:00' },
        { id: 'scheduled', title: 'Sent already', status: 'scheduled', can_move: false, planned_date: '2026-11-09', planned_time: '09:00' },
    ] });
});

test('editable brief generates article and SEO without saving, then saves the reviewed brief and metadata', async () => {
    await withCalendar(async page => {
        let generations = 0, saves = 0;
        await page.route('http://calendar.test/admin/content-calendar/generate', async route => {
            generations++;
            assert.equal(route.request().postDataJSON().brief.prompt, 'Help prospective students prepare questions for their first EP conversation.');
            await route.fulfill({json:{draft:true,article:{title:'Plan your EP conversation',slug:'plan-your-ep-conversation',summary:'Prepare your questions.',body_html:'<h2>Your next step</h2><p>Ask the EP team.</p>',primary_keyword:'study planning',meta_title:'Study planning with EP',meta_description:'Prepare for a conversation with EP.',author:'EP Editorial Team',pillar:'Study',image_alt:'An EP study planning graphic'},visual_direction:'A calm green layout.',slides:[{headline:'Plan your next step',supporting_copy:'Bring your questions to EP.',cta:'Talk to EP',alt:'EP planning graphic'}]}});
        });
        await page.route('http://calendar.test'+articlesPath, async route => {
            saves++; const data=route.request().postDataJSON();assert.equal(data.brief.prompt,'Help prospective students prepare questions for their first EP conversation.');assert.equal(data.meta_title,'Study planning with EP');
            await route.fulfill({json:{...data,id:4,status:'draft',revision:1}});
        });
        await page.getByRole('button',{name:'New article',exact:true}).click();const dialog=page.getByRole('dialog',{name:'New article',exact:true});
        await dialog.getByLabel('Article brief',{exact:true}).fill('Help prospective students prepare questions for their first EP conversation.');
        await dialog.getByRole('button',{name:'Generate article & SEO',exact:true}).click();
        await dialog.getByLabel('Title',{exact:true}).filter({visible:true}).waitFor();
        await page.waitForFunction(()=>document.querySelector('#article-title').value==='Plan your EP conversation');
        assert.equal(await dialog.getByLabel('Primary keyword',{exact:true}).inputValue(),'study planning');assert.equal(await dialog.getByLabel('Meta title',{exact:true}).inputValue(),'Study planning with EP');
        assert.equal(generations,1);assert.equal(saves,0,'Generation is not an article write');
        await dialog.getByRole('button',{name:'Save draft',exact:true}).click();await dialog.getByRole('status').getByText('Draft saved.',{exact:true}).waitFor();assert.equal(saves,1);
    },{topics:[]});
});

test('a failed generation preserves the current article and editable brief', async () => {
    await withCalendar(async page => {
        await page.route('http://calendar.test/admin/content-calendar/generate', route=>route.fulfill({status:503,json:{message:'The AI connection is unavailable. Your draft has not changed.'}}));
        page.on('dialog',dialog=>dialog.accept());
        await page.getByRole('button',{name:/Writing a study plan/}).click();const dialog=page.getByRole('dialog');
        await dialog.getByLabel('Article brief',{exact:true}).fill('Write a new article about preparing questions for EP.');
        await dialog.getByRole('button',{name:'Generate article & SEO',exact:true}).click();await dialog.getByRole('alert').getByText('The AI connection is unavailable. Your draft has not changed.').waitFor();
        assert.equal(await dialog.getByLabel('Title',{exact:true}).inputValue(),article.title);assert.equal(await dialog.getByRole('textbox',{name:'Article body',exact:true}).textContent(),'Start with your goal.');
        assert.equal(await dialog.getByLabel('Article brief',{exact:true}).inputValue(),'Write a new article about preparing questions for EP.');
    });
});

test('native visual previews, image uploads and carousel selections stay editable and fit small screens', async () => {
    await withCalendar(async page => {
        const errors=[];page.on('pageerror',error=>errors.push(error.message));
        const manifest=JSON.parse(readFileSync('public/build/manifest.json','utf8'));await page.addStyleTag({content:readFileSync('public/build/'+manifest['style.css'].file,'utf8')});
        await page.route('http://calendar.test/social/workspace-test/v1/ep-layout-renderer.js?*',route=>route.fulfill({contentType:'text/javascript',body:readFileSync('resources/social-workspace/ep-layout-renderer.js')}));
        await page.route('http://calendar.test/social/workspace-test/v1/assets/logo.png',route=>route.fulfill({contentType:'image/png',body:readFileSync('resources/social-workspace/assets/logo.png')}));
        await page.route('https://calendar.test/uploaded-*.png',route=>route.fulfill({contentType:'image/png',body:readFileSync('resources/social-workspace/assets/logo.png')}));
        let imageWrites=0;
        await page.route('http://calendar.test/admin/content-calendar/images',async route=>{
            const body=route.request().postDataBuffer();const count=(body.toString().match(/name="images\[\]"/g)||[]).length;assert.ok(count>=1&&count<=5);assert.ok(body.includes(Buffer.from([137,80,78,71])),'Real PNG bytes are uploaded');imageWrites++;
            await route.fulfill({status:201,json:{images:Array.from({length:count},(_,i)=>({url:`https://calendar.test/uploaded-${i}.png`,alt:''}))}});
        });
        await page.getByRole('button',{name:'New article',exact:true}).click();const dialog=page.getByRole('dialog',{name:'New article',exact:true});
        await dialog.getByLabel('Title',{exact:true}).fill('Prepare for your EP conversation');await dialog.getByLabel('Summary',{exact:true}).fill('Bring your questions and plan the next step.');
        await dialog.getByLabel('Visual format',{exact:true}).selectOption('carousel');await dialog.getByRole('button',{name:'Build visual preview',exact:true}).click();
        await dialog.getByRole('button',{name:'Use carousel',exact:true}).waitFor();assert.equal(imageWrites,0,'Preview stays local');
        assert.equal(await dialog.getByRole('link',{name:'Download PNG',exact:true}).count(),3);
        const dimensions=await dialog.getByRole('link',{name:'Download PNG',exact:true}).first().locator('..').locator('..').getByRole('img').evaluate(image=>[image.naturalWidth,image.naturalHeight]);assert.deepEqual(dimensions,[1080,1080]);
        await dialog.getByRole('button',{name:'Use carousel',exact:true}).click();await dialog.getByLabel('Image 3 description',{exact:true}).waitFor();assert.equal(imageWrites,1);
        await dialog.getByLabel('Image 1 description',{exact:true}).fill('Our edited carousel cover');assert.equal(await dialog.getByLabel('Image alt text',{exact:true}).inputValue(),'Our edited carousel cover');
        await dialog.getByLabel('Upload article images',{exact:true}).setInputFiles([{name:'first.png',mimeType:'image/png',buffer:readFileSync('resources/social-workspace/assets/logo.png')},{name:'second.png',mimeType:'image/png',buffer:readFileSync('resources/social-workspace/assets/logo.png')}]);
        await page.waitForFunction(()=>document.querySelectorAll('[aria-labelledby="article-visual-heading"] label').length>0);await dialog.getByLabel('Image 2 description',{exact:true}).waitFor();assert.equal(imageWrites,2);
        assert.equal(await dialog.getByLabel('Image 3 description',{exact:true}).count(),0);
        for(const width of [1440,768,390,320]){await page.setViewportSize({width,height:900});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);const box=await dialog.boundingBox();assert.ok(box.x>=0&&box.x+box.width<=width,'Editor fits '+width);const footer=await dialog.locator('footer').boundingBox();assert.ok(footer.y+footer.height<=900,'Actions stay visible');}
        assert.deepEqual(errors,[]);
    },{topics:[]});
});
