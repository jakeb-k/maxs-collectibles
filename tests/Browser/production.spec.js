import { test, expect } from "@playwright/test";
import fs from "node:fs";
import path from "node:path";
const output = process.env.MAXS_BROWSER_OUTPUT;

test("compiled CSR routes, responsive uploaded images and measured fixture performance", async ({
  browser,
}) => {
  test.setTimeout(90000);
  const context = await browser.newContext({
    viewport: { width: 1440, height: 1000 },
    deviceScaleFactor: 1,
  });
  const page = await context.newPage();
  const errors = [];
  page.on("pageerror", (e) => errors.push(e.message));
  page.on("console", (m) => {
    if (m.type() === "error") errors.push(m.text());
  });
  await page.goto("/login");
  await expect(page.getByLabel("Email", { exact: true })).toBeVisible();
  await page
    .getByLabel("Email", { exact: true })
    .fill("browser-admin@example.test");
  await page.getByLabel("Password", { exact: true }).fill("isolated-password");
  await page.getByRole("button", { name: "Log in", exact: true }).click();
  await page.getByRole("link", { name: "Admin", exact: true }).click();
  await page.getByRole("button", { name: "Create item", exact: true }).click();
  await page
    .getByLabel("Item name", { exact: true })
    .fill("Production image fixture");
  await page
    .getByLabel("Item description", { exact: true })
    .fill(
      "Synthetic performance fixture, not Max photography or real inventory.",
    );
  await page.getByLabel("Price (AUD cents)", { exact: true }).fill("1250");
  await page
    .getByLabel("Physical stock (includes held units)", { exact: true })
    .fill("1");
  await page.getByRole("checkbox", { name: "Jewellery", exact: true }).check();
  await page
    .getByLabel("Add photographs", { exact: true })
    .setInputFiles(path.join(output, "../app/public/fixture-1-0.png"));
  await page
    .getByLabel("Photo 1 alternative text", { exact: true })
    .fill("Synthetic derivative performance fixture");
  const saved = page.waitForResponse(
    (r) =>
      r.url().endsWith("/product/store") && r.request().method() === "POST",
  );
  await page.getByRole("button", { name: "Save item", exact: true }).click();
  const response = await saved;
  expect(response.status()).toBe(201);
  const id = (await response.json()).products.find(
    (p) => p.name === "Production image fixture",
  ).id;
  const measured = [];
  for (const viewport of [
    { width: 1440, height: 1000 },
    { width: 390, height: 844 },
  ]) {
    const measurementContext = await browser.newContext({
      viewport,
      deviceScaleFactor: 1,
    });
    const p = await measurementContext.newPage();
    p.on("pageerror", (e) => errors.push(e.message));
    p.on("console", (m) => {
      if (m.type() === "error") errors.push(m.text());
    });
    const cdp = await measurementContext.newCDPSession(p);
    await cdp.send("Network.enable");
    await cdp.send("Network.setCacheDisabled", { cacheDisabled: true });
    await cdp.send("Network.emulateNetworkConditions", {
      offline: false,
      latency: 150,
      downloadThroughput: 200000,
      uploadThroughput: 93750,
    });
    await cdp.send("Emulation.setCPUThrottlingRate", { rate: 4 });
    await p.addInitScript(() => {
      window.__presentation = { lcp: 0, cls: 0 };
      new PerformanceObserver((list) => {
        for (const e of list.getEntries())
          window.__presentation.lcp = e.startTime;
      }).observe({ type: "largest-contentful-paint", buffered: true });
      new PerformanceObserver((list) => {
        for (const e of list.getEntries())
          if (!e.hadRecentInput) window.__presentation.cls += e.value;
      }).observe({ type: "layout-shift", buffered: true });
    });
    for (const route of [
      "/",
      "/products/jewellery",
      `/product/${id}`,
      "/checkout",
    ]) {
      const res = await p.goto(route);
      expect(res.status()).toBe(200);
      await expect(p.locator("main h1")).toBeVisible();
      if (route === `/product/${id}`) {
        const image = p.locator(".main-photo img");
        await image.evaluate((el) => el.decode());
        const dimensions = await image.evaluate(async (el) => {
          const r = await fetch(el.currentSrc);
          const b = await r.blob();
          const bitmap = await createImageBitmap(b);
          return {
            source: el.currentSrc,
            sourceWidth: bitmap.width,
            sourceHeight: bitmap.height,
            bytes: b.size,
            displayWidth: el.getBoundingClientRect().width,
            sizes: el.sizes,
          };
        });
        expect(dimensions.source).toMatch(/\/(480|960|1600)\.webp$/);
        expect(dimensions.sourceWidth).toBeGreaterThanOrEqual(
          dimensions.displayWidth - 1,
        );
        expect(dimensions.sourceWidth).toBeLessThanOrEqual(960);
        measured.push({ viewport, route, image: dimensions });
        await p.getByRole("button", { name: "Add to bag ↗" }).click();
        await p.getByRole("button", { name: /^Bag/ }).click();
        await expect(
          p.getByRole("dialog", { name: "Your shopping bag" }),
        ).toBeVisible();
        await p.getByRole("button", { name: "Close bag", exact: true }).click();
      }
      await p.waitForTimeout(600); // bounded observer settling window, recorded below
      const data = await p.evaluate(() => ({
        navigation: performance.getEntriesByType("navigation")[0].toJSON(),
        paint: performance.getEntriesByType("paint").map((x) => x.toJSON()),
        ...window.__presentation,
        resources: performance
          .getEntriesByType("resource")
          .map((r) => ({
            name: r.name,
            bytes: r.transferSize,
            duration: r.duration,
            type: r.initiatorType,
          })),
        imageCount: document.images.length,
        overflow: document.documentElement.scrollWidth > innerWidth,
      }));
      expect(data.overflow).toBe(false);
      expect(data.cls).toBeLessThanOrEqual(0.1);
      expect(data.lcp).toBeGreaterThan(0);
      expect(data.lcp).toBeLessThan(8000);
      expect(
        data.resources.some(
          (r) => r.name.includes("/build/assets/") && r.type === "script",
        ),
      ).toBe(true);
      expect(
        data.resources.some(
          (r) =>
            r.name.includes("localhost:5173") ||
            r.name.includes("@vite/client"),
        ),
      ).toBe(false);
      measured.push({ viewport, route, ...data });
      if (route === `/product/${id}`)
        await p.screenshot({
          path: path.join(output, `production-item-${viewport.width}.png`),
          fullPage: true,
        });
    }
    await measurementContext.close();
  }
  await page.goto("/admin");
  const token = await page
    .locator('meta[name="csrf-token"]')
    .getAttribute("content");
  const deleted = await page.request.delete(`/product/${id}/delete`, {
    headers: { "X-CSRF-TOKEN": token, Accept: "application/json" },
  });
  expect(deleted.ok()).toBe(true);
  fs.writeFileSync(
    path.join(output, "performance.json"),
    JSON.stringify(
      {
        kind: "agent browser measurement; synthetic fixture, not real-photo or field performance",
        conditions: {
          browser: browser.version(),
          engine: "Chromium in pinned Playwright Linux container",
          network: "CDP 150ms latency, 1.6Mbps download, 750kbps upload",
          cpu: "CDP 4x slowdown; shared host, not calibrated handset",
          cache: "disabled each page navigation",
          deviceScaleFactor: 1,
          settlingMs: 600,
          layoutShiftBudget: 0.1,
          lcpRegressionBudgetMs: 8000,
          limits:
            "Local MySQL/app; labelled low-entropy PNG fixture and actual ImageAuthoring WebP derivatives. Does not establish real Max-photo transfer/decode/LCP or field Core Web Vitals.",
        },
        errors,
        measurements: measured,
      },
      null,
      2,
    ),
  );
  expect(errors).toEqual([]);
  await context.close();
});
