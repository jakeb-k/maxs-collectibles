import React from "react";
import { renderToStaticMarkup } from "react-dom/server";
import QuoteTotals from "../../resources/js/Components/common/QuoteTotals.jsx";
import { test, expect } from "@playwright/test";
import path from "node:path";
import fs from "node:fs";
const output = process.env.MAXS_BROWSER_OUTPUT;
async function capture(page, name) {
  for (const img of await page.locator("img").all()) {
    await img.scrollIntoViewIfNeeded();
    await img.evaluate((el) => el.decode().catch(() => {}));
  }
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.screenshot({
    path: path.join(output, `${name}.png`),
    fullPage: true,
  });
}
for (const viewport of [
  { width: 1440, height: 1000 },
  { width: 390, height: 844 },
]) {
  const mode = viewport.width > 700 ? "desktop" : "mobile";
  test(`real storefront slice: ${mode}`, async ({ page }) => {
    await page.setViewportSize(viewport);
    await page.emulateMedia({ reducedMotion: "reduce" });
    const errors = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await page.goto("/");
    await expect(
      page.getByRole("heading", { name: "Objects worth a closer look." }),
    ).toBeVisible();
    await expect(
      page.getByRole("link", { name: "Explore the collection", exact: true }),
    ).toBeVisible();
    await expect(page.locator(".hero-object img")).toBeVisible();
    await expect(page.locator(".hero-object img")).toHaveJSProperty(
      "naturalWidth",
      960,
    );
    await capture(page, `${mode}-home`);
    await page.reload();
    await expect(
      page.getByRole("heading", { name: "Objects worth a closer look." }),
    ).toBeVisible();
    if (mode === "mobile") {
      await page.getByRole("button", { name: "Menu", exact: true }).click();
      await expect(
        page.getByRole("navigation", { name: "Main navigation" }),
      ).toBeVisible();
      await page.getByRole("button", { name: "Close menu" }).click();
    }
    await page.goto("/products/all");
    await expect(page.locator(".product-card")).toHaveCount(7);
    await capture(page, `${mode}-category`);
    await page.getByRole("searchbox").fill("Watches");
    await page
      .getByRole("combobox", { name: "Availability", exact: true })
      .selectOption("in_stock");
    await page
      .getByRole("combobox", { name: "Sort by", exact: true })
      .selectOption("price_asc");
    await page.getByRole("button", { name: "Apply", exact: true }).click();
    await expect(page.locator(".product-card")).toHaveCount(1);
    await expect(page.locator(".product-card h3")).toContainText("Watches");
    await page.getByRole("searchbox").fill("no-such-object");
    await page.getByRole("button", { name: "Apply", exact: true }).click();
    await expect(
      page.getByRole("heading", { name: "No objects found." }),
    ).toBeVisible();
    await capture(page, `${mode}-empty`);
    await page.goto("/products/jewellery");
    await page
      .locator(".product-card h3 a")
      .filter({ hasText: "Jewellery study" })
      .click();
    await expect(
      page.getByRole("heading", { name: "Fixture — Jewellery study" }),
    ).toBeVisible();
    await expect(
      page.getByText(
        "Illustration fixture — not Max’s photography or real inventory.",
        { exact: true },
      ),
    ).toBeVisible();
    await expect(page.locator(".gallery .main-photo img")).toHaveJSProperty(
      "naturalWidth",
      960,
    );
    await page.getByRole("button", { name: "Next photograph" }).click();
    await expect(page.locator(".gallery-controls")).toContainText("2 / 2");
    await page.locator(".main-photo").focus();
    await page.keyboard.press("ArrowLeft");
    await expect(page.locator(".gallery-controls")).toContainText("1 / 2");
    await capture(page, `${mode}-item`);
    await page.getByRole("button", { name: "Add to bag ↗" }).click();
    await expect(
      page.getByRole("status").filter({ hasText: "Added to your bag." }),
    ).toBeVisible();
    await page.getByRole("button", { name: /^Bag/ }).click();
    await page.getByRole("link", { name: "Review checkout ↗" }).click();
    await expect(
      page.getByRole("heading", { name: "Your next find." }),
    ).toBeVisible();
    await page.getByLabel("First name", { exact: true }).fill("Fixture");
    await page.getByLabel("Last name", { exact: true }).fill("Buyer");
    await page
      .getByLabel("Email", { exact: true })
      .first()
      .fill("fixture@example.test");
    await page
      .getByLabel("Australian mobile", { exact: true })
      .fill("0412345678");
    await page
      .getByLabel("Street address", { exact: true })
      .fill("1 Fixture Street");
    await page.getByLabel("Suburb / city", { exact: true }).fill("Brisbane");
    await page.getByLabel("State / territory", { exact: true }).fill("QLD");
    await page.getByLabel("Postcode", { exact: true }).fill("4000");
    await page.getByRole("button", { name: "Review your quote ↗" }).click();
    await expect(
      page.getByRole("button", { name: "Accept quote & continue ↗" }),
    ).toBeVisible();
    await expect(page.locator(".quote-totals")).toContainText("$12.50");
    await capture(page, `${mode}-checkout`);
    await page.goto("/products/all");
    await page
      .locator(".product-card h3 a")
      .filter({ hasText: "no-photo object" })
      .click();
    await expect(
      page.getByRole("img", { name: /No photograph available/ }),
    ).toBeVisible();
    await expect(
      page.getByRole("button", { name: "Unavailable", exact: true }),
    ).toBeDisabled();
    await capture(page, `${mode}-no-photo`);
    await page.goto("/products/jewellery");
    await page.locator(".product-card h3 a").first().click();
    await expect(page.locator(".item-details h1")).toBeVisible();
    await page.route("**/__test/media/**", (route) => route.abort());
    await page.reload();
    await expect(page.locator(".main-photo .photo-fallback")).toBeVisible();
    const missing = await page.goto("/product/999999");
    expect(missing.status()).toBe(404);
    await page.goto("/");
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= window.innerWidth,
      ),
    ).toBe(true);
    expect(await page.locator("body").innerText()).not.toMatch(
      /Picklewear|REALDILL|rating|free shipping/i,
    );
    expect(errors).toEqual([]);
  });
}

// Render the production presenter using actual PricingService output generated
// from tests/Fixtures/pricing.json by the guarded fixture helper.
const quoteFixtures = JSON.parse(
  fs.readFileSync(path.join(output, "../quote-display.json"), "utf8"),
);
for (const fixture of [
  {
    mode: "none",
    subtotal: "$50.00 AUD",
    delivery: "$1.23 AUD",
    tax: "$0.00 AUD",
    total: "$51.23 AUD",
  },
  {
    mode: "inclusive",
    subtotal: "$22.00 AUD",
    delivery: "$5.50 AUD",
    tax: "$2.50 AUD",
    total: "$27.50 AUD",
  },
  {
    mode: "exclusive",
    subtotal: "$29.97 AUD",
    delivery: "$5.00 AUD",
    tax: "$3.50 AUD",
    total: "$38.47 AUD",
  },
]) {
  test(`rendered canonical quote breakdown: ${fixture.mode} delivery`, async ({
    page,
  }) => {
    await page.setContent(
      renderToStaticMarkup(
        React.createElement(QuoteTotals, {
          quote: quoteFixtures[fixture.mode],
        }),
      ),
    );
    const row = (label) =>
      page
        .locator(".quote-totals > div")
        .filter({
          has: page.locator("dt", {
            hasText: new RegExp(`^${label.replace(/[()]/g, "\\$&")}$`),
          }),
        })
        .locator("dd");
    await expect(row("Subtotal")).toHaveText(fixture.subtotal);
    await expect(row("Discount")).toHaveText("$0.00 AUD");
    await expect(
      row(fixture.mode === "inclusive" ? "Tax (included)" : "Tax"),
    ).toHaveText(fixture.tax);
    await expect(row("Delivery")).toHaveText(fixture.delivery);
    await expect(row("Total")).toHaveText(fixture.total);
  });
}

test("versioned local migrations discard unsafe data and resolve current facts with recovery", async ({
  page,
}) => {
  await page.goto("/");
  await page.evaluate(() => {
    localStorage.setItem(
      "cart-storage",
      JSON.stringify({
        state: {
          products: [
            {
              id: 4,
              quantity: 2,
              selection: { Display: "Standard" },
              price: 0.01,
              name: "FORGED CACHE NAME",
            },
            { id: 0, quantity: 1 },
            { id: 4, quantity: -2 },
            { id: 3, quantity: 1, color: "blue", size: "L" },
            null,
          ],
        },
        version: 0,
      }),
    );
    localStorage.setItem(
      "favs-storage",
      JSON.stringify({
        state: {
          favourites: [
            { id: 4, name: "FORGED FAVOURITE" },
            { id: 999999 },
            null,
            { id: -1 },
          ],
        },
        version: 0,
      }),
    );
  });
  await page.goto("/checkout");
  await expect(
    page.getByRole("link", { name: "Fixture — Cards example", exact: true }),
  ).toBeVisible();
  await expect(
    page.getByRole("spinbutton", {
      name: "Quantity for Fixture — Cards example",
    }),
  ).toHaveValue("2");
  expect(await page.locator("body").innerText()).not.toMatch(/FORGED|0\.01/);
  await page
    .getByRole("spinbutton", { name: "Quantity for Fixture — Cards example" })
    .fill("0");
  await expect(
    page.getByRole("spinbutton", {
      name: "Quantity for Fixture — Cards example",
    }),
  ).toHaveValue("2");
  await page
    .getByRole("spinbutton", { name: "Quantity for Fixture — Cards example" })
    .fill("3");
  await page.reload();
  await expect(
    page.getByRole("spinbutton", {
      name: "Quantity for Fixture — Cards example",
    }),
  ).toHaveValue("3");
  const storage = await page.evaluate(() =>
    JSON.parse(localStorage.getItem("cart-storage")),
  );
  expect(storage.version).toBe(2);
  expect(storage.state.products).toHaveLength(1);
  expect(Object.keys(storage.state.products[0]).sort()).toEqual([
    "id",
    "line_key",
    "quantity",
    "selection",
  ]);
  await page.goto("/products/favourites");
  await expect(page.locator(".product-card")).toHaveCount(1);
  await expect(
    page.getByText("1 saved item(s) are no longer available.", {
      exact: false,
    }),
  ).toBeVisible();
  await page
    .getByRole("button", { name: "Remove unavailable favourites" })
    .click();
  await page.reload();
  await expect(page.locator(".product-card")).toHaveCount(1);
  await page.locator(".product-card h3 a").click();
  await expect(
    page.getByRole("button", { name: "Remove from favourites" }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Remove from favourites" }).click();
  await page.goto("/products/favourites");
  await expect(
    page.getByRole("heading", { name: "No favourites saved yet." }),
  ).toBeVisible();
  await page.goto("/checkout");
  await page.route("**/products/resolve?**", (route) => route.abort());
  await page.reload();
  await expect(
    page.getByRole("button", { name: "Retry bag refresh" }),
  ).toBeVisible();
  await page.unroute("**/products/resolve?**");
  await page.getByRole("button", { name: "Retry bag refresh" }).click();
  await expect(
    page.getByRole("link", { name: "Fixture — Cards example", exact: true }),
  ).toBeVisible();
  await page
    .getByRole("button", { name: "Remove Fixture — Cards example from bag" })
    .click();
  await expect(
    page.getByText("Your bag is empty.", { exact: true }),
  ).toBeVisible();
  await page.evaluate(() =>
    localStorage.setItem("cart-storage", "{unreadable"),
  );
  await page.reload();
  await expect(
    page.getByText("Your bag is empty.", { exact: true }),
  ).toBeVisible();
  await expect(
    page.getByText("Saved shopping data was unreadable and has been reset.", {
      exact: true,
    }),
  ).toBeVisible();
});

test("local cart identity includes supplied selections and survives reload", async ({
  page,
}) => {
  await page.goto("/product/4");
  await page
    .getByRole("combobox", { name: "Display", exact: true })
    .selectOption("Standard");
  await page.getByRole("button", { name: "Add to bag ↗" }).click();
  await page
    .getByRole("combobox", { name: "Display", exact: true })
    .selectOption("Stand");
  await page.getByRole("button", { name: "Add to bag ↗" }).click();
  await page.getByRole("button", { name: "Add to bag ↗" }).click();
  await page.reload();
  const items = await page.evaluate(
    () => JSON.parse(localStorage.getItem("cart-storage")).state.products,
  );
  expect(items).toHaveLength(2);
  expect(items.find((p) => p.selection.Display === "Stand").quantity).toBe(2);
  expect(items.find((p) => p.selection.Display === "Standard").quantity).toBe(
    1,
  );
});
