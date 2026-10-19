import { test, expect } from "@playwright/test";
const main = "https://luvep.test:8443",
    hr = "https://hr.luvep.test:8443";
async function login(page, email) {
    await page.goto(`${main}/login`);
    await page.locator("#email").fill(email);
    await page.locator("#password").fill("Browser-Test-123");
    await page.locator("button[type=submit]").click();
    await page.waitForURL((u) => !u.pathname.includes("login"));
}
test("employee submits DTR and leave, receives decisions, completes onboarding and downloads own report", async ({
    page,
    browser,
}) => {
    test.setTimeout(90000);
    await login(page, "taylor@example.test");
    await page.goto(`${hr}/dtr`);
    await page.getByLabel("Work location", { exact: true }).fill("Office");
    await page.getByRole("button", { name: "Add task", exact: true }).click();
    await page
        .getByLabel("Task 1", { exact: true })
        .fill("Complete employee orientation");
    await page.getByLabel("Started", { exact: true }).fill("08:00");
    await page.getByLabel("Finished", { exact: true }).fill("09:00");
    await page.getByLabel("Status", { exact: true }).selectOption("done");
    await page.getByRole("button", { name: "Save draft", exact: true }).click();
    await expect(
        page.getByText("Time record saved.", { exact: true }),
    ).toBeVisible();
    await page
        .getByLabel(
            "I confirm this record accurately reflects my work and attendance.",
        )
        .check();
    await page
        .getByRole("button", { name: "Submit for review", exact: true })
        .click();
    await expect(
        page.getByLabel("Work location", { exact: true }),
    ).not.toBeVisible();
    const ac = await browser.newContext({ ignoreHTTPSErrors: true });
    const ap = await ac.newPage();
    await login(ap, "admin@example.test");
    await ap.goto(`${hr}/dtr/review`);
    const daily = ap.locator("details").filter({ hasText: "Taylor Morgan" });
    await daily.locator("summary").click();
    await daily.getByRole("button", { name: "Save decision" }).click();
    await expect(
        ap.locator("details").filter({ hasText: "Taylor Morgan" }),
    ).toHaveCount(0);
    await page.reload();
    await expect(
        page.getByLabel("Date", { exact: true }).locator("option:checked"),
    ).toContainText("reviewed");
    await page.goto(`${hr}/leave`);
    const d = new Date();
    d.setDate(d.getDate() + 35);
    if (d.getDay() === 0) d.setDate(d.getDate() + 1);
    const date = d.toISOString().slice(0, 10);
    await page.getByLabel("First day", { exact: true }).fill(date);
    await page.getByLabel("Last day", { exact: true }).fill(date);
    await page
        .getByLabel(
            "I checked with my team for deadlines and overlapping leave.",
        )
        .check();
    await page
        .getByLabel("I understand I should not book travel until approved.")
        .check();
    await page
        .getByLabel(
            "I will set my email auto reply and WhatsApp status with my return date and cover person.",
        )
        .check();
    await page
        .getByLabel(
            "The information is correct, and I have read the Leave of Absence memo (LUV-MEMO-2026-001).",
        )
        .check();
    await page
        .getByRole("button", { name: "Submit request", exact: true })
        .click();
    await expect(
        page.locator("details").filter({ hasText: date }),
    ).toBeVisible();
    await ap.goto(`${hr}/leave/review`);
    const leave = ap.locator("details").filter({ hasText: "Taylor Morgan" });
    await leave.locator("summary").click();
    await leave.getByRole("button", { name: "Record decision" }).click();
    await page.reload();
    await expect(
        page.locator("details").filter({ hasText: date }).locator("summary"),
    ).toContainText("approved");
    await page.goto(`${hr}/checklists`);
    await expect(page.getByText("PRIVATE ONBOARDING NOTE")).not.toBeVisible();
    await page
        .getByRole("button", { name: "Mark complete", exact: true })
        .click();
    await expect(page.getByText("Completed", { exact: true })).toBeVisible();
    await page.goto(
        `${hr}/reports?type=approved_hours&from=2026-01-01&to=2026-12-31`,
    );
    await expect(
        page.getByRole("cell", { name: "Taylor Morgan", exact: true }),
    ).toBeVisible();
    await expect(
        page.getByRole("cell", { name: "Alex Rivera", exact: true }),
    ).not.toBeVisible();
    await page.getByRole("button", { name: "Export displayed report" }).click();
    await expect(
        page.getByRole("link", { name: "Download CSV" }),
    ).toBeVisible();
    const download = page.waitForEvent("download");
    await page.getByRole("link", { name: "Download CSV" }).click();
    expect((await download).suggestedFilename()).toBe(
        "luvep-approved_hours.csv",
    );
    await ap.goto(`${hr}/milestones`);
    await ap.getByText("Add milestone", { exact: true }).click();
    await ap
        .getByRole("combobox", { name: "Employee", exact: true })
        .selectOption({ label: "Taylor Morgan" });
    await ap.getByLabel("Title", { exact: true }).fill("First month check-in");
    await ap.getByLabel("Due date", { exact: true }).fill(date);
    await ap
        .getByRole("combobox", { name: "Owner", exact: true })
        .selectOption({ label: "HR Administrator" });
    await ap.getByLabel("Share with employee").check();
    await ap
        .getByLabel("Private HR note", { exact: true })
        .fill("PRIVATE MILESTONE NOTE");
    await ap
        .getByRole("button", { name: "Save milestone", exact: true })
        .click();
    await page.goto(`${hr}/milestones`);
    await expect(
        page.getByRole("heading", { name: "First month check-in" }),
    ).toBeVisible();
    await expect(page.getByText("PRIVATE MILESTONE NOTE")).not.toBeVisible();
    await ac.close();
});
test("desktop and mobile portal visual review and administrative pages", async ({
    page,
}) => {
    await login(page, "admin@example.test");
    await page.setViewportSize({ width: 1440, height: 1050 });
    await page.goto(hr);
    await page.screenshot({
        path: "/private/tmp/luvep-hr-admin-desktop.png",
        fullPage: true,
    });
    for (const path of [
        "access",
        "audit",
        "reports",
        "dtr/corrections",
        "equipment",
    ]) {
        await page.goto(`${hr}/${path}`);
        await expect(page.locator("h1")).toBeVisible();
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= window.innerWidth,
            ),
        ).toBe(true);
    }
    await page.goto(`${hr}/access`);
    await page
        .locator("details")
        .filter({ hasText: "Taylor Morgan" })
        .locator("summary")
        .click();
    await page.screenshot({
        path: "/private/tmp/luvep-hr-access-desktop.png",
        fullPage: true,
    });
    await page.goto(`${hr}/`);
    await page.getByRole("button", { name: "Sign out", exact: true }).click();
    await login(page, "taylor@example.test");
    await page.goto(hr);
    await page.screenshot({
        path: "/private/tmp/luvep-hr-employee-desktop.png",
        fullPage: true,
    });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({
        path: "/private/tmp/luvep-hr-employee-mobile.png",
        fullPage: true,
    });
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);
});
