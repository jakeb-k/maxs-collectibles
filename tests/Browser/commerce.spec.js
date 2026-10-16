import { test, expect } from "@playwright/test";
import path from "node:path";
test.beforeEach(async ({ page }) => {
  await page.route("https://checkout.stripe.com/**", (route) => route.abort());
});
async function control(page, data) {
  const csrf = await page
    .locator('meta[name="csrf-token"]')
    .getAttribute("content");
  const response = await page.request.post("/__test/commerce", {
    data,
    headers: { "X-CSRF-TOKEN": csrf, Accept: "application/json" },
  });
  expect(response.ok(), await response.text()).toBe(true);
  return response.json();
}
async function details(page, { mobile = "0412345678" } = {}) {
  for (const [label, value] of [
    ["First name", "Fixture"],
    ["Last name", "Buyer"],
    ["Email", "fixture@example.test"],
    ["Australian mobile", mobile],
    ["Street address", "1 Fixture Street"],
    ["Suburb / city", "Brisbane"],
    ["State / territory", "QLD"],
    ["Postcode", "4000"],
  ])
    await page.getByLabel(label, { exact: true }).fill(value);
}
async function addCard(page, value) {
  await page.goto("/product/4");
  await page
    .getByRole("combobox", { name: "Display", exact: true })
    .selectOption(value);
  await page.getByRole("button", { name: "Add to bag ↗" }).click();
}
async function stored(page) {
  return page.evaluate(
    () => JSON.parse(localStorage.getItem("cart-storage")).state,
  );
}

test("real quote conflict, owned pending retry, paid immutable order and precise cart clearing", async ({
  page,
  browser,
}) => {
  await page.goto("/");
  await control(page, { action: "behavior", behavior: "timeout_after_create" });
  await addCard(page, "Standard");
  await addCard(page, "Stand");
  await page.goto("/checkout");
  await details(page);
  await page.getByRole("button", { name: "Review your quote ↗" }).click();
  await expect(page.locator(".quote-totals")).toContainText("$85.00");
  await control(page, { action: "product", product_id: 4, price_minor: 4350 });
  const changed = page.waitForResponse(
    (r) => r.url().endsWith("/checkout") && r.request().method() === "POST",
  );
  await page.getByRole("button", { name: "Accept quote & continue ↗" }).click();
  expect((await changed).status()).toBe(409);
  await expect(
    page.getByRole("button", { name: "Accept updated quote & continue ↗" }),
  ).toBeVisible();
  await expect(page.locator(".quote-totals")).toContainText("$87.00");
  expect((await stored(page)).pendingKey).toBeNull();
  let sent;
  page.on("request", (r) => {
    if (r.url().endsWith("/checkout") && r.method() === "POST")
      sent = r.postDataJSON();
  });
  await page
    .getByRole("button", { name: "Accept updated quote & continue ↗" })
    .click();
  await expect(
    page.getByText(
      "Payment session status is pending. Retry this same attempt.",
      { exact: true },
    ),
  ).toBeVisible();
  const captured = await stored(page);
  expect(JSON.stringify(captured)).not.toContain("fixture@example.test");
  expect(JSON.stringify(captured)).not.toContain("1 Fixture Street");
  const key = captured.pendingKey;
  expect(key).toMatch(/^[a-f0-9-]{36}$/);
  await page.reload();
  await expect(page.getByLabel("First name", { exact: true })).toHaveValue(
    "Fixture",
  );
  await expect(page.getByLabel("First name", { exact: true })).toBeDisabled();
  expect((await stored(page)).pendingKey).toBe(key);
  await page
    .getByRole("spinbutton", { name: "Quantity for Fixture — Cards example" })
    .first()
    .fill("3");
  await page
    .getByRole("button", { name: "Remove Fixture — Cards example from bag" })
    .nth(1)
    .click();
  await addCard(page, "Stand");
  await page.goto("/product/1");
  await page.getByRole("button", { name: "Add to bag ↗" }).click();
  await page.goto("/checkout");
  const beforePaid = await stored(page);
  expect(
    beforePaid.products.find((p) => p.selection.Display === "Standard")
      .quantity,
  ).toBe(3);
  expect(
    beforePaid.products.find((p) => p.selection.Display === "Stand").line_key,
  ).not.toBe(
    captured.attempts[key].lines.find((p) => p.selection.Display === "Stand")
      .line_key,
  );
  const pending = await control(page, { action: "inspect", attempt_key: key });
  expect(pending.order_status).toBe("Unpaid");
  expect(pending.attempt_count).toBe(1);
  // Existing owned attempt resolves before an invalid/expired quote token.
  const csrf = await page
    .locator('meta[name="csrf-token"]')
    .getAttribute("content");
  const retry = await page.request.post("/checkout", {
    data: { ...sent, attempt_key: key, quote_token: "no-longer-valid" },
    headers: { "X-CSRF-TOKEN": csrf, Accept: "application/json" },
  });
  expect(retry.status()).toBe(200);
  expect((await retry.json()).attempt_key).toBe(key);
  const outsider = await browser.newContext();
  const outsiderPage = await outsider.newPage();
  expect(
    (await outsiderPage.goto(`/orders/${pending.order_id}`)).status(),
  ).toBe(403);
  expect(
    (await outsiderPage.goto(`/success?attempt_key=${key}`)).status(),
  ).toBe(403);
  await outsider.close();
  await control(page, { action: "paid", attempt_key: key });
  await control(page, {
    action: "product",
    product_id: 4,
    name: "Fixture changed after purchase",
    price_minor: 9999,
  });
  await control(page, { action: "product", product_id: 4, delete: true });
  // The real protected return reconciles current provider proof and grants access.
  await page
    .getByRole("link", { name: "Check payment status", exact: true })
    .click();
  await expect(page).toHaveURL(new RegExp(`/orders/${pending.order_id}$`));
  await expect(
    page.getByRole("heading", { name: "Paid", exact: true }),
  ).toBeVisible();
  await expect(
    page.getByRole("heading", { name: "Fixture — Cards example", exact: true }),
  ).toHaveCount(2);
  await expect(
    page.getByText("1 Fixture Street", { exact: false }),
  ).toBeVisible();
  expect(await page.locator("body").innerText()).not.toContain(
    "Fixture changed after purchase",
  );
  expect(page.url()).not.toMatch(/token|session_id|signature/);
  const after = await stored(page);
  expect(
    after.products.find((p) => p.selection.Display === "Standard").quantity,
  ).toBe(2);
  expect(
    after.products.find((p) => p.selection.Display === "Stand").quantity,
  ).toBe(1);
  expect(after.products.find((p) => p.id === 1).quantity).toBe(1);
  expect(after.settled).toContain(key);
  await page.reload();
  expect((await stored(page)).products).toEqual(after.products);
  await page.evaluate(() => {
    document.activeElement?.blur();
    window.scrollTo(0, 0);
  });
  await page.screenshot({
    path: path.join(
      process.env.MAXS_BROWSER_OUTPUT,
      "paid-immutable-order.png",
    ),
    fullPage: true,
  });
  await control(page, {
    action: "product",
    product_id: 4,
    restore: true,
    name: "Fixture — Cards example",
    price_minor: 4250,
    stock_quantity: 5,
    available: true,
  });
});

test("real manual validation and paused/deleted stale cart recovery", async ({
  page,
}) => {
  await page.goto("/product/3");
  await page.getByRole("button", { name: "Add to bag ↗" }).click();
  await page.goto("/checkout");
  await details(page, { mobile: "123" });
  await page.getByRole("button", { name: "Review your quote ↗" }).click();
  await expect(
    page.getByLabel("Australian mobile", { exact: true }),
  ).toHaveAttribute("aria-invalid", "true");
  await page
    .getByLabel("Australian mobile", { exact: true })
    .fill("0412345678");
  await control(page, { action: "product", product_id: 3, available: false });
  await page.getByRole("button", { name: "Review your quote ↗" }).click();
  await expect(
    page.getByText(
      "An item is unavailable or has insufficient stock. Refresh your cart.",
      { exact: true },
    ),
  ).toBeVisible();
  await control(page, { action: "product", product_id: 3, delete: true });
  await page.getByRole("button", { name: "Refresh bag", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Item no longer available" }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Remove item 3 from bag" }).click();
  await expect(
    page.getByText("Your bag is empty.", { exact: true }),
  ).toBeVisible();
  await control(page, {
    action: "product",
    product_id: 3,
    restore: true,
    available: true,
  });
});

test("real admin authoring, uploads, metadata recovery, stock controls and paid-only completion", async ({
  page,
  browser,
}) => {
  await page.goto("/login");
  await page
    .getByLabel("Email", { exact: true })
    .fill("browser-admin@example.test");
  await page.getByLabel("Password", { exact: true }).fill("isolated-password");
  await page.getByRole("button", { name: "Log in", exact: true }).click();
  await expect(
    page.getByRole("link", { name: "Admin", exact: true }),
  ).toBeVisible();
  await page.getByRole("link", { name: "Admin", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Manage the collection." }),
  ).toBeVisible();
  const created = [];
  for (const [index, category] of [
    "Jewellery",
    "Watches",
    "Gems",
    "Cards",
    "Other objects",
  ].entries()) {
    await page
      .getByRole("button", { name: "Create item", exact: true })
      .click();
    if (index === 0) {
      const checked = page.getByRole("checkbox", {
        name: "Allow new purchases (stock is checked separately)",
        exact: true,
      });
      await expect(checked).toBeChecked();
      const channels = (
        await checked.evaluate((el) => getComputedStyle(el).backgroundColor)
      )
        .match(/[\d.]+/g)
        .slice(0, 3)
        .map(Number)
        .map((v) => v / 255)
        .map((v) => (v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4));
      const luminance =
        0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
      expect(1.05 / (luminance + 0.05)).toBeGreaterThanOrEqual(3);
    }
    const name = `Browser fixture — ${category}`;
    await page.getByLabel("Item name", { exact: true }).fill(name);
    await page
      .getByLabel("Item description", { exact: true })
      .fill(
        "Synthetic browser-authoring fixture. Not real inventory or Max photography.",
      );
    await page.getByLabel("Price (AUD cents)", { exact: true }).fill("1999");
    await page
      .getByLabel("Physical stock (includes held units)", { exact: true })
      .fill("2");
    await page.getByRole("checkbox", { name: category, exact: true }).check();
    await page.getByRole("button", { name: "Add fact", exact: true }).click();
    await page.getByLabel("Fact 1 label", { exact: true }).fill("Condition");
    await page
      .getByLabel("Fact 1 value", { exact: true })
      .fill("Fixture wear; no grade claimed");
    if (index === 0) {
      await page
        .getByRole("checkbox", { name: "Fixture detail", exact: true })
        .check();
      await page
        .getByLabel("Add photographs", { exact: true })
        .setInputFiles([
          path.join(
            process.env.MAXS_BROWSER_OUTPUT,
            "../app/public/fixture-1-0.png",
          ),
          path.join(
            process.env.MAXS_BROWSER_OUTPUT,
            "../app/public/fixture-2-0.png",
          ),
        ]);
      await page
        .getByLabel("Photo 1 alternative text", { exact: true })
        .fill("First browser illustration fixture");
      await page
        .getByLabel("Photo 1 caption", { exact: true })
        .fill("Illustration fixture, not Max photography");
      await page
        .getByLabel("Photo 2 alternative text", { exact: true })
        .fill("Second browser illustration fixture");
      await page
        .getByRole("button", { name: "Move photo 2 up", exact: true })
        .click();
    } else {
      if (index === 1) {
        const failed = page.waitForResponse(
          (r) =>
            r.url().endsWith("/product/store") &&
            r.request().method() === "POST",
        );
        await page
          .getByRole("button", { name: "Save item", exact: true })
          .click();
        expect((await failed).status()).toBe(422);
        await expect(page.getByRole("alert")).toContainText("no_images");
        await expect(page.getByLabel("Item name", { exact: true })).toHaveValue(
          name,
        );
      }
      await page
        .getByRole("checkbox", {
          name: "Confirm this item has no photographs",
          exact: true,
        })
        .check();
    }
    if (category === "Cards") {
      await page
        .getByRole("button", { name: "Add selection", exact: true })
        .click();
      await page
        .getByLabel("Selection 1 name", { exact: true })
        .fill("Display");
      await page
        .getByLabel("Selection 1 values (one per line)", { exact: true })
        .fill("Desk\nBox");
    }
    if (index === 0) {
      await page.evaluate(() => {
        document.activeElement?.blur();
        window.scrollTo(0, 0);
      });
      await page.screenshot({
        path: path.join(
          process.env.MAXS_BROWSER_OUTPUT,
          "admin-authoring-form.png",
        ),
        fullPage: true,
      });
    }
    const saved = page.waitForResponse(
      (r) =>
        r.url().endsWith("/product/store") && r.request().method() === "POST",
    );
    await page.getByRole("button", { name: "Save item", exact: true }).click();
    const response = await saved;
    expect(response.status()).toBe(201);
    const { product } = await response.json();
    created.push(product);
    await expect(
      page.getByRole("button", { name: `Edit ${name}`, exact: true }),
    ).toBeVisible();
    if (index === 0) {
      expect(product.images).toHaveLength(2);
      expect(product.images[0].alt_text).toBe(
        "Second browser illustration fixture",
      );
      expect(product.type_tag_ids).toHaveLength(1);
    } else expect(product.no_images).toBe(true);
    if (category === "Cards")
      expect(product.options[0].values).toEqual(["Desk", "Box"]);
  }
  await page
    .getByRole("button", { name: `Edit ${created[0].name}`, exact: true })
    .click();
  await page.getByLabel("Add photographs", { exact: true }).setInputFiles({
    name: "invalid.png",
    mimeType: "image/png",
    buffer: Buffer.from("not an actual image"),
  });
  const invalid = page.waitForResponse(
    (r) =>
      r.url().endsWith(`/product/${created[0].id}/update`) &&
      r.request().method() === "POST",
  );
  await page.getByRole("button", { name: "Save item", exact: true }).click();
  expect((await invalid).status()).toBe(422);
  await expect(
    page.getByRole("img", {
      name: "Second browser illustration fixture",
      exact: true,
    }),
  ).toBeVisible();
  await page
    .getByRole("button", { name: "Remove photo 3", exact: true })
    .click();
  await page
    .getByRole("button", { name: "Remove photo 2", exact: true })
    .click();
  await page
    .getByLabel("Photo 1 caption", { exact: true })
    .fill("Updated supplied fixture caption");
  const updated = page.waitForResponse(
    (r) =>
      r.url().endsWith(`/product/${created[0].id}/update`) &&
      r.request().method() === "POST",
  );
  await page.getByRole("button", { name: "Save item", exact: true }).click();
  expect((await updated).status()).toBe(200);
  await page.goto(`/product/${created[0].id}`);
  const renderedPhoto = await page
    .locator(".main-photo img")
    .evaluate(async (image) => {
      const response = await fetch(image.currentSrc);
      const bitmap = await createImageBitmap(await response.blob());
      return {
        width: bitmap.width,
        height: bitmap.height,
        source: new URL(image.currentSrc).pathname,
      };
    });
  expect([480, 960]).toContain(renderedPhoto.width);
  expect(renderedPhoto.height).toBe(renderedPhoto.width);
  expect(renderedPhoto.source).toMatch(/\/(480|960)\.webp$/);
  await expect(
    page.getByText("Updated supplied fixture caption", { exact: true }),
  ).toBeVisible();
  await expect(
    page.getByText("Fixture wear; no grade claimed", { exact: true }),
  ).toBeVisible();
  await page.goto("/admin");
  await page
    .getByRole("button", { name: `Pause ${created[0].name}`, exact: true })
    .click();
  await expect(
    page.getByRole("button", {
      name: `Resume ${created[0].name}`,
      exact: true,
    }),
  ).toBeVisible();
  await page
    .getByRole("button", { name: `Resume ${created[0].name}`, exact: true })
    .click();
  await expect(
    page.getByRole("button", { name: `Pause ${created[0].name}`, exact: true }),
  ).toBeVisible();
  await page.evaluate(() => {
    document.activeElement?.blur();
    window.scrollTo(0, 0);
  });
  await page.screenshot({
    path: path.join(process.env.MAXS_BROWSER_OUTPUT, "admin-inventory.png"),
    fullPage: true,
  });
  // A second actual guest checkout remains Unpaid; browser cancellation never releases it.
  const guest = await browser.newContext();
  const guestPage = await guest.newPage();
  await guestPage.goto("/product/1");
  await guestPage.getByRole("button", { name: "Add to bag ↗" }).click();
  await guestPage.goto("/checkout");
  await details(guestPage);
  await control(guestPage, {
    action: "behavior",
    behavior: "timeout_after_create",
  });
  await guestPage.getByRole("button", { name: "Review your quote ↗" }).click();
  const pendingResponse = guestPage.waitForResponse(
    (r) => r.url().endsWith("/checkout") && r.request().method() === "POST",
  );
  await guestPage
    .getByRole("button", { name: "Accept quote & continue ↗" })
    .click();
  const pending = await (await pendingResponse).json();
  await page.reload();
  await page.getByRole("button", { name: "Orders", exact: true }).click();
  const unpaid = page
    .getByRole("region", { name: "Orders", exact: true })
    .locator("article")
    .filter({ hasText: pending.order_code });
  await expect(
    unpaid.getByRole("button", {
      name: `Complete order ${pending.order_code}`,
      exact: true,
    }),
  ).toBeDisabled();
  const paid = page
    .getByRole("region", { name: "Orders", exact: true })
    .locator("article")
    .filter({ has: page.getByText("Status: Paid", { exact: true }) })
    .first();
  const link = await paid.getByRole("link").getAttribute("href");
  await paid.getByRole("button", { name: /^Complete order/ }).click();
  await expect(
    page.getByText(
      "Order marked completed. The completion email is queued for delivery.",
      { exact: true },
    ),
  ).toBeVisible();
  const completed = page
    .getByRole("region", { name: "Orders", exact: true })
    .locator("article")
    .filter({ has: page.locator(`a[href="${link}"]`) });
  await expect(
    completed.getByRole("button", { name: /^Complete order/ }),
  ).toBeDisabled();
  const csrf = await page
    .locator('meta[name="csrf-token"]')
    .getAttribute("content");
  expect(
    (
      await page.request.post(`${link}/complete`, {
        headers: { "X-CSRF-TOKEN": csrf, Accept: "application/json" },
      })
    ).status(),
  ).toBe(200);
  expect((await page.request.get(`${link}/complete`)).status()).toBe(405);
  await page.evaluate(() => {
    document.activeElement?.blur();
    window.scrollTo(0, 0);
  });
  await page.screenshot({
    path: path.join(process.env.MAXS_BROWSER_OUTPUT, "admin-orders.png"),
    fullPage: true,
  });
  // Close the synthetic pending fixture through real provider proof/reconciliation,
  // then restore its physical stock; never delete a hold directly for test cleanup.
  await control(guestPage, {
    action: "paid",
    attempt_key: pending.attempt_key,
  });
  await guestPage.goto(
    `/success?attempt_key=${encodeURIComponent(pending.attempt_key)}`,
  );
  await expect(
    guestPage.getByRole("heading", { name: "Paid", exact: true }),
  ).toBeVisible();
  await control(guestPage, {
    action: "product",
    product_id: 1,
    stock_quantity: 1,
  });
  await guest.close();
  await page.getByRole("button", { name: "Products", exact: true }).click();
  for (const item of created) {
    await page
      .getByRole("button", { name: `Delete ${item.name}`, exact: true })
      .click();
    await page
      .getByRole("button", { name: `Confirm delete ${item.name}`, exact: true })
      .click();
    await expect(
      page.getByRole("button", { name: `Edit ${item.name}`, exact: true }),
    ).not.toBeVisible();
  }
  expect((await page.request.get(`/product/${created[0].id}`)).status()).toBe(
    404,
  );
});
