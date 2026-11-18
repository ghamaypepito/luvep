import { test } from 'node:test';
import assert from 'node:assert/strict';
import { postTaskComment } from '../../resources/js/lib/taskComments.js';

test('comment submission uses the current XSRF cookie after login rotates the token', async () => {
    globalThis.document = {
        cookie: 'XSRF-TOKEN=rotated%3Dtoken',
        querySelector: () => ({ getAttribute: () => 'stale-login-page-token' }),
    };
    let request;
    globalThis.fetch = async (url, options) => {
        request = { url, ...options };
        return { ok: true, json: async () => ({ comment: { id: 7, body: 'Update' } }) };
    };
    assert.deepEqual(await postTaskComment(42, 'Update'), { comment: { id: 7, body: 'Update' } });
    assert.equal(request.url, '/api/tasks/42/comments');
    assert.equal(request.headers['X-XSRF-TOKEN'], 'rotated=token');
    assert.equal(request.headers['X-CSRF-TOKEN'], undefined);
    assert.equal(request.credentials, 'same-origin');
    assert.equal(request.body, '{"body":"Update"}');
    document.cookie = 'XSRF-TOKEN=newer-token';
    await postTaskComment(42, 'Next');
    assert.equal(request.headers['X-XSRF-TOKEN'], 'newer-token');
});

test('failed requests reject without pretending a comment was created', async () => {
    globalThis.document = { cookie: 'XSRF-TOKEN=current', querySelector: () => null };
    globalThis.fetch = async () => ({ ok: false, status: 419 });
    await assert.rejects(postTaskComment(42, 'Update'), /419/);
});
