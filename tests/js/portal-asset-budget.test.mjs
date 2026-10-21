import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {gzipSync} from 'node:zlib';

test('cold admin dashboard requires at most 15 JS files and 250 KB gzip',()=>{
 const manifest=JSON.parse(readFileSync('public/build/manifest.json','utf8'));
 const seen=new Set();
 function visit(key){assert.ok(manifest[key],`Missing build entry ${key}`);if(seen.has(key))return;seen.add(key);for(const child of manifest[key].imports||[])visit(child);}
 for(const key of ['resources/js/app.jsx','resources/js/pages/admin/Dashboard.jsx','resources/js/components/layout/AdminLayout.jsx'])visit(key);
 const files=[...seen].map(key=>manifest[key].file).filter(file=>file.endsWith('.js'));
 const compressed=files.reduce((sum,file)=>sum+gzipSync(readFileSync('public/build/'+file)).length,0);
 assert.ok(files.length<=15,`${files.length} initial JS files exceeds request budget`);
 assert.ok(compressed<=250000,`${compressed} gzip bytes exceeds payload budget`);
});
