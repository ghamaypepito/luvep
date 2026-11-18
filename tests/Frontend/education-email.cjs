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
const gets = [];
const inertia = { Head: () => null, router: {
    post: (url, data, options) => posts.push({ url, data, options }),
    get: (url, data) => gets.push({ url, data }),
} };

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
const chooseFiles = async files => {
    const input = document.querySelector('input[type="file"]');
    let selected = files;
    Object.defineProperty(input, "files", { configurable: true, get: () => selected });
    Object.defineProperty(input, "value", {
        configurable: true,
        get: () => selected.length ? `C:\\fakepath\\${selected[0].name}` : "",
        set: value => { if (value === "") selected = []; },
    });
    await act(async () => input.dispatchEvent(new Event("change", { bubbles: true })));
};

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
    await chooseFiles([new dom.window.File(["first"], "first.pdf", { type: "application/pdf" })]);
    await chooseFiles([new dom.window.File(["second"], "second.txt", { type: "text/plain" })]);
    assert.match(document.body.textContent, /first.pdf/);
    assert.match(document.body.textContent, /second.txt/, "A second file selection stays attached after the input resets");
    await click(document.querySelector('[aria-label="Remove first.pdf"]'));
    assert.doesNotMatch(document.body.textContent, /first.pdf/);
    await chooseFiles([new dom.window.File(["first"], "first.pdf", { type: "application/pdf" })]);
    await chooseFiles([]);
    assert.equal(document.querySelector('[aria-label="Selected attachments"]').children.length, 2);
    assert.match(document.querySelector('main > div > section[aria-label="Attachments"]').textContent, /Attachments \(2\)/);
    await click(button("Send"));
    assert.equal(posts[0].url, "/portal/education/compose/send");
    assert.deepEqual(posts[0].data.getAll("attachments[]").map(file => file.name), ["second.txt", "first.pdf"]);
    assert.deepEqual(Object.fromEntries([...posts[0].data].filter(([key]) => key !== "attachments[]")), {
        "emails[]": "synthetic@example.test", subject: template.email_subject, body: template.email_body,
    });
    await act(async () => {
        posts[0].options.onError({ "attachments.1": "The attachment is too large." });
        posts[0].options.onFinish();
    });
    assert.match(document.querySelector('[role="alert"]').textContent, /attachment is too large/);
    assert.match(document.body.textContent, /first.pdf/);
    assert.match(document.body.textContent, /second.txt/);
    assert.equal(document.querySelector('input[placeholder="Subject"]').value, template.email_subject);
    await click(button("Send"));
    await act(async () => {
        posts[1].options.onSuccess();
        posts[1].options.onFinish();
    });
    assert.equal(document.querySelector('[aria-label="Selected attachments"]').children.length, 0);
    assert.equal(document.querySelector('[role="alert"]'), null);

    await act(async () => root.render(React.createElement(BulkEmail, {
        basePath: "/portal/education/bulk-email", filterNewLeads: true,
        templates: [{ id: 1, name: "Welcome" }],
        recipients: [
            { id: 1, name: "Eligible", email: "new@example.test", stage: "Consultation Done", status: "New Leads", is_new_lead: true },
            { id: 2, name: "Contacted", email: "contacted@example.test", stage: "New Leads", status: "Contact Attempted", is_new_lead: false },
            { id: 3, name: "Student", email: "student@example.test", stage: "New Leads", status: "New Leads", is_new_lead: false },
        ],
    })));
    assert.match(document.querySelector('#education-bulk-send-help').textContent, /Enter a campaign name/);
    assert.equal(document.querySelector('input[placeholder="June re-engagement"]').required, true);
    await click(button("Select all matching (3)"));
    assert.equal(button("Send \u00b7 3 leads").disabled, true, "Selected recipients alone do not bypass required campaign name");
    assert.match(document.querySelector('#education-bulk-send-help').textContent, /placeholder/);
    await click(button("Send now"));
    assert.equal(button("Send \u00b7 3 leads").disabled, true, "Timing selector does not start a campaign");
    await change(document.querySelector('input[placeholder="June re-engagement"]'), "Synthetic campaign");
    assert.equal(button("Send \u00b7 3 leads").disabled, false);
    await click(button("Schedule"));
    assert.match(document.querySelector('#education-bulk-send-help').textContent, /scheduled date and time/);
    assert.equal(button("Schedule campaign \u00b7 3 leads").disabled, true);
    await click(button("Send now"));
    await change(select("All stages"), "New Leads");
    assert.match(document.body.textContent, /1 selected · 1 shown/);
    assert.match(document.body.textContent, /Eligible/);
    assert.doesNotMatch(document.body.textContent, /contacted@example.test|student@example.test/);
    await click(button("Send · 1 lead"));
    assert.equal(posts[2].url, "/portal/education/bulk-email");
    assert.deepEqual(posts[2].data.recipient_lead_ids, [1]);
    assert.equal(posts[2].data.new_leads_only, true);
    await act(async () => { posts[2].options.onError({ name: "Campaign could not be started. Please retry." }); posts[2].options.onFinish(); });
    assert.match(document.querySelector('[role="alert"]').textContent, /Please retry/);
    assert.equal(document.querySelector('input[placeholder="June re-engagement"]').value, "Synthetic campaign");
    assert.equal(button("Send \u00b7 1 lead").disabled, false, "Failed request retains the draft and allows retry");

    await act(async () => root.render(React.createElement(BulkEmail, {
        key: "search-all-recipients",
        basePath: "/portal/education/bulk-email",
        templates: [{ id: 1, name: "Welcome" }],
        recipients: Array.from({ length: 505 }, (_, id) => ({
            id, name: `Recipient ${id}`, email: `recipient${id}@example.test`,
        })),
    })));
    assert.doesNotMatch(document.body.textContent, /recipient504@example.test/);
    await change(document.querySelector('[aria-label="Search recipients"]'), "recipient504@example.test");
    assert.match(document.body.textContent, /0 selected · 1 shown/);
    assert.match(document.body.textContent, /recipient504@example.test/);
    await click(button("Select all matching (1)"));
    assert.match(document.body.textContent, /1 selected · 1 shown/);
    const listProps = {
        basePath: "/portal/education/bulk-email", filterNewLeads: true,
        templates: [{ id: 1, name: "Welcome" }], events: [{ id: 8, name: "Open evening" }],
        educationVisits: [{ id: 8, name: "Recent school visit" }],
        recipientLists: [
            { id: 10, name: "November event", mode: "smart", filters: { event_id: 8, registered_from: "2026-11-17", registered_to: "2026-11-18" }, lead_ids: [] },
            { id: 11, name: "Chosen people", mode: "custom", filters: [], lead_ids: [2, 99] },
        ],
        recipients: [
            { id: 1, name: "In range", email: "range@example.test", registered_on: "2026-11-17", event_id: 8 },
            { id: 2, name: "Different event", email: "other@example.test", registered_on: "2026-11-18", event_id: 9 },
            { id: 3, name: "Earlier", email: "earlier@example.test", registered_on: "2026-11-16", event_id: 8 },
            { id: 4, name: "Latest", email: "latest@example.test", registered_on: "2026-11-18", event_id: 8 },
        ],
    };
    await act(async () => root.render(React.createElement(BulkEmail, { ...listProps, key: "saved-lists" })));
    await change(document.querySelector('[aria-label="Saved recipient list"]'), "10");
    assert.match(document.body.textContent, /2 selected · 2 shown/);
    assert.doesNotMatch(document.querySelector('ul').textContent, /Different event|Earlier/);
    await change(document.querySelector('[aria-label="Lead registered from"]'), "2026-11-18");
    assert.match(document.body.textContent, /1 selected · 1 shown/);
    await click(button("Select all matching (1)"));
    await change(document.querySelector('input[placeholder="June re-engagement"]'), "Scoped campaign");
    await click(button("Send · 1 lead"));
    assert.deepEqual(posts.at(-1).data.recipient_lead_ids, [4], "Narrowing a smart list also narrows the send payload");
    await act(async () => posts.at(-1).options.onFinish());
    await click(button("Save list"));
    assert.equal(posts.at(-1).url, "/portal/education/bulk-email/recipient-lists");
    assert.equal(posts.at(-1).data.list_id, "10");
    assert.equal("lead_ids" in posts.at(-1).data, false, "Smart lists save rules without submitting discarded member IDs");
    assert.equal(posts.at(-1).data.filters.registered_from, "2026-11-18");
    await act(async () => { posts.at(-1).options.onSuccess(); posts.at(-1).options.onFinish(); });
    await click(button("Send · 1 lead"));
    assert.deepEqual(posts.at(-1).data.recipient_lead_ids, [4], "Saving narrowed smart rules keeps the send selection narrowed");
    await act(async () => posts.at(-1).options.onFinish());
    await change(document.querySelector('[aria-label="Saved recipient list"]'), "10");
    await change(document.querySelector('[aria-label="Lead registered from"]'), "2026-11-18");
    await click(button("Save as a new list"));
    await click(button("Send · 1 lead"));
    assert.deepEqual(posts.at(-1).data.recipient_lead_ids, [4], "Starting a new list keeps the narrowed selection");
    await act(async () => posts.at(-1).options.onFinish());
    await change(document.querySelector('[aria-label="Saved recipient list"]'), "11");
    assert.match(document.body.textContent, /1 selected · 4 shown/);
    assert.equal(document.querySelector('[aria-label="Lead registered from"]').value, "");
    await click(button("Save list"));
    assert.equal(posts.at(-1).data.mode, "custom");
    assert.deepEqual(posts.at(-1).data.lead_ids, [2], "Unavailable saved members are excluded");
    await act(async () => root.render(React.createElement(BulkEmail, {
        ...listProps, key: "reopen-smart-list",
        recipients: [...listProps.recipients, { id: 5, name: "New match", email: "new@example.test", event_id: 8, registered_on: "2026-11-18" }],
    })));
    await change(document.querySelector('[aria-label="Saved recipient list"]'), "10");
    assert.match(document.body.textContent, /3 selected · 3 shown/, "Reopened smart lists include new matching leads");
    await change(document.querySelector('[aria-label="Saved recipient list"]'), "");
    await change(document.querySelector('[aria-label="Search recipients"]'), "range@example.test");
    await click(button("Select all matching (1)"));
    await change(document.querySelector('[aria-label="Search recipients"]'), "other@example.test");
    await click(button("Select all matching (1)"));
    await change(document.querySelector('input[placeholder="June re-engagement"]'), "Manual selections");
    await click(button("Send · 2 leads"));
    assert.deepEqual(posts.at(-1).data.recipient_lead_ids, [1, 2], "Ordinary campaigns preserve manual selections across searches");
    await change(document.querySelector('[aria-label="Filter recipients by event"]'), "8");
    assert.equal(gets.length, 0, "General event IDs still filter leads in place");
    await change(document.querySelector('[aria-label="Filter recipients by event"]'), "visit:8");
    assert.deepEqual(gets.at(-1), {
        url: "/portal/education/school-mail",
        data: { visit_ids: [8], audience: "registered", purpose: "email_follow_up" },
    }, "Education visits open their exact mailing audience without colliding with general event IDs");
    for (const props of [
        { basePath: "/portal/sales/bulk-email", channel: "email" },
        { basePath: "/portal/immigration/bulk-email", channel: "email" },
        { basePath: "/portal/education/sms", channel: "sms" },
    ]) {
        await act(async () => root.render(React.createElement(BulkEmail, { ...props, key: props.basePath, templates: [{ id: 1, name: "Fixture" }], recipients: [] })));
        assert.equal(document.querySelector('#education-bulk-send-help'), null, "Education email help stays out of other portals/SMS");
        assert.equal(document.querySelector('input[placeholder="June re-engagement"]').required, false);
    }
    await act(async () => root.unmount());
    console.log("Education Compose templates, consecutive attachments, removal, retry and New Leads recipients verified");
})().catch(error => { console.error(error); process.exitCode = 1; });
