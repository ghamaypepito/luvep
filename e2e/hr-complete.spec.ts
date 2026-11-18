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
    const workDate = (
        await page
            .getByRole("combobox", { name: "Date", exact: true })
            .locator("option:checked")
            .innerText()
    )
        .trim()
        .slice(0, 10);
    await page.getByLabel("Work location", { exact: true }).fill("Office");
    await page.getByRole("button", { name: "Add task", exact: true }).click();
    await page
        .getByLabel("Task 1", { exact: true })
        .fill("Complete employee orientation");
    await page.getByLabel("Started", { exact: true }).fill("08:00");
    await page.getByLabel("Finished", { exact: true }).fill("09:00");
    await page
        .getByRole("combobox", { name: "Status", exact: true })
        .selectOption("done");
    await page.getByRole("button", { name: "Save draft", exact: true }).click();
    await expect(
        page.getByRole("status").filter({ hasText: "Time record saved." }),
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
        page
            .getByRole("combobox", { name: "Date", exact: true })
            .locator("option:checked"),
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
    await ap.goto(`${hr}/dtr/corrections`);
    await ap
        .getByRole("combobox", { name: "Employee", exact: true })
        .selectOption({ label: "Taylor Morgan" });
    await ap.getByLabel("Work date", { exact: true }).fill(workDate);
    await ap.getByRole("button", { name: "Find record", exact: true }).click();
    const oldFinish = await ap
        .getByLabel("Clock out (UTC)", { exact: true })
        .inputValue();
    await ap
        .getByLabel("Clock out (UTC)", { exact: true })
        .fill(oldFinish.slice(0, 11) + "09:05");
    await ap
        .getByLabel("Reason for correction", { exact: true })
        .fill("Reviewed five minutes of closing work.");
    await ap
        .getByRole("button", {
            name: "Save correction and return DTR",
            exact: true,
        })
        .click();
    await expect(
        ap.getByRole("status").filter({ hasText: "Time record saved." }),
    ).toBeVisible();
    await page.goto(`${hr}/dtr`);
    await expect(
        page
            .getByRole("combobox", { name: "Date", exact: true })
            .locator("option:checked"),
    ).toContainText("returned");
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
    await page.waitForURL((u) => u.pathname.includes("login"));
    await login(page, "taylor@example.test");
    await page.goto(hr);
    await page.screenshot({
        path: "/private/tmp/luvep-hr-employee-desktop.png",
        fullPage: true,
    });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.reload();
    await expect(page.locator(".hr-app")).toHaveCount(1);
    await expect(
        page.getByRole("navigation", { name: "HR navigation" }),
    ).toHaveCount(1);
    await expect(page.getByRole("link", { name: /Book Free/i })).toHaveCount(0);
    await expect(
        page.getByRole("link", { name: "Chat on WhatsApp" }),
    ).toHaveCount(0);
    await page.screenshot({
        path: "/private/tmp/luvep-hr-employee-mobile.png",
        fullPage: false,
    });
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);
});

test("finish reminder updates, stays subtle after dismissal, and expires", async ({
    page,
}) => {
    await login(page, "reminder@example.test");
    await expect(
        page.getByText("Your shift finishes soon", { exact: true }),
    ).toBeVisible();
    await page.getByRole("button", { name: "Dismiss shift reminder" }).click();
    await expect(
        page.getByText(/Scheduled finish in \d+ minutes/),
    ).toBeVisible();
    let remaining = 60;
    await page.route("**/hr/clock", async (route) => {
        if (route.request().method() !== "GET") {
            await route.continue();
            return;
        }
        const response = await route.fetch({
            url: "https://127.0.0.1:8443/hr/clock",
            headers: { ...route.request().headers(), host: "luvep.test:8443" },
        });
        const data = await response.json();
        const target = data.state || data;
        target.server_now = new Date(
            Date.parse(target.scheduled_end_at) - remaining * 1000,
        ).toISOString();
        await route.fulfill({ response, json: data });
    });
    await page.evaluate(() => window.dispatchEvent(new Event("focus")));
    await expect(
        page.getByText(
            "Scheduled finish in 1 minute. Remember to clock out when you finish.",
            { exact: true },
        ),
    ).toBeVisible();
    remaining = -10;
    await page.evaluate(() => window.dispatchEvent(new Event("focus")));
    await expect(page.getByText(/Scheduled finish in/)).not.toBeVisible();
    await expect(
        page.getByText("Your shift finishes soon", { exact: true }),
    ).not.toBeVisible();
});
