import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const shellQuote = require('shell-quote');

test('shell quoting rejects line terminators after comment tokens', () => {
    for (const newline of ['\n', '\r', '\u2028', '\u2029']) {
        assert.throws(() => shellQuote.quote(['echo', 'ok', { comment: 'comment' }, `untrusted${newline}command;#`]), TypeError);
    }
    const args = ['node', 'a file with spaces', "client's invoice #1", '$(untrusted)'];
    assert.deepEqual(shellQuote.parse(shellQuote.quote(args)), args);
});

test('Swiper rejects prototype pollution even when array indexOf is modified', () => {
    // Isolate the advisory's modified built-in and payload from the test runner.
    const result = spawnSync(process.execPath, ['--input-type=module', '--eval', `
        import Swiper from 'swiper';
        import assert from 'node:assert/strict';
        const original = Array.prototype.indexOf;
        try {
            Array.prototype.indexOf = () => -1;
            Swiper.extendDefaults(JSON.parse('{"__proto__":{"luvepPollution":"unexpected"}}'));
        } finally {
            Array.prototype.indexOf = original;
        }
        assert.equal(Object.prototype.luvepPollution, undefined);
    `], { encoding: 'utf8' });
    assert.equal(result.status, 0, result.stderr);
});

test('the development runner preserves named commands and failure exit codes', () => {
    const run = (...commands) => spawnSync(process.execPath, [fileURLToPath(new URL('../../node_modules/concurrently/dist/bin/concurrently.js', import.meta.url)),
        '-c', '#93c5fd,#c4b5fd', '--names=server,queue', ...commands], { encoding: 'utf8', timeout: 10000 });
    const command = (code) => shellQuote.quote([process.execPath, '-e', code]);
    const success = run(command('console.log("server-ready")'), command('console.log("queue-ready")'));
    assert.equal(success.status, 0, success.stderr);
    assert.match(success.stdout, /server-ready/);
    assert.match(success.stdout, /queue-ready/);
    const failure = run(command('process.exit(1)'), command('process.exit(0)'));
    assert.notEqual(failure.status, 0);
    assert.equal(failure.error, undefined);
});
