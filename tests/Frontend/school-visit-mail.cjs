const assert = require("node:assert/strict");
const { buildSync } = require("esbuild");
const { JSDOM } = require("jsdom");
const Module = require("node:module");
const path = require("node:path");

const dom = new JSDOM('<div id="root"></div>', { url: "https://luvep.test/portal/education/compose" });
for (const key of ["window", "document", "HTMLElement", "Event", "FormData"]) global[key] = dom.window[key];
Object.defineProperty(global, "navigator", { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;

const React = require("react");
const { act } = React;
const { createRoot } = require("react-dom/client");
const root = createRoot(document.getElementById("root"));
const posts = [];
const inertia = { Head: () => null, router: { post: (url, data, options) => posts.push({ url, data, options }), get: (url,data) => posts.push({url,data,method:"get"}) } };

function load(entryPoint) {
    const output = buildSync({
        entryPoints: [entryPoint], bundle: true, write: false, format: "cjs", platform: "node", jsx: "automatic",
        external: ["react", "react/jsx-runtime", "@inertiajs/react", "sonner", "@/components/templates/*"],
    }).outputFiles[0].text;
    const filename = path.resolve("education-email.cjs");
    const loaded = new Module(filename, module);
    loaded.filename = filename;
    loaded.paths = module.paths;
    loaded.require = name => {
        if (name === "@inertiajs/react") return inertia;
        if (name === "sonner") return { toast: { error: () => {} } };
        if (name.startsWith("@/components/templates/")) return () => null;
        return module.require(name);
    };
    loaded._compile(output, filename);
    return loaded.exports.default;
}


const SchoolMail = load('resources/js/pages/portal/education/SchoolMail.jsx');
const button=label=>[...document.querySelectorAll('button')].find(n=>n.textContent.trim()===label);
const click=node=>act(async()=>{assert.ok(node);node.click();});
const change=(node,value)=>act(async()=>{
    assert.ok(node);Object.getOwnPropertyDescriptor(Object.getPrototypeOf(node),'value').set.call(node,value);
    node.dispatchEvent(new Event(node.tagName==='SELECT'?'change':'input',{bubbles:true}));
});
(async()=>{
    const props={visits:[{id:11,school:'Jose Maria College',activity:'Visit',summary:'Date TBC'},{id:12,school:'Tagum',activity:'Visit',summary:'Date TBC'}],
        filters:{visit_ids:[11],audience:'registered',purpose:'email_follow_up'},
        recipients:[{id:'a'.repeat(64),name:'<Alex> Taylor',email:'alex@example.test',eligible:true,schools:['Jose Maria College'],attendance:'Registered',context:{full_name:'<Alex> Taylor',school:'Jose Maria College'}},
            {id:'b'.repeat(64),name:'Excluded',email:'excluded@example.test',eligible:false,reason:'Missing permission',schools:['Jose Maria College'],attendance:'Registered'}],
        templates:[{id:7,name:'Follow up',subject:'Hello {{full_name}}',body:'<p>{{full_name}} {{school}}</p>',review:'c'.repeat(64),
            from_email:'staff@example.test',from_name:'Education',reply_to_email:'replies@example.test',reply_to_name:'Education Replies',
            shell:'<title>&lt;!-- school-mail-subject --&gt;</title><header>Branding</header><!-- school-mail-body -->'}],mailings:[]};
    await act(async()=>root.render(React.createElement(SchoolMail,props)));
    assert.match(document.body.textContent,/Attendance can be marked automatically/);
    assert.equal(button('Queue 0 emails').disabled,true);
    const excluded=[...document.querySelectorAll('li label')].find(n=>n.textContent.includes('excluded@example.test')).querySelector('input');
    assert.equal(excluded.disabled,true);
    await click(button('Select eligible matches'));
    await change(document.querySelector('[aria-label="Mailing name"]'),'Synthetic follow up');
    await change(document.querySelector('[aria-label="Template"]'),'7');
    assert.match(document.body.textContent,/Hello <Alex> Taylor/);
    assert.match(document.body.textContent,/From: Education <staff@example\.test>/);
    const preview=document.querySelector('[title="Email preview"]');
    assert.ok(preview.hasAttribute('sandbox'));
    assert.match(preview.srcdoc,/<header>Branding<\/header><p>&lt;Alex&gt; Taylor Jose Maria College<\/p>/);
    const confirmation=[...document.querySelectorAll('label')].find(n=>n.textContent.includes('I reviewed')).querySelector('input');
    assert.equal(button('Queue 1 emails').disabled,true);
    await click(confirmation);await click(button('Queue 1 emails'));
    assert.equal(posts.length,1);assert.equal(posts[0].url,'/portal/education/school-mail');
    assert.deepEqual(posts[0].data.recipient_keys,['a'.repeat(64)]);assert.equal(posts[0].data.confirmed,true);
    assert.equal(posts[0].data.template_review,'c'.repeat(64));
    assert.deepEqual(posts[0].data.visit_ids,[11]);assert.equal(posts[0].data.purpose,'email_follow_up');
    await act(async()=>{
        root.render(React.createElement(SchoolMail,{...props,templates:[{...props.templates[0],subject:'Updated {{full_name}}',review:'d'.repeat(64)}]}));
        posts[0].options.onError({template_review:'The template or email branding changed. Review the updated message and confirm again.'});
        posts[0].options.onFinish();
    });
    assert.equal(button('Queue 1 emails').disabled,true);
    assert.equal(confirmation.checked,false);
    assert.match(document.querySelector('[role="alert"]').textContent,/Review the updated message/);
    assert.match(document.body.textContent,/Updated <Alex> Taylor/);
    await change(document.querySelector('[aria-label="Audience"]'),'attended');
    assert.match(document.body.textContent,/Filters changed/);assert.equal(button('Queue 1 emails'),undefined);
    await click(button('Load recipients'));assert.equal(posts[1].method,'get');assert.equal(posts[1].data.audience,'attended');
    await act(async()=>root.unmount());console.log('School visit audience, consent exclusions, review, payload and stale-filter behaviour verified');
})().catch(e=>{console.error(e);process.exitCode=1;});
