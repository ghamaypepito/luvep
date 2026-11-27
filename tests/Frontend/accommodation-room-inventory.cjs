const assert = require('node:assert/strict');
const { buildSync } = require('esbuild');
const Module = require('node:module');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/portal/accommodation/dashboard' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'MouseEvent']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
const React = require('react'); const { act } = React; const { createRoot } = require('react-dom/client');
const posts = []; const empty = () => null;
const inertia = { Head: empty, Link: ({ children, ...props }) => React.createElement('a', props, children), router: { post: (...args) => posts.push(args) } };
function load(file, exportName = 'default') {
    const output = buildSync({ entryPoints: [file], bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic',
        external: ['react', 'react/jsx-runtime', '@inertiajs/react', 'lucide-react'] }).outputFiles[0].text;
    const loaded = new Module(path.resolve('room-inventory-test.cjs'), module); loaded.filename = path.resolve('room-inventory-test.cjs'); loaded.paths = module.paths;
    loaded.require = name => name === '@inertiajs/react' ? inertia : name === 'lucide-react' ? new Proxy({}, { get: () => empty }) : module.require(name);
    loaded._compile(output, loaded.filename); return loaded.exports[exportName];
}
const Dashboard = load('resources/js/pages/portal/accommodation/Dashboard.jsx');
const PortfolioSummary = load('resources/js/components/accommodation/Insights.jsx', 'PortfolioSummary');
const Verify = load('resources/js/components/accommodation/RoomVerification.jsx');
const capacities = [5,6,4,4,4,4,4,3,1,5,3], households = [5,4,3,2,1,3,4,1,1,10,4];
const rows = capacities.map((total, index) => ({ id:index+1, name:`Synthetic Property ${index+1}`, total, households:households[index],
    occupied:total-(index===1?1:index===3?2:0), vacant:index===1?1:index===3?2:0, available_to_let:index===1?1:index===3?2:0,
    unavailable:0, state:'verified', observed_on:'2026-11-24', unlabelled_households:households[index], rental_mode:'per_room' }));
const stats = { properties:11, rooms:43, households:38, active_agreements:0, occupied:40, vacant:3, available_to_let:3, unavailable:0,
    unverified_properties:0, unverified_rooms:0, missing_capacity:0, rows };
const immutable = JSON.stringify(stats);
const root = createRoot(document.getElementById('root'));
const render = (Page, props) => act(async () => root.render(React.createElement(Page,props)));
const card = label => [...document.querySelector('[aria-label="Property and room totals"]').children].find(n=>n.querySelector('p')?.textContent===label);
const value = label => card(label).querySelectorAll('p')[1].textContent;
const change = (label, value) => act(async () => {
    const node=document.querySelector(`[aria-label="${label}"]`); assert.ok(node,label);
    Object.getOwnPropertyDescriptor(Object.getPrototypeOf(node),'value').set.call(node,value);
    node.dispatchEvent(new Event(node.tagName==='SELECT'?'change':'input',{bubbles:true}));
});
(async () => {
    await render(Dashboard,{inventoryStats:stats, propertyStats:{available:9,unavailable:3}, applicationStats:{total:12}, viewingTotal:8});
    const expectedCards = [['Total Active Properties','11'],['Total Rooms','43'],['Total Active Agreements','38'],['Total Occupied Rooms','40'],['Total Vacant Rooms','3'],['Total Leads','12'],['Total Viewings','8']];
    assert.deepEqual([...document.querySelector('[aria-label="Property and room totals"]').children].map(node=>node.querySelector('p').textContent), expectedCards.map(([label])=>label));
    for(const [label,expected] of expectedCards) assert.equal(value(label),expected);
    assert.equal(card('Total Leads').getAttribute('href'),'/portal/accommodation/onboarding');
    assert.equal(card('Total Viewings').getAttribute('href'),'/portal/accommodation/viewings');
    await act(async()=>card('Total Vacant Rooms').click());
    assert.equal(document.querySelectorAll('#room-inventory tbody tr').length,2);
    assert.ok(document.querySelector('#room-inventory a[href="/portal/accommodation/properties/4"]'));
    assert.match(document.body.textContent,/room labels/);
    await change('Room inventory filter','all');
    await render(Dashboard,{inventoryStats:{...stats,occupied:35,unverified_properties:1,unverified_rooms:5,rows:[{...rows[0],state:'needs_review',occupied:null,vacant:null}]}});
    assert.equal(value('Total Occupied Rooms'),'—'); assert.equal(value('Total Vacant Rooms'),'—');
    assert.match(document.querySelector('[role="status"]').textContent,/not assumed vacant/);
    await render(Dashboard,{inventoryStats:null,inventoryError:'Totals unavailable'});
    assert.equal(value('Total Rooms'),'—'); assert.equal(document.querySelector('[role="alert"]').textContent,'Totals unavailable');
    await render(PortfolioSummary,{stats});
    assert.equal(value('Current household tenancies'),'38');
    assert.equal(value('Active tenant agreements'),'0');
    assert.ok(card('Available rooms to let'));
    assert.equal(JSON.stringify(stats),immutable); assert.equal(posts.length,0,'Dashboard filtering/rendering is read-only');
    const summary={total:5,households:1,occupied:null,vacant:null,unavailable:null,state:'unverified',installed:true,version:'a'.repeat(64),today:'2026-11-24',unlabelled_households:1};
    await render(Verify,{propertyId:1,summary,canVerify:false}); assert.equal(document.querySelector('form'),null);
    await render(Verify,{propertyId:1,summary,canVerify:true});
    assert.equal(document.querySelector('[aria-label="Occupied rooms"]').value,'','Unknown occupancy is blank, not zero');
    await change('Occupied rooms','4'); await change('Vacant rooms','1'); await change('Unavailable rooms','0'); await change('Source / reason','Approved register');
    await act(async()=>document.querySelector('input[type="checkbox"]').click());
    await act(async()=>document.querySelector('form').dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));
    assert.equal(posts.length,1);assert.equal(posts[0][0],'/portal/accommodation/properties/1/room-occupancy');
    assert.equal(posts[0][1].unavailable,'0');assert.equal(posts[0][1].version,summary.version);assert.equal(posts[0][1].confirm,true);
    await act(async()=>{posts[0][2].onError({version:'Record changed. Refresh.'});posts[0][2].onFinish();});
    assert.match(document.querySelector('[role="alert"]').textContent,/Record changed/);
    assert.equal(document.querySelector('[aria-label="Occupied rooms"]').value,'4','Failed save retains entered counts');
    await act(async()=>root.unmount());dom.window.close();
    console.log('Accommodation room inventory actual React checks passed: separate totals, vacancy navigation, unknown/error states, role visibility, versioned confirmation and failed-save retention.');
})().catch(error=>{console.error(error);process.exitCode=1;});
