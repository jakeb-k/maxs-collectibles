import { test, expect } from "@playwright/test";
import path from "node:path";
async function csrfPost(page, url, data) {
  const session = await (await page.request.get("/__test/session")).json();
  const csrf = session.csrf;
  return page.request.post(url, {
    data,
    headers: { "X-CSRF-TOKEN": csrf, Accept: "application/json" },
  });
}
async function control(page, data) {
  const response = await csrfPost(page, "/__test/commerce", data);
  expect(response.ok()).toBe(true);
  return response.json();
}
async function mailLink(page, email, kind) {
  const response = await csrfPost(page, "/__test/account-mail", {
    email,
    kind,
  });
  expect(response.ok()).toBe(true);
  const { link } = await response.json();
  expect(new URL(link).origin).toBe(
    new URL(process.env.MAXS_BROWSER_URL).origin,
  );
  return link;
}
async function login(page, password) {
  await page.goto("/login");
  await page
    .getByLabel("Email", { exact: true })
    .fill("browser-account@example.test");
  await page.getByLabel("Password", { exact: true }).fill(password);
  await page.getByRole("button", { name: "Log in", exact: true }).click();
}

test("retained account lifecycle, manual account checkout, owned history and immutable address", async ({
  page,
  browser,
}) => {
  await page.route("https://checkout.stripe.com/**", (route) => route.abort());
  await page.goto("/register");
  for (const [label, value] of [
    ["First name", "Browser"],
    ["Last name", "Account"],
    ["Email", "browser-account@example.test"],
    ["Password", "browser-start-password"],
    ["Confirm password", "browser-start-password"],
  ])
    await page.getByLabel(label, { exact: true }).fill(value);
  await page
    .getByRole("button", { name: "Create account", exact: true })
    .click();
  await expect(
    page.getByRole("button", { name: "Log out", exact: true }),
  ).toBeVisible();
  expect((await page.request.get("/admin")).status()).toBe(403);
  await expect(
    page.getByRole("link", { name: "Admin", exact: true }),
  ).toHaveCount(0);
  // A fresh account has no saved address; checkout stays fully manual.
  await page.goto("/product/2");
  await page.getByRole("button", { name: "Add to bag ↗" }).click();
  await page.goto("/checkout");
  for (const [label, value] of [
    ["First name", "Browser"],
    ["Last name", "Buyer"],
    ["Email", "browser-account@example.test"],
    ["Australian mobile", "0412345678"],
    ["Street address", "1 Fixture Street"],
    ["Suburb / city", "Brisbane"],
    ["State / territory", "QLD"],
    ["Postcode", "4000"],
  ])
    await page.getByLabel(label, { exact: true }).fill(value);
  await control(page, { action: "behavior", behavior: "timeout_after_create" });
  await page.getByRole("button", { name: "Review your quote ↗" }).click();
  const pending = page.waitForResponse(
    (r) => r.url().endsWith("/checkout") && r.request().method() === "POST",
  );
  await page.getByRole("button", { name: "Accept quote & continue ↗" }).click();
  const attempt = await (await pending).json();
  expect(attempt.state).not.toBe("paid");
  await control(page, { action: "paid", attempt_key: attempt.attempt_key });
  await page
    .getByRole("link", { name: "Check payment status", exact: true })
    .click();
  await expect(
    page.getByRole("heading", { name: "Paid", exact: true }),
  ).toBeVisible();
  const orderURL = page.url();
  await page.goto("/profile");
  await expect(
    page.getByRole("link", { name: new RegExp(attempt.order_code) }),
  ).toBeVisible();
  expect(await page.locator("body").innerText()).not.toContain("session_id");
  await page.getByLabel("First name", { exact: true }).fill("Updated");
  for (const [label, value] of [
    ["Street address", "10 Saved Address"],
    ["Suburb / city", "Darwin"],
    ["State / territory", "nt"],
    ["Postcode", "0800"],
  ])
    await page.getByLabel(label, { exact: true }).fill(value);
  await page.getByRole("button", { name: "Save profile", exact: true }).click();
  await expect(page.getByText("Profile saved.", { exact: true })).toBeVisible();
  await page.reload();
  await expect(page.getByLabel("Postcode", { exact: true })).toHaveValue(
    "0800",
  );
  await page
    .getByRole("link", { name: new RegExp(attempt.order_code) })
    .click();
  await expect(
    page.getByText("1 Fixture Street", { exact: false }),
  ).toBeVisible();
  expect(await page.locator("body").innerText()).not.toContain(
    "10 Saved Address",
  );
  await page.goto("/profile");
  await page
    .getByLabel("Current password", { exact: true })
    .fill("wrong-password");
  await page
    .getByLabel("New password", { exact: true })
    .fill("browser-changed-password");
  await page
    .getByLabel("Confirm new password", { exact: true })
    .fill("browser-changed-password");
  await page
    .getByRole("button", { name: "Save new password", exact: true })
    .click();
  await expect(
    page.getByLabel("Current password", { exact: true }),
  ).toHaveAttribute("aria-invalid", "true");
  await page
    .getByLabel("Current password", { exact: true })
    .fill("browser-start-password");
  await page
    .getByRole("button", { name: "Save new password", exact: true })
    .click();
  await expect(
    page.getByText("Password changed.", { exact: true }),
  ).toBeVisible();
  await page.goto("/confirm-password");
  await page
    .getByLabel("Password", { exact: true })
    .fill("browser-changed-password");
  await page
    .getByRole("button", { name: "Confirm password", exact: true })
    .click();
  await expect(
    page.getByRole("heading", { name: "Objects worth a closer look." }),
  ).toBeVisible();
  await page.goto("/verify-email");
  await page
    .getByRole("button", { name: "Request verification email", exact: true })
    .click();
  await expect(
    page.getByText("Your verification message was accepted for delivery.", {
      exact: true,
    }),
  ).toBeVisible();
  const verification = await mailLink(
    page,
    "browser-account@example.test",
    "verification",
  );
  await page.goto(verification);
  await expect(
    page.getByRole("heading", { name: "Objects worth a closer look." }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Log out", exact: true }).click();
  await page.goto("/forgot-password");
  await page
    .getByLabel("Email", { exact: true })
    .fill("browser-account@example.test");
  await page
    .getByRole("button", { name: "Request reset link", exact: true })
    .click();
  await expect(
    page.getByText("Your password reset request was accepted for delivery.", {
      exact: true,
    }),
  ).toBeVisible();
  const reset = await mailLink(
    page,
    "browser-account@example.test",
    "password_reset",
  );
  await page.goto(reset);
  await page
    .getByLabel("New password", { exact: true })
    .fill("browser-reset-password");
  await page
    .getByLabel("Confirm new password", { exact: true })
    .fill("browser-reset-password");
  await page
    .getByRole("button", { name: "Reset password", exact: true })
    .click();
  await expect(
    page.getByRole("button", { name: "Log in", exact: true }),
  ).toBeVisible();
  await login(page, "browser-changed-password");
  await expect(page.getByLabel("Email", { exact: true })).toHaveAttribute(
    "aria-invalid",
    "true",
  );
  await page
    .getByLabel("Password", { exact: true })
    .fill("browser-reset-password");
  await page.getByRole("button", { name: "Log in", exact: true }).click();
  await expect(
    page.getByRole("button", { name: "Log out", exact: true }),
  ).toBeVisible();
  await page.goto("/profile");
  await page.evaluate(() => {
    document.activeElement?.blur();
    window.scrollTo(0, 0);
  });
  await page.screenshot({
    path: path.join(process.env.MAXS_BROWSER_OUTPUT, "account-profile.png"),
    fullPage: true,
  });
  await page
    .getByRole("button", { name: "Delete my account", exact: true })
    .click();
  await page
    .getByLabel("Password to delete account", { exact: true })
    .fill("wrong-password");
  await page
    .getByRole("button", { name: "Permanently delete account", exact: true })
    .click();
  await expect(
    page.getByLabel("Password to delete account", { exact: true }),
  ).toHaveAttribute("aria-invalid", "true");
  await page
    .getByLabel("Password to delete account", { exact: true })
    .fill("browser-reset-password");
  await page
    .getByRole("button", { name: "Permanently delete account", exact: true })
    .click();
  await expect(
    page.getByRole("link", { name: "Account", exact: true }),
  ).toBeVisible();
  expect((await page.request.get(orderURL)).status()).toBe(403);
  await expect(page).toHaveURL(/\/$/);
  await expect(
    page.getByRole("button", { name: "Log out", exact: true }),
  ).toHaveCount(0);
  // Restore only synthetic physical stock so later discovery specs keep their fixture.
  await control(page, { action: "product", product_id: 2, stock_quantity: 1 });
});

test("general contact, subscription errors and genuine signed unsubscribe", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto("/faqs");
  const form = page.getByRole("form", { name: "Contact Max" });
  await form.getByLabel("First name", { exact: true }).fill("X".repeat(51));
  await form
    .getByLabel("Email", { exact: true })
    .fill("browser-contact@example.test");
  await form
    .getByLabel("Your message", { exact: true })
    .fill("Synthetic browser contact question.");
  await form.getByRole("button", { name: "Send message", exact: true }).click();
  await expect(form.getByLabel("First name", { exact: true })).toHaveAttribute(
    "aria-invalid",
    "true",
  );
  await form.getByLabel("First name", { exact: true }).fill("Browser");
  await form.getByRole("button", { name: "Send message", exact: true }).click();
  await expect(
    page.getByText("Your message was accepted and is queued for delivery.", {
      exact: true,
    }),
  ).toBeVisible();
  await page
    .getByLabel("Stay in the loop", { exact: true })
    .fill("browser-subscriber@example.test");
  await page.getByRole("button", { name: "Subscribe ↗", exact: true }).click();
  await expect(
    page.getByText(
      "You’re subscribed. Your welcome email is queued for delivery.",
      { exact: true },
    ),
  ).toBeVisible();
  await page.getByRole("button", { name: "Subscribe ↗", exact: true }).click();
  await expect(
    page.getByLabel("Stay in the loop", { exact: true }),
  ).toHaveAttribute("aria-invalid", "true");
  await page.evaluate(() => {
    document.activeElement?.blur();
    window.scrollTo(0, 0);
  });
  await page.screenshot({
    path: path.join(
      process.env.MAXS_BROWSER_OUTPUT,
      "mobile-information-contact.png",
    ),
    fullPage: true,
  });
  const unsubscribe = await mailLink(
    page,
    "browser-subscriber@example.test",
    "welcome",
  );
  const forged = new URL(unsubscribe);
  forged.searchParams.set("signature", "invalid");
  expect((await page.request.get(forged.href)).status()).toBe(403);
  await page.goto(unsubscribe);
  await expect(
    page.getByRole("heading", { name: "You’re unsubscribed", exact: true }),
  ).toBeVisible();
  await page.goto(unsubscribe);
  await expect(
    page.getByRole("heading", { name: "You’re unsubscribed", exact: true }),
  ).toBeVisible();
  await page
    .getByRole("link", { name: "Return to the store", exact: true })
    .click();
  await expect(
    page.getByRole("heading", { name: "Objects worth a closer look." }),
  ).toBeVisible();
});
