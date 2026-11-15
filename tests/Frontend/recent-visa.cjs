const assert = require("node:assert/strict");
const { buildSync } = require("esbuild");
const { JSDOM } = require("jsdom");
const Module = require("node:module");
const path = require("node:path");
const dom = new JSDOM('<button id="opener">More</button><div id="root"></div>', { url: "https://luvep.test/portal/immigration/cases/1/profile?tab=documents" });
for (const key of ["window", "document", "HTMLElement", "HTMLDialogElement", "Event", "MouseEvent"]) global[key] = dom.window[key];
Object.defineProperty(global, "navigator", { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', ''); };
HTMLDialogElement.prototype.close = function () { this.removeAttribute('open'); };
const React = require("react");
const { act } = React;
const { createRoot } = require("react-dom/client");
const posts = [];
const output = buildSync({
    entryPoints: ["resources/js/components/immigration/case-profile/tabs/RecentVisaDialog.jsx"],
    bundle: true, write: false, format: "cjs", platform: "node", jsx: "automatic",
    alias: { "@": path.resolve("resources/js") },
    external: ["react", "react-dom", "react/jsx-runtime", "@inertiajs/react"],
}).outputFiles[0].text;
const loaded = new Module(path.resolve("recent-visa-dialog.cjs"), module);
loaded.filename = path.resolve("recent-visa-dialog.cjs"); loaded.paths = module.paths;
loaded.require = name => name === "@inertiajs/react" ? { router: { post: (...args) => posts.push(args) } } : module.require(name);
loaded._compile(output, loaded.filename);
const Dialog = loaded.exports.default;
const root = createRoot(document.getElementById("root"));
let closes = 0;
const render = () => act(async () => root.render(React.createElement(Dialog, {
    leadId: 1, doc: { id: 12, original_name: "synthetic-visa.pdf" }, onClose: () => closes++,
})));
const button = text => [...document.querySelectorAll("button")].find(el => el.textContent === text);
const click = async el => { assert.ok(el); await act(async () => el.dispatchEvent(new MouseEvent("click", { bubbles: true }))); };
(async () => {
    const opener = document.getElementById("opener");
    opener.focus();
    await render();
    const dialog = document.querySelector("dialog[open]");
    assert.ok(dialog, "Use a native modal to keep keyboard focus inside the dialog");
    assert.equal(document.activeElement, button("Cancel"));
    await click(button("Cancel"));
    assert.equal(closes, 1); assert.equal(posts.length, 0);
    await act(async () => dialog.dispatchEvent(new Event("cancel", { cancelable: true })));
    assert.equal(closes, 2); assert.equal(posts.length, 0);
    await click(button("Move document"));
    assert.equal(posts.length, 1);
    assert.equal(posts[0][0], "/portal/immigration/cases/1/documents/12/recent-visa");
    assert.ok(button("Moving…").disabled);
    await act(async () => dialog.dispatchEvent(new Event("cancel", { cancelable: true })));
    assert.equal(closes, 2, "Escape must not dismiss a move while it is saving");
    await click(button("Moving…"));
    assert.equal(posts.length, 1, "Submitting twice must not create duplicate requests");
    const options = posts[0][2];
    assert.ok(options.only.includes("checklist") && options.only.includes("documents"));
    await act(async () => { options.onError({ document: "Classification failed" }); options.onFinish(); });
    assert.match(document.querySelector('[role="alert"]').textContent, /Classification failed/);
    assert.equal(closes, 2, "Failed classification must keep the dialog open");
    await click(button("Move document"));
    await act(async () => { posts[1][2].onSuccess(); posts[1][2].onFinish(); });
    assert.equal(closes, 3);
    await act(async () => root.unmount());
    assert.equal(document.activeElement, opener, "Closing returns focus to the file menu");
    console.log("Recent visa / eVisa dialog: confirmation, cancel, duplicate-submit prevention, failure and retry passed.");
})().catch(error => { console.error(error); process.exitCode = 1; });
