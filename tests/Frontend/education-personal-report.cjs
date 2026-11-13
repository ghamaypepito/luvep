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
const posts = [], deletes = [], visits = [];
let searchFails = false;
global.fetch = async () => ({
    ok: !searchFails,
    json: async () => ({ clients: [{ id: 7, name: "Synthetic Student" }] }),
});
const inertia = { router: {
    post: (url, data, options) => posts.push({ url, data, options }),
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
const data = {
    today: "2026-11-12", notes_week_start: "2026-11-09",
    notes: { accomplishments: "", other_activities: "", next_week_tasks: "" },
    kinds: { new_lead: "New leads recorded", follow_up: "Follow-ups recorded manually", status_update: "Other status changes", called: "Clients called", messaged: "Clients messaged", enrolment: "Enrolments / additional enrolments", offer: "Offers received", proposal: "Proposals sent / additional proposals" },
    statuses: ["Conditional Offer", "School Enrolment"],
    counts: { enrolment: { label: "Enrolments / additional enrolments", events: 2, clients: 1 } },
    contacts: { "Contact Attempted": [{ id: 8, name: "My Lead", date: "2026-11-11T22:00:00Z" }], Contacted: [] },
    entries: { data: [
        { id: 1, kind: "enrolment", date: "2026-11-10", client: "Synthetic Student", details: "School A" },
        { id: 2, kind: "enrolment", date: "2026-11-11", client: "Synthetic Student", details: "School B" },
    ], current_page: 1, last_page: 2, next_page_url: "/portal/education/reports?preset=this_week&activity_page=2", prev_page_url: null },
};
const render = next => act(async () => root.render(React.createElement(Report, { data: next })));
const byLabel = label => document.querySelector('[aria-label="' + label + '"]');
const button = text => [...document.querySelectorAll("button")].find(node => node.textContent.trim() === text);
const click = node => act(async () => { assert.ok(node); node.dispatchEvent(new MouseEvent("click", { bubbles: true })); });
const change = (node, value) => act(async () => {
    assert.ok(node);
    const setter = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(node), "value").set;
    setter.call(node, value);
    node.dispatchEvent(new Event(node.tagName === "SELECT" ? "change" : "input", { bubbles: true }));
});
const submit = node => act(async () => node.dispatchEvent(new Event("submit", { bubbles: true, cancelable: true })));
(async () => {
    await render(data);
    assert.match(byLabel("My activity totals").textContent, /2 activities · 1 distinct clients \/ names/);
    assert.equal(document.querySelectorAll("tbody tr").length, 2, "Repeated enrolments must keep separate rows");
    assert.match(byLabel("My lead contact activity").textContent, /My Lead/);
    await submit(byLabel("Record an accomplishment"));
    assert.equal(posts.length, 0);
    assert.match(document.body.textContent, /choose a client first/);
    await change(byLabel("Search accomplishment client"), "Synthetic");
    await act(async () => new Promise(resolve => setTimeout(resolve, 300)));
    await change(byLabel("Choose accomplishment client"), "7");
    await change(byLabel("Accomplishment activity"), "enrolment");
    await change(byLabel("Accomplishment details"), "School C");
    await submit(byLabel("Record an accomplishment"));
    const first = posts.at(-1);
    assert.equal(first.url, "/portal/education/reports/activities");
    assert.equal(first.data.lead_id, 7); assert.equal(first.data.kind, "enrolment");
    assert.match(first.data.submission_key, /^[0-9a-f-]{36}$/);
    await submit(byLabel("Record an accomplishment"));
    assert.equal(posts.length, 1, "Do not submit twice while saving");
    await act(async () => { first.options.onError({ details: "Save failed" }); first.options.onFinish(); });
    assert.equal(byLabel("Accomplishment details").value, "School C");
    await submit(byLabel("Record an accomplishment"));
    assert.equal(posts.at(-1).data.submission_key, first.data.submission_key, "Retry must reuse its key");
    await act(async () => { posts.at(-1).options.onSuccess(); posts.at(-1).options.onFinish(); });
    await change(byLabel("Accomplishment details"), "School D");
    await submit(byLabel("Record an accomplishment"));
    assert.notEqual(posts.at(-1).data.submission_key, first.data.submission_key, "A new activity needs a fresh key");
    await act(async () => { posts.at(-1).options.onSuccess(); posts.at(-1).options.onFinish(); });

    await change(byLabel("Weekly accomplishments"), "Called five clients");
    await change(byLabel("Other activities"), "Team meeting");
    await change(byLabel("Next week's tasks"), "Review offers");
    const otherWeek = { ...data, notes_week_start: "2026-11-02", notes: { accomplishments: "", other_activities: "", next_week_tasks: "" } };
    await render(otherWeek);
    assert.equal(byLabel("Weekly accomplishments").value, "Called five clients");
    assert.match(document.body.textContent, /unsaved notes belong to the week starting 2026-11-09/);
    await submit(byLabel("My weekly Education notes"));
    const note = posts.at(-1);
    assert.equal(note.url, "/portal/education/reports/personal-notes");
    assert.deepEqual(note.data, { week_start: "2026-11-09", accomplishments: "Called five clients", other_activities: "Team meeting", next_week_tasks: "Review offers" });
    await act(async () => { note.options.onError({ note: "Notes failed" }); note.options.onFinish(); });
    assert.equal(byLabel("Next week's tasks").value, "Review offers");
    await click(button("Discard unsaved notes"));
    assert.equal(byLabel("Weekly accomplishments").value, "");

    await click(byLabel("Remove activity 1"));
    assert.equal(deletes[0].url, "/portal/education/reports/activities/1");
    await act(async () => deletes[0].options.onFinish());
    await click(button("Next activities"));
    assert.equal(visits[0].url, data.entries.next_page_url);
    assert.equal(visits[0].options.preserveState, true);

    searchFails = true;
    await change(byLabel("Search accomplishment client"), "Unknown");
    await act(async () => new Promise(resolve => setTimeout(resolve, 300)));
    assert.match(document.body.textContent, /Client search failed/);

    await change(byLabel("Accomplishment client source"), "named");
    assert.equal(byLabel("Search accomplishment client"), null);
    assert.equal(byLabel("Choose accomplishment client"), null);
    await change(byLabel("Accomplishment client name"), "External Person");
    await change(byLabel("Accomplishment activity"), "new_lead");
    await submit(byLabel("Record an accomplishment"));
    assert.equal(posts.at(-1).data.client_name, "External Person");
    assert.equal(posts.at(-1).data.lead_id, undefined);
    await act(async () => { posts.at(-1).options.onSuccess(); posts.at(-1).options.onFinish(); });
    await change(byLabel("Accomplishment activity"), "follow_up");
    await submit(byLabel("Record an accomplishment"));
    assert.equal(posts.at(-1).data.kind, "follow_up");
    await act(async () => { posts.at(-1).options.onSuccess(); posts.at(-1).options.onFinish(); });
    await change(byLabel("Accomplishment activity"), "status_update");
    await change(byLabel("Reported Education status"), "Conditional Offer");
    await change(byLabel("Accomplishment details"), "Another school offer");
    await submit(byLabel("Record an accomplishment"));
    assert.equal(posts.at(-1).data.reported_status, "Conditional Offer");
    assert.equal(posts.at(-1).data.kind, "status_update");
    await change(byLabel("Filter accomplishment list"), "follow_up");
    assert.equal(visits.at(-1).data.activity_kind, "follow_up");
    assert.equal(visits.at(-1).data.preset, "this_week");
    await click(button("Show enrolments / additional enrolments names"));
    assert.equal(visits.at(-1).data.activity_kind, "enrolment");
    await act(async () => root.unmount());
    console.log("Personal Education report: repeated rows, client search, validation, retry keys, note/week isolation, drafts, errors, removal and pagination passed.");
})().catch(async error => { console.error(error); await act(async () => root.unmount()); process.exitCode = 1; });
