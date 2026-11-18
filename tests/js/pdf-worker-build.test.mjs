import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

test('PDF worker is emitted as JavaScript supported by the production static server', () => {
    const manifest = JSON.parse(readFileSync('public/build/manifest.json', 'utf8'));
    const source = 'node_modules/pdfjs-dist/build/pdf.worker.min.mjs';
    const worker = manifest[source];
    assert.ok(worker, 'PDF worker must be included in the production build');
    // Production nginx serves .mjs as application/octet-stream, which browsers
    // reject for module workers. Preserve the module bytes under a .js filename.
    assert.match(worker.file, /\.js$/, 'PDF worker needs the server-supported JavaScript extension');
    assert.deepEqual(readFileSync('public/build/' + worker.file), readFileSync(source));
});
