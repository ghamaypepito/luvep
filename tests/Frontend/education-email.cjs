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
const inertia = { Head: () => null, router: { post: (url, data) => posts.push({ url, data }) } };

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

const Compose = load("resources/js/components/email/ComposeView.jsx");
const BulkEmail = load("resources/js/pages/portal/sales/BulkEmail.jsx");
const select = label => [...document.querySelectorAll("select")].find(field => field.textContent.includes(label));
const button = label => [...document.querySelectorAll("button")].find(node => node.textContent.trim() === label);
const click = node => act(async () => { assert.ok(node); node.click(); });
const change = (node, value) => act(async () => {
    assert.ok(node);
    const setter = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(node), "value").set;
    setter.call(node, value);
    node.dispatchEvent(new Event(node.tagName === "SELECT" ? "change" : "input", { bubbles: true }));
});

(async () => {
    const template = {
        id: 12, name: "Welcome", email_subject: "Welcome {{first_name}}",
        email_body: "<p>Start here</p>",
    };
    await act(async () => root.render(React.createElement(Compose, {
        basePath: "/portal/education/compose", templates: [template],
    })));
    await change(document.querySelector('input[placeholder*="Type a name"]'), "synthetic@example.test");
    await click(button("Add synthetic@example.test"));
    await change(select("Choose an email template"), "12");
    assert.equal(document.querySelector('input[placeholder="Subject"]').value, template.email_subject);
    await click(button("Send"));
    assert.equal(posts[0].url, "/portal/education/compose/send");
    assert.deepEqual(Object.fromEntries(posts[0].data), {
        "emails[]": "synthetic@example.test", subject: template.email_subject, body: template.email_body,
    });

    await act(async () => root.render(React.createElement(BulkEmail, {
        basePath: "/portal/education/bulk-email", filterNewLeads: true,
        templates: [{ id: 1, name: "Welcome" }],
        recipients: [
            { id: 1, name: "Eligible", email: "new@example.test", stage: "Consultation Done", status: "New Leads", is_new_lead: true },
            { id: 2, name: "Contacted", email: "contacted@example.test", stage: "New Leads", status: "Contact Attempted", is_new_lead: false },
            { id: 3, name: "Student", email: "student@example.test", stage: "New Leads", status: "New Leads", is_new_lead: false },
        ],
    })));
    await change(document.querySelector('input[placeholder="June re-engagement"]'), "Synthetic campaign");
    await click(button("Select all matching (3)"));
    await change(select("All stages"), "New Leads");
    assert.match(document.body.textContent, /1 selected · 1 shown/);
    assert.match(document.body.textContent, /Eligible/);
    assert.doesNotMatch(document.body.textContent, /contacted@example.test|student@example.test/);
    await click(button("Send · 1 lead"));
    assert.equal(posts[1].url, "/portal/education/bulk-email");
    assert.deepEqual(posts[1].data.recipient_lead_ids, [1]);
    assert.equal(posts[1].data.new_leads_only, true);
    await act(async () => root.unmount());
    console.log("Education Compose template and New Leads recipient selection verified");
})().catch(error => { console.error(error); process.exitCode = 1; });
