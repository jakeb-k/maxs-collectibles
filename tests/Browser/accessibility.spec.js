import { test, expect } from "@playwright/test";

test("checkout keyboard, described errors, bag focus, reduced motion and rendered contrast", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.emulateMedia({ reducedMotion: "reduce" });
  await page.goto("/product/1");
  await page.getByRole("button", { name: "Add to bag ↗" }).click();
  await page.getByRole("button", { name: /^Bag/ }).focus();
  await page.keyboard.press("Enter");
  await expect(
    page.getByRole("dialog", { name: "Your shopping bag" }),
  ).toBeVisible();
  await page.keyboard.press("Escape");
  await expect(
    page.getByRole("dialog", { name: "Your shopping bag" }),
  ).not.toBeVisible();
  await expect(page.getByRole("button", { name: /^Bag/ })).toBeFocused();
  await page.goto("/checkout");
  const fields = [
    ["First name", "Fixture"],
    ["Last name", "Buyer"],
    ["Email", "fixture@example.test"],
    ["Australian mobile", "123"],
    ["Street address", "1 Fixture Street"],
    ["Suburb / city", "Brisbane"],
    ["State / territory", "QLD"],
    ["Postcode", "4000"],
  ];
  for (const [label, value] of fields) {
    const input = page.getByLabel(label, { exact: true });
    await input.focus();
    await page.keyboard.type(value);
  }
  await page.getByRole("button", { name: "Review your quote ↗" }).focus();
  await page.keyboard.press("Enter");
  const mobile = page.getByLabel("Australian mobile", { exact: true });
  await expect(mobile).toHaveAttribute("aria-invalid", "true");
  await expect(page.locator('[role="alert"]')).toBeFocused();
  const description = await mobile.getAttribute("aria-describedby");
  expect(description).toBe("contact.mobile-error");
  await expect(page.locator('[id="contact.mobile-error"]')).toBeVisible();
  const colors = await mobile.evaluate((el) => ({
    foreground: getComputedStyle(el).color,
    background: getComputedStyle(el).backgroundColor,
  }));
  const luminance = (rgb) => {
    const values = rgb
      .match(/[\d.]+/g)
      .slice(0, 3)
      .map(Number)
      .map((v) => v / 255)
      .map((v) => (v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4));
    return 0.2126 * values[0] + 0.7152 * values[1] + 0.0722 * values[2];
  };
  const a = luminance(colors.foreground),
    b = luminance(colors.background);
  expect(
    (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05),
  ).toBeGreaterThanOrEqual(4.5);
  await mobile.fill("0412345678");
  await page.getByRole("button", { name: "Review your quote ↗" }).click();
  await expect(
    page.getByRole("button", { name: "Accept quote & continue ↗" }),
  ).toBeVisible();
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBe(true);
  expect(
    await page.evaluate(
      () => matchMedia("(prefers-reduced-motion: reduce)").matches,
    ),
  ).toBe(true);
});

import fs from "node:fs";
import path from "node:path";
const output = process.env.MAXS_BROWSER_OUTPUT;
const evidence = (name, value) =>
  fs.writeFileSync(
    path.join(output, name),
    typeof value === "string" ? value : JSON.stringify(value, null, 2),
  );
async function contrast(page, name) {
  const rows = await page
    .locator("main, header, footer")
    .evaluateAll((roots) => {
      const lum = (rgb) => {
        const [r, g, b] = rgb
          .map((v) => v / 255)
          .map((v) =>
            v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4,
          );
        return 0.2126 * r + 0.7152 * g + 0.0722 * b;
      };
      const rgb = (value) => value.match(/[\d.]+/g)?.map(Number) || [];
      const result = [];
      for (const root of roots)
        for (const el of [root, ...root.querySelectorAll("*")]) {
          if (
            !el.getClientRects().length ||
            getComputedStyle(el).visibility === "hidden" ||
            el.closest("[disabled], .object-mark")
          )
            continue;
          const style = getComputedStyle(el);
          if (
            ![...el.childNodes].some(
              (n) => n.nodeType === Node.TEXT_NODE && n.textContent.trim(),
            ) &&
            !el.matches("input,textarea,select")
          )
            continue;
          let background = [16, 21, 19],
            transparent = true;
          for (let p = el; p; p = p.parentElement) {
            const color = rgb(getComputedStyle(p).backgroundColor);
            if (color.length >= 3 && (color.length === 3 || color[3] === 1)) {
              background = color.slice(0, 3);
              transparent = false;
              break;
            }
          }
          const foreground = rgb(style.color).slice(0, 3);
          const a = lum(foreground),
            b = lum(background);
          const ratio = (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
          const large =
            parseFloat(style.fontSize) >= 24 ||
            (parseFloat(style.fontSize) >= 18.66 &&
              Number(style.fontWeight) >= 700);
          result.push({
            text: (
              el.innerText ||
              el.getAttribute("aria-label") ||
              el.name ||
              el.tagName
            ).slice(0, 90),
            ratio,
            required: large ? 3 : 4.5,
            foreground,
            background,
            transparent,
          });
        }
      return result;
    });
  evidence(`${name}-contrast.json`, rows);
  expect(rows.length).toBeGreaterThan(10);
  expect(rows.filter((r) => r.ratio + 0.01 < r.required)).toEqual([]);
}
async function reflow(page, name) {
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth + 1,
    ),
  ).toBe(true);
  await page.evaluate(() => {
    document.activeElement?.blur();
    scrollTo(0, 0);
  });
  await page.screenshot({
    path: path.join(output, `${name}.png`),
    fullPage: true,
  });
}

test("keyboard skip/menu/catalogue/gallery and AX semantics with focus visible", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto("/");
  await page.keyboard.press("Tab");
  await expect(
    page.getByRole("link", { name: "Skip to content" }),
  ).toBeFocused();
  await page.keyboard.press("Enter");
  await expect(page.locator("main")).toBeFocused();
  const menu = page.locator('button[aria-controls="store-navigation"]');
  await expect(menu).toHaveAccessibleName("Menu");
  await menu.focus();
  await page.keyboard.press("Enter");
  await expect(menu).toHaveAttribute("aria-expanded", "true");
  await expect(menu).toHaveAccessibleName("Close menu");
  await page.keyboard.press("Tab");
  await expect(
    page
      .getByRole("navigation", { name: "Main navigation" })
      .getByRole("link", { name: "Collection", exact: true }),
  ).toBeFocused();
  await page.keyboard.press("Enter");
  await expect(
    page.getByRole("heading", { name: "The collection", exact: true }),
  ).toBeVisible();
  await page.getByRole("searchbox").focus();
  await page.keyboard.type("Jewellery");
  await page.getByRole("button", { name: "Apply", exact: true }).focus();
  await page.keyboard.press("Enter");
  await expect(page.locator(".product-card")).toHaveCount(2);
  await page.locator(".product-card h3 a").first().focus();
  await page.keyboard.press("Enter");
  await expect(page.locator(".gallery-controls")).toContainText("1 / 2");
  await page.locator(".main-photo").focus();
  await page.keyboard.press("ArrowRight");
  await expect(page.locator(".gallery-controls")).toContainText("2 / 2");
  await page.getByRole("button", { name: "Previous photograph" }).focus();
  expect(
    await page
      .getByRole("button", { name: "Previous photograph" })
      .evaluate((el) => getComputedStyle(el).outlineStyle),
  ).toBe("solid");
  await page.keyboard.press("Enter");
  await expect(
    page.getByRole("button", { name: "View photograph 1", exact: true }),
  ).toHaveAttribute("aria-pressed", "true");
  await page
    .getByRole("button", { name: "Save to favourites", exact: true })
    .focus();
  await page.keyboard.press("Enter");
  await expect(
    page.getByRole("button", { name: "Remove from favourites", exact: true }),
  ).toHaveAttribute("aria-pressed", "true");
  evidence("item-ax-tree.txt", await page.locator("body").ariaSnapshot());
  await contrast(page, "item-selected");
  await reflow(page, "keyboard-item-mobile");
});

test("account and admin keyboard errors, labelled authoring controls, zoom and narrow reflow", async ({
  page,
}) => {
  await page.goto("/login");
  await page
    .getByLabel("Email", { exact: true })
    .fill("browser-admin@example.test");
  await page.getByLabel("Password", { exact: true }).fill("incorrect-password");
  await page.getByRole("button", { name: "Log in", exact: true }).focus();
  await page.keyboard.press("Enter");
  await expect(page.getByRole("alert")).toBeFocused();
  await expect(page.getByLabel("Email", { exact: true })).toHaveAttribute(
    "aria-invalid",
    "true",
  );
  await contrast(page, "login-error");
  await page.getByLabel("Password", { exact: true }).fill("isolated-password");
  await page.getByRole("button", { name: "Log in", exact: true }).focus();
  await page.keyboard.press("Enter");
  await page.getByRole("link", { name: "Admin", exact: true }).focus();
  await page.keyboard.press("Enter");
  await page.getByRole("button", { name: "Create item", exact: true }).focus();
  await page.keyboard.press("Enter");
  await expect(page.getByLabel("Item name", { exact: true })).toBeVisible();
  const unnamed = await page
    .locator("main input:not([type=hidden]),main textarea,main select")
    .evaluateAll((inputs) =>
      inputs
        .filter(
          (el) =>
            !el.labels?.length &&
            !el.getAttribute("aria-label") &&
            !el.getAttribute("aria-labelledby"),
        )
        .map((el) => el.outerHTML),
    );
  expect(unnamed).toEqual([]);
  await page
    .getByLabel("Item name", { exact: true })
    .fill("Accessibility synthetic item");
  await page
    .getByLabel("Item description", { exact: true })
    .fill("Labelled fixture only; not real inventory.");
  await page.getByRole("button", { name: "Save item", exact: true }).focus();
  await page.keyboard.press("Enter");
  await expect(page.getByRole("alert")).toBeFocused();
  await contrast(page, "admin-error");
  const textarea = page.getByRole("textbox", { name: "Item description", exact: true });
  await textarea.focus();
  expect(
    await textarea.evaluate((el) => getComputedStyle(el).outlineStyle),
  ).toBe("solid");
  await page.setViewportSize({ width: 1280, height: 1000 });
  await page.evaluate(() => (document.documentElement.style.zoom = "2"));
  await reflow(page, "admin-200percent-css-zoom");
  evidence("admin-zoom-ax-tree.txt", await page.locator("main").ariaSnapshot());
  await page.evaluate(() => (document.documentElement.style.zoom = ""));
  await page.setViewportSize({ width: 320, height: 800 });
  await reflow(page, "admin-320-reflow");
  await page.goto("/faqs");
  await reflow(page, "contact-320-reflow");
  await contrast(page, "contact");
  evidence("contact-ax-tree.txt", await page.locator("main").ariaSnapshot());
});

test("touch, reduced motion, slow and failed photography retain purchase controls without effects", async ({
  browser,
}) => {
  const context = await browser.newContext({
    viewport: { width: 390, height: 844 },
    isMobile: true,
    hasTouch: true,
    reducedMotion: "reduce",
  });
  const page = await context.newPage();
  let release;
  const delayed = new Promise((resolve) => (release = resolve));
  await page.route("**/__test/media/**", async (route) => {
    await delayed;
    await route.continue().catch(() => {});
  });
  await page.goto("/product/1", { waitUntil: "domcontentloaded" });
  await expect(
    page.getByRole("button", { name: "Add to bag ↗" }),
  ).toBeVisible();
  const before = await page.locator(".main-photo").boundingBox();
  await page.addStyleTag({
    content:
      "*,*::before,*::after{animation:none!important;transition:none!important;box-shadow:none!important;background-image:none!important}",
  });
  await page.getByRole("button", { name: "Add to bag ↗" }).tap();
  await expect(
    page.getByRole("status").filter({ hasText: "Added to your bag." }),
  ).toBeVisible();
  expect(
    await page.evaluate(
      () => matchMedia("(prefers-reduced-motion: reduce)").matches,
    ),
  ).toBe(true);
  expect(
    await page
      .getByRole("button", { name: "Add to bag ↗" })
      .evaluate((el) => el.getBoundingClientRect().height),
  ).toBeGreaterThanOrEqual(44);
  release();
  await page.locator(".main-photo img").evaluate((el) => el.decode());
  const after = await page.locator(".main-photo").boundingBox();
  expect(Math.abs(before.height - after.height)).toBeLessThan(1);
  await page.unroute("**/__test/media/**");
  await page.route("**/__test/media/**", (route) => route.abort("failed"));
  await page.reload();
  await expect(page.locator(".main-photo .photo-fallback")).toBeVisible();
  await page.getByRole("button", { name: "Next photograph" }).tap();
  await expect(page.locator(".gallery-controls")).toContainText("2 / 2");
  await page.getByRole("button", { name: "Add to bag ↗" }).tap();
  await page.getByRole("button", { name: /^Bag/ }).tap();
  await expect(
    page.getByRole("dialog", { name: "Your shopping bag" }),
  ).toBeVisible();
  await page.getByRole("link", { name: "Review checkout ↗" }).tap();
  await expect(
    page.getByRole("button", { name: "Review your quote ↗" }),
  ).toBeVisible();
  await reflow(page, "touch-failed-photo-checkout");
  evidence("resilience.json", {
    touch: true,
    reducedMotion: true,
    delayedPhotoLayoutBefore: before,
    delayedPhotoLayoutAfter: after,
    failedPhotoControls: "gallery, add, bag, checkout exercised",
    effects: "CSS animations/transitions/background images/shadows disabled",
  });
  await context.close();
});
