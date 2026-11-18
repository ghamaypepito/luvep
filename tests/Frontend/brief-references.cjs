const fs=require('fs'),vm=require('vm'),assert=require('assert');
const source=fs.readFileSync('resources/social-workspace/brief-references.js','utf8').split('// Reference picker UI')[0];
class Data {constructor(){this.entries=[]}append(...args){this.entries.push(args)}}
const fields={hidden:false};const ctx={FormData:Data,$:()=>fields};vm.createContext(ctx);vm.runInContext(source,ctx);
const photo={name:'moodboard.png',type:'image/png',size:1200,lastModified:1},note={name:'notes.txt',type:'text/plain',size:300,lastModified:2};
ctx.epAddBriefFiles([photo,note]);assert.equal(ctx.epBriefCapture().length,2);const before=JSON.stringify(ctx.epReferenceSnapshot());
let req=ctx.epBriefRequest({campaign:'Campus tour',channels:['Instagram'],references:ctx.epReferenceSnapshot()},ctx.epBriefCapture());assert.equal(req.headers['Content-Type'],undefined);assert.equal(req.body.entries.filter(x=>x[0]==='references[]').length,2);assert.equal(req.body.entries.find(x=>x[0]==='channels[]')[1],'Instagram');
assert.throws(()=>ctx.epAddBriefFiles([{name:'bad.html',type:'text/html',size:10}]),/PDF/);assert.equal(ctx.epBriefCapture().length,2);
ctx.epRemoveBriefFile(ctx.epReferenceSnapshot()[0].id);assert.notEqual(JSON.stringify(ctx.epReferenceSnapshot()),before);assert.equal(ctx.epBriefCapture().length,1);
fields.hidden=true;assert.equal(ctx.epBriefCapture().length,0);req=ctx.epBriefRequest({campaign:'Quick post'},ctx.epBriefCapture());assert.equal(req.headers['Content-Type'],'application/json');
fields.hidden=false;ctx.epClearBriefReferences();assert.equal(ctx.epBriefCapture().length,0);
assert.throws(()=>ctx.epAddBriefFiles([{name:'long.txt',type:'text/plain',size:51201}]),/50 KB/);
console.log('Reference selection, removal, multipart transport, limits and hidden-mode isolation passed.');
