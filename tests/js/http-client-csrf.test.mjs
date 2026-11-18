import { test } from 'node:test';
import assert from 'node:assert/strict';
import { postJson } from '../../resources/js/lib/http.js';

test('message composer posts with the current cookie after an Inertia login rotates the session', async () => {
    globalThis.document = { cookie: 'XSRF-TOKEN=rotated%3Dtoken', querySelector: () => ({ getAttribute: () => 'stale-html-token' }) };
    let request;
    globalThis.fetch = async (url, options) => { request = {url, ...options}; return {ok:true,status:200,json:async()=>({count:1})}; };
    assert.deepEqual(await postJson('/admin/leads/42/compose', {channel:'email',template_id:7}), {ok:true,status:200,data:{count:1}});
    assert.equal(request.headers['X-XSRF-TOKEN'], 'rotated=token');
    assert.equal(request.headers['X-CSRF-TOKEN'], undefined, 'A stale HTML header must not override the current cookie');
    assert.equal(request.credentials, 'same-origin');
    assert.equal(request.body, '{"channel":"email","template_id":7}');
    document.cookie = 'XSRF-TOKEN=newest';
    await postJson('/admin/leads/42/compose', {});
    assert.equal(request.headers['X-XSRF-TOKEN'], 'newest');
});

test('without an XSRF cookie the JSON client retains the HTML-token fallback', async () => {
    globalThis.document = {cookie:'',querySelector:()=>({getAttribute:()=> 'html-only-token'})};
    let headers;
    globalThis.fetch = async (url, options) => { headers=options.headers; return {ok:false,status:422,json:async()=>({message:'Invalid template'})}; };
    assert.deepEqual(await postJson('/admin/leads/42/compose', {}), {ok:false,status:422,data:{message:'Invalid template'}});
    assert.equal(headers['X-CSRF-TOKEN'],'html-only-token');
    assert.equal(headers['X-XSRF-TOKEN'],undefined);
});
