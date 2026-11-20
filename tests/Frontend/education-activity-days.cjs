const assert = require("node:assert/strict");
const { buildSync } = require("esbuild");
const { JSDOM } = require("jsdom");
const Module = require("node:module");
const path = require("node:path");
const { webcrypto } = require("node:crypto");

const dom = new JSDOM('<div id="root"></div>', { url: "https://luvep.test/portal/education/reports?preset=this_week" });
for (const key of ["window", "document", "HTMLElement", "Event", "MouseEvent"]) global[key] = dom.window[key];
Object.defineProperty(global, "navigator", { value: dom.window.navigator, configurable: true });
if (!global.crypto) Object.defineProperty(global, "crypto", { value: webcrypto });
global.IS_REACT_ACT_ENVIRONMENT = true;
const React = require("react");
const { act } = React;
const { createRoot } = require("react-dom/client");
const posts = [], patches = [], deletes = [], visits = [];
let searchFails = false;
global.fetch = async () => ({
    ok: !searchFails,
    json: async () => ({ clients: [{ id: 7, name: "Synthetic Student" }] }),
});
const inertia = { router: {
    post: (url, data, options) => posts.push({ url, data, options }),
    patch: (url, data, options) => patches.push({ url, data, options }),
    delete: (url, options) => deletes.push({ url, options }),
    get: (url, data, options) => visits.push({ url, data, options }),
} };
const output = buildSync({
    entryPoints: ["resources/js/components/education/PersonalReport.jsx"],
    bundle: true, write: false, format: "cjs", platform: "node", jsx: "automatic",
    external: ["react", "react/jsx-runtime", "@inertiajs/react"],
}).outputFiles[0].text;
const loaded = new Module(path.resolve("education-personal-report.cjs"), module);
loaded.filename = path.resolve("education-personal-report.cjs"); loaded.paths = module.paths;
loaded.require = name => name === "@inertiajs/react" ? inertia : module.require(name);
loaded._compile(output, loaded.filename);
const Report = loaded.exports.default;
const root = createRoot(document.getElementById("root"));

const byLabel=label=>document.querySelector('[aria-label="'+label+'"]');
const button=text=>[...document.querySelectorAll('button')].find(n=>n.textContent.trim()===text);
const render=data=>act(async()=>root.render(React.createElement(Report,{data})));
const click=node=>act(async()=>{assert.ok(node);node.click();});
const change=(node,value)=>act(async()=>{assert.ok(node);Object.getOwnPropertyDescriptor(Object.getPrototypeOf(node),'value').set.call(node,value);node.dispatchEvent(new Event('change',{bubbles:true}));});
const data={today:'2026-11-17',notes_week_start:'2026-11-16',notes:{},contacts:{},
    kinds:{enrolment:'Enrolments',called:'Clients called'},statuses:[],counts:{enrolment:{label:'Enrolments',events:4,clients:2}},
    daily_counts:{'2026-11-17':3,'2026-11-16':1},entries:{data:[
        {id:1,kind:'enrolment',date:'2026-11-17',client:'Client A',details:'First enrolment',edit_version:'v1'},
        {id:2,kind:'enrolment',date:'2026-11-17',client:'Client A',details:'Another enrolment',edit_version:'v2'},
        {id:3,kind:'enrolment',date:'2026-11-16',client:'Client B',details:'Previous day',edit_version:'v3'},
    ],current_page:1,last_page:2,next_page_url:'/portal/education/reports?preset=this_week&activity_page=2',prev_page_url:null}};
(async()=>{
    await render(data);
    let groups=[...document.querySelectorAll('details[data-activity-day]')];
    assert.deepEqual(groups.map(g=>g.dataset.activityDay),['2026-11-17','2026-11-16']);
    assert.ok(groups.every(g=>!g.open),'Days start collapsed');
    assert.match(groups[0].querySelector('summary').textContent,/17 Nov 2026.*3 activities/);
    assert.match(groups[1].querySelector('summary').textContent,/16 Nov 2026.*1 activity/);
    assert.match(groups[0].textContent,/Showing 2 of 3 activities/,'Daily totals must not be mistaken for this page count');
    assert.equal(groups[0].querySelectorAll('tbody tr').length,2,'Repeat activities remain separate');
    assert.equal(posts.length+patches.length+deletes.length+visits.length,0,'Grouping must not write or navigate');
    await click(groups[0].querySelector('summary'));assert.equal(groups[0].open,true);
    await render({...data,daily_counts:{...data.daily_counts,'2026-11-17':4}});
    groups=[...document.querySelectorAll('details[data-activity-day]')];
    assert.equal(groups[0].open,true,'Report refresh retains an opened day');
    assert.match(groups[0].querySelector('summary').textContent,/4 activities/);
    byLabel('Edit activity 1').focus();await click(byLabel('Edit activity 1'));
    assert.ok(byLabel('Edit accomplishment'));
    await click(groups[0].querySelector('summary'));assert.equal(groups[0].open,false);
    await click(button('Cancel edit'));
    assert.equal(document.activeElement,groups[0].querySelector('summary'),'Focus must not return to a hidden edit button');
    await click(groups[0].querySelector('summary'));await click(byLabel('Remove activity 2'));
    assert.equal(deletes[0].url,'/portal/education/reports/activities/2');
    await act(async()=>deletes[0].options.onFinish());
    await click(button('Next activities'));assert.equal(visits.at(-1).url,data.entries.next_page_url);
    window.history.replaceState(null,'','?preset=this_week&activity_page=2');
    await change(byLabel('Filter accomplishment list'),'called');
    assert.equal(visits.at(-1).data.activity_kind,'called');assert.equal(visits.at(-1).data.preset,'this_week');
    assert.equal(visits.at(-1).data.activity_page,undefined);
    await render({...data,entries:{data:[],current_page:1,last_page:1},daily_counts:{}});
    assert.equal(document.querySelectorAll('details[data-activity-day]').length,0);
    assert.match(document.body.textContent,/No accomplishments recorded for you/);
    await act(async()=>root.unmount());console.log('Education daily groups: collapse/expand, full-day counts, repeated rows, refresh, hidden-row edit focus, remove, filters and pagination passed');
})().catch(async error=>{console.error(error);await act(async()=>root.unmount());process.exitCode=1;});
