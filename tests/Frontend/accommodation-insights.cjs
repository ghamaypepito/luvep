const assert = require('node:assert/strict');
const { buildSync } = require('esbuild');
const Module = require('node:module');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const dom = new JSDOM('<div id="root"></div>', {url:'https://luvep.test/portal/accommodation/reports'});
for (const key of ['window','document','HTMLElement','Event','MouseEvent']) global[key] = dom.window[key];
Object.defineProperty(global,'navigator',{value:dom.window.navigator,configurable:true});
global.IS_REACT_ACT_ENVIRONMENT = true;
const React = require('react'); const {act} = React; const {createRoot} = require('react-dom/client');
const root = createRoot(document.getElementById('root'));
const output = buildSync({entryPoints:['resources/js/components/accommodation/Insights.jsx'],bundle:true,write:false,
    format:'cjs',platform:'node',jsx:'automatic',external:['react','react/jsx-runtime','@inertiajs/react','lucide-react']}).outputFiles[0].text;
const loaded = new Module(path.resolve('insights-test.cjs'),module);loaded.filename=path.resolve('insights-test.cjs');loaded.paths=module.paths;
loaded.require = name => name==='@inertiajs/react' ? {Link:({children,...props})=>React.createElement('a',props,children)}
    : name==='lucide-react' ? new Proxy({}, {get:()=>()=>null}) : module.require(name);
loaded._compile(output,loaded.filename);
const {PortfolioSummary,OccupancyPanel,ViewingCalendar,TaskActivity} = loaded.exports;
const render=(C,props)=>act(async()=>root.render(React.createElement(C,props)));
(async()=>{
    const incomplete={properties:1,rooms:4,households:2,active_agreements:3,unverified_properties:1,unverified_rooms:4,missing_capacity:0,
        occupied:0,vacant:0,available_to_let:0,unavailable:0,rows:[{id:1,name:'Unverified house',total:4,occupied:null,state:'needs_review'}]};
    await render(OccupancyPanel,{stats:incomplete});
    assert.match(document.body.textContent,/Needs review/);assert.doesNotMatch(document.body.textContent, /0%/);
    assert.equal(document.querySelector('[role="meter"]'),null,'Unknown occupancy has no misleading percentage');
    await render(PortfolioSummary,{stats:{...incomplete,properties:0,rooms:0,households:0,active_agreements:0,unverified_properties:0,rows:[]}});
    assert.match(document.querySelector('[aria-label="Property and room totals"]').textContent,/Active properties0/);
    await render(TaskActivity,{insights:{period:{from:'2026-11-02',to:'2026-11-03'},daily_tasks:[{date:'2026-11-02',created:0,completed:1},{date:'2026-11-03',created:2,completed:0}]}});
    assert.equal(document.querySelector('[data-total="created"]').textContent,'2');
    assert.equal(document.querySelector('[data-total="completed"]').textContent,'1');
    const months=[];
    await render(ViewingCalendar,{insights:{as_of:'2026-11-26',calendar:{month:'2026-11',bookings:[
        ...[1,2,3,4].map(day=>({id:10+day,date:`2026-11-0${day}`,time:'10:30',name:'Earlier visitor '+day,property:'Test property',status:'Completed'})),
        {id:1,date:'2026-11-27',time:'10:30',name:'Synthetic visitor',property:'Test property',status:'Confirmed'}]}},onMonthChange:m=>months.push(m)});
    assert.match(document.body.textContent,/Synthetic visitor/);
    assert.doesNotMatch(document.querySelector('.ai-viewings').textContent,/Earlier visitor/,'Upcoming viewings are not hidden behind earlier bookings');
    await act(async()=>document.querySelector('[aria-label="Next month"]').click());assert.deepEqual(months,['2026-12']);
    await act(async()=>document.querySelector('[aria-label="26 November 2026, 0 viewings"]').click());
    assert.match(document.body.textContent,/No viewings booked for this day/);assert.doesNotMatch(document.body.textContent,/Synthetic visitor/);
    await act(async()=>document.querySelector('[aria-label="27 November 2026, 1 viewing"]').click());assert.match(document.body.textContent,/Synthetic visitor/);
    await render(TaskActivity,{insights:null});assert.match(document.body.textContent,/unavailable/i);
    await act(async()=>root.unmount());dom.window.close();console.log('Accommodation insight React checks passed.');
})().catch(e=>{console.error(e);process.exitCode=1;});
