import { test, expect } from "@playwright/test";
const main = "https://luvep.test:8443";
const hr = "https://hr.luvep.test:8443";
async function login(page, email = "alex@example.test") {
    await page.goto(`${main}/login`);
    await page.locator("#email").fill(email);
    await page.locator("#password").fill("Browser-Test-123");
    await page.locator("button[type=submit]").click();
    await page.waitForURL((u) => !u.pathname.includes("login"));
}
test("clock and breaks remain consistent across both portals and reload", async ({
    page,
}) => {
    await login(page);
    await expect(
        page.getByRole("button", { name: "Clock in", exact: true }),
    ).toBeVisible();
    await page.getByRole("button", { name: "Clock in", exact: true }).click();
    await expect(page.getByText("Working", { exact: true })).toBeVisible();
    await page
        .getByRole("button", { name: "Start break", exact: true })
        .click();
    await expect(
        page.getByRole("dialog", { name: "You’re on a break" }),
    ).toBeVisible();
    await page.reload();
    await expect(
        page.getByRole("dialog", { name: "You’re on a break" }),
    ).toBeVisible();
    await page.getByRole("button", { name: "Keep break running" }).click();
    await expect(page.getByText("Work time is paused.")).toBeVisible();
    await page.goto(hr);
    await expect(
        page.getByRole("dialog", { name: "You’re on a break" }),
    ).toBeVisible();
    await page.getByRole("button", { name: "I’m back · resume work" }).click();
    await expect(page.getByRole("dialog")).not.toBeVisible();
    await expect(page.getByText("Working", { exact: true })).toBeVisible();
    page.once("dialog", (d) => d.accept());
    await page.getByRole("button", { name: "Clock out", exact: true }).click();
    await expect(page.getByText("Off shift", { exact: true })).toBeVisible();
    await page
        .getByRole("link", { name: "My time records", exact: true })
        .click();
    await expect(
        page.getByRole("heading", { name: "My time records" }),
    ).toBeVisible();
    await expect(page.getByLabel("Work location")).toBeVisible();
});
test("admin preview is read only and can return to administration", async ({
    page,
}) => {
    await login(page, "admin@example.test");
    await page.goto(hr);
    await page.locator(".hr-profile-trigger").click();
    await page.getByRole("link", { name: "Login as employee" }).click();
    await page.getByRole("button", { name: "View dashboard" }).first().click();
    await expect(page.getByText(/Viewing Alex Rivera/)).toBeVisible();
    await expect(
        page.getByRole("button", { name: "Clock in", exact: true }),
    ).not.toBeVisible();
    await page.getByRole("button", { name: "Return to admin" }).click();
    await expect(page.getByText(/Viewing Alex Rivera/)).not.toBeVisible();
});
test("mobile employee navigation and forms fit without page overflow", async ({
    page,
}) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await login(page);
    await page.goto(`${hr}/leave`);
    await expect(page.getByRole("heading", { name: "My leave" })).toBeVisible();
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBeTruthy();
    await expect(
        page.getByRole("button", { name: "Submit request" }),
    ).toBeDisabled();
});

test("failed clock actions show feedback and another tab refreshes the break state", async ({
    page,
    context,
}) => {
    await login(page);
    await page.goto(hr);
    await page.route("**/clock", (route) =>
        route.request().method() === "POST" ? route.abort() : route.continue(),
    );
    await page.getByRole("button", { name: "Clock in", exact: true }).click();
    await expect(page.getByRole("alert")).toContainText("could not confirm");
    await expect(page.getByText("Off shift", { exact: true })).toBeVisible();
    await page.unroute("**/clock");
    await page.getByRole("button", { name: "Clock in", exact: true }).click();
    await expect(page.getByText("Working", { exact: true })).toBeVisible();
    const other = await context.newPage();
    await other.goto(hr);
    await other
        .getByRole("button", { name: "Start break", exact: true })
        .click();
    await expect(other.getByRole("dialog")).toBeVisible();
    await page.bringToFront();
    await page.evaluate(() => window.dispatchEvent(new Event("focus")));
    await expect(page.getByRole("dialog")).toBeVisible();
    const before = await page.getByTestId("work-timer").innerText();
    await page.waitForTimeout(1200);
    expect(await page.getByTestId("work-timer").innerText()).toBe(before);
    await page.getByRole("button", { name: "I’m back · resume work" }).click();
    page.once("dialog", (d) => d.accept());
    await page.getByRole("button", { name: "Clock out", exact: true }).click();
    await expect(page.getByText("Off shift", { exact: true })).toBeVisible();
    await other.close();
});

test("private documents, exact policy versions and HR replies remain scoped", async ({
    page,
    browser,
}) => {
    test.setTimeout(90000);
    await login(page, "admin@example.test");
    await page.goto(`${hr}/documents`);
    await page.getByText("Upload a private document", { exact: true }).click();
    await page
        .getByRole("combobox", { name: "Employee", exact: true })
        .selectOption({ label: "Alex Rivera" });
    await page.getByLabel("Title", { exact: true }).fill("Synthetic agreement");
    await page
        .getByRole("combobox", { name: "Classification", exact: true })
        .selectOption("employee_shared");
    await page
        .getByLabel("File · PDF, PNG or JPEG, up to 10 MiB")
        .setInputFiles({
            name: "synthetic.pdf",
            mimeType: "application/pdf",
            buffer: Buffer.from(
                "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF",
            ),
        });
    await page.getByRole("button", { name: "Upload private version" }).click();
    await expect(
        page.getByRole("heading", { name: "Synthetic agreement" }),
    ).toBeVisible();
    const doc = page
        .locator("section.hr-panel")
        .filter({
            has: page.getByRole("heading", { name: "Synthetic agreement" }),
        });
    const download = await doc
        .getByRole("link", { name: "Download", exact: true })
        .getAttribute("href");
    await doc.getByRole("button", { name: "Release to employee" }).click();
    await expect(
        doc.getByRole("button", { name: "Revoke employee access" }),
    ).toBeVisible();
    const ec = await browser.newContext({ ignoreHTTPSErrors: true });
    const ep = await ec.newPage();
    await login(ep);
    await ep.goto(`${hr}/documents`);
    await expect(
        ep.getByRole("heading", { name: "Synthetic agreement" }),
    ).toBeVisible();
    await doc.getByRole("button", { name: "Revoke employee access" }).click();
    await expect(
        doc.getByRole("button", { name: "Release to employee" }),
    ).toBeVisible();
    expect(
        await ep.evaluate(async (path) => (await fetch(path)).status, download),
    ).toBe(404);
    await ep.reload();
    await expect(
        ep.getByRole("heading", { name: "Synthetic agreement" }),
    ).not.toBeVisible();
    await page.goto(`${hr}/policies`);
    await page.getByText("Create a policy draft", { exact: true }).click();
    const create = page
        .locator("details")
        .filter({
            has: page.getByText("Create a policy draft", { exact: true }),
        });
    await create.getByLabel("Policy title").fill("Synthetic workplace policy");
    await create.getByLabel("Policy text").fill("First published policy text.");
    await create.getByRole("button", { name: "Save draft" }).click();
    const draft = page
        .locator("details")
        .filter({
            has: page.getByText("Edit draft · Synthetic workplace policy", {
                exact: true,
            }),
        });
    await draft.locator("summary").click();
    page.once("dialog", (d) => d.accept());
    await draft.getByRole("button", { name: "Publish new version" }).click();
    await expect(
        page.getByRole("heading", { name: "Synthetic workplace policy" }),
    ).toBeVisible();
    await ep.goto(`${hr}/policies`);
    await ep
        .getByLabel("I confirm I have read this policy, version 1.")
        .check();
    await ep.getByRole("button", { name: "Record acknowledgement" }).click();
    await expect(ep.getByText(/^Acknowledged /)).toBeVisible();
    await draft.locator("summary").click();
    await draft.getByLabel("Policy text").fill("Second published policy text.");
    page.once("dialog", (d) => d.accept());
    await draft.getByRole("button", { name: "Publish new version" }).click();
    await expect(
        page.getByText("Second published policy text.", { exact: true }),
    ).toBeVisible();
    await ep.reload();
    await expect(
        ep.getByLabel("I confirm I have read this policy, version 2."),
    ).not.toBeChecked();
    await ep.goto(`${hr}/requests`);
    await ep.getByText("Open a private HR request", { exact: true }).click();
    await ep
        .getByLabel("Subject", { exact: true })
        .fill("Synthetic employment letter");
    await ep
        .getByLabel("How can HR help?")
        .fill("Please prepare a letter for my records.");
    await ep.getByRole("button", { name: "Send request to HR" }).click();
    await expect(
        ep.getByRole("heading", { name: "Synthetic employment letter" }),
    ).toBeVisible();
    const casePath = new URL(ep.url()).pathname;
    await page.goto(`${hr}/requests`);
    await page
        .getByRole("link", { name: "Synthetic employment letter" })
        .click();
    await page.getByLabel("Internal note · hidden from the employee").check();
    await page
        .getByLabel("Internal HR note", { exact: true })
        .fill("INTERNAL TEST NOTE");
    await page.getByRole("button", { name: "Save internal note" }).click();
    await expect(
        page.getByText("INTERNAL TEST NOTE", { exact: true }),
    ).toBeVisible();
    await ep.reload();
    await expect(
        ep.getByText("INTERNAL TEST NOTE", { exact: true }),
    ).not.toBeVisible();
    await page
        .getByLabel("Reply", { exact: true })
        .fill("Your letter is being prepared.");
    await page.getByRole("button", { name: "Send reply" }).click();
    await ep.reload();
    await expect(
        ep.getByText("Your letter is being prepared.", { exact: true }),
    ).toBeVisible();
    const sc = await browser.newContext({ ignoreHTTPSErrors: true });
    const sp = await sc.newPage();
    await login(sp, "sam@example.test");
    await sp.goto(hr);
    expect(
        await sp.evaluate(async (path) => (await fetch(path)).status, casePath),
    ).toBe(404);
    expect(
        await sp.evaluate(async (path) => (await fetch(path)).status, download),
    ).toBe(404);
    await ec.close();
    await sc.close();
});
