import { test, expect } from "@playwright/test";
import { readFileSync } from "node:fs";
const main = "https://luvep.test:8443";
const hr = "https://hr.luvep.test:8443";

async function login(page, remember = false) {
    await page.goto(`${main}/login`);
    await page.locator("#email").fill("alex@example.test");
    await page.locator("#password").fill("Browser-Test-123");
    if (remember) await page.getByText("Remember me", { exact: true }).click();
    await page.locator("button[type=submit]").click();
    await page.waitForURL((url) => !url.pathname.includes("login"));
}

test("main login, HR navigation, CSRF update and cross-portal logout", async ({
    page,
    context,
}) => {
    await login(page);
    await page.goto(`${hr}/profile`);
    await expect(
        page.getByRole("heading", { name: "Alex Rivera" }),
    ).toBeVisible();
    await page
        .getByLabel("Phone number", { exact: true })
        .fill("+639991234567");
    await page.getByRole("button", { name: "Save details" }).click();
    await expect(
        page.getByRole("status").filter({ hasText: "Saved" }),
    ).toBeVisible();
    const cookies = await context.cookies();
    expect(
        cookies.some(
            (c) =>
                c.name === "luvep_shared_session" &&
                c.domain === ".luvep.test" &&
                c.secure &&
                c.httpOnly,
        ),
    ).toBeTruthy();
    const invalid = await page.evaluate(
        async () =>
            (
                await fetch("/profile", {
                    method: "PATCH",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ phone: "bad" }),
                })
            ).status,
    );
    expect(invalid).toBe(419);
    await page.getByRole("button", { name: "Sign out" }).click();
    await expect(page).toHaveURL(`${main}/login`);
    await page.goto(`${main}/portal/sales/dashboard`);
    await expect(page).toHaveURL(/\/login/);
    await page.goto(`${hr}/profile`);
    await expect(page).toHaveURL(`${main}/login`);
});

test("old host-only session is migrated and reused by HR", async ({
    page,
    context,
}) => {
    const fixture = JSON.parse(
        readFileSync(
            ".superpowers/sdd/2026-10-19-hr-release-1/browser/fixture.json",
            "utf8",
        ),
    );
    await context.addCookies([
        {
            ...fixture.legacyCookie,
            url: main,
            httpOnly: true,
            secure: true,
            sameSite: "Lax",
        },
    ]);
    await page.goto(`${main}/portal/sales/dashboard`);
    await page.goto(`${hr}/profile`);
    await expect(
        page.getByRole("heading", { name: "Alex Rivera" }),
    ).toBeVisible();
    expect(
        (await context.cookies()).some(
            (c) =>
                c.name === fixture.legacyCookie.name &&
                c.domain === "luvep.test",
        ),
    ).toBeFalsy();
});

test("remember-me restores HR sign-in and logout revokes it", async ({
    page,
    context,
}) => {
    await login(page, true);
    const remembered = (await context.cookies()).filter((c) =>
        c.name.startsWith("remember_"),
    );
    expect(remembered.length).toBeGreaterThan(0);
    expect(remembered[0].domain).toBe(".luvep.test");
    await context.clearCookies();
    await context.addCookies(remembered);
    await page.goto(`${hr}/profile`);
    await expect(
        page.getByRole("heading", { name: "Alex Rivera" }),
    ).toBeVisible();
    await page.getByRole("button", { name: "Sign out" }).click();
    await expect(page).toHaveURL(`${main}/login`);
    await page.goto(`${hr}/profile`);
    await expect(page).toHaveURL(`${main}/login`);
});

test("a direct HR visit signs in on Luvep and returns to the requested HR page", async ({
    page,
}) => {
    await page.goto(`${hr}/profile`);
    await expect(page).toHaveURL(`${main}/login`);
    await page.locator("#email").fill("alex@example.test");
    await page.locator("#password").fill("Browser-Test-123");
    await page.locator("button[type=submit]").click();
    await expect(page).toHaveURL(`${hr}/profile`);
    await expect(
        page.getByRole("heading", { name: "Alex Rivera" }),
    ).toBeVisible();
});
