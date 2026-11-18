const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const handlers = {};
let axiosHandler;
const calls = [];
class Form { constructor() { this.action='https://luvep.com/download'; this.fields=[]; } querySelector() { return this.fields[0]; } appendChild(f) { this.fields.push(f); } }
const sandbox = {
  URL, Headers, Request, HTMLFormElement: Form,
  document: { addEventListener: (name, handler) => handlers[name] = handler, createElement: () => ({}) },
  window: { location: { origin: 'https://luvep.com', href: 'https://luvep.com/admin/users' }, fetch: (...args) => { calls.push(args); return Promise.resolve(); } },
};
vm.createContext(sandbox);
vm.runInContext(fs.readFileSync('resources/js/lib/userView.js','utf8').replaceAll('export function','function'),sandbox);
sandbox.installUserViewRequests({on:(name,handler)=>handlers[name]=handler},{interceptors:{request:{use:fn=>axiosHandler=fn}}});
sandbox.updateUserView({props:{userView:{token:'view-one'}}});
const visit={headers:{}}; handlers.before({detail:{visit}}); assert.equal(visit.headers['X-Luvep-User-View'],'view-one');
assert.equal(axiosHandler({url:'/notes',headers:{}}).headers['X-Luvep-User-View'],'view-one');
assert.equal(axiosHandler({url:'https://external.test/notes',headers:{}}).headers['X-Luvep-User-View'],undefined);
sandbox.window.fetch('/notes',{method:'POST'}); assert.equal(calls.at(-1)[1].headers.get('X-Luvep-User-View'),'view-one');
sandbox.window.fetch('https://external.test/notes',{method:'POST'}); assert.equal(calls.at(-1)[1].headers,undefined);
const form=new Form(); handlers.submit({target:form}); assert.equal(form.fields[0].value,'view-one');
sandbox.updateUserView({props:{userView:{token:'view-two'}}}); handlers.submit({target:form}); assert.equal(form.fields.length,1); assert.equal(form.fields[0].value,'view-two');
sandbox.updateUserView({props:{}}); assert.equal(axiosHandler({url:'/notes',headers:{}}).headers['X-Luvep-User-View'],'none');
assert.match(fs.readFileSync('resources/js/pages/portal/immigration/ClientDocuments.jsx','utf8'),/form\.requestSubmit\(\)/);
console.log('User view request-context tests passed');
