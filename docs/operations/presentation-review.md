# Presentation verification — 23 October 2026

This is an implementation-agent inspection, not a human signature or launch approval. The integrated run `check-1792728256002-83858314` reverified the final presentation source, including the local font preload: all 18 browser tests passed with zero failures, skips or flaky tests. The overall fresh gate still failed its PHP phase (201 tests, 19 failures and 16 errors); backend repairs and final release verification remain required.

## Reference and rendered inspection

Inspected `a_wide_high_end_ui_branding_presentation_board_in.png` from the selected Futurist Exchange complete pack before changes. The owner selected a darker, mysterious variation, with exact composition deferred. The implementation uses dark sage/charcoal surfaces, pale readable purchase controls, thin rules, restrained reflection and direct collection entry. The previous mixed sans/italic-serif home title was materially weaker than the reference's condensed display treatment; it now uses the existing local Oswald face. No new font service is required. The unused external Bunny font request was removed.

Inspected actual Chromium screenshots at 1440×1000 and 390×844, plus the 320px admin error form: home, item and checkout composition, header/navigation, image framing, form labels, purchase controls, footer and narrow wrapping. Desktop hero now has the intended condensed display hierarchy. Cold mobile initially retained the safe fallback font with optional font loading. A home-only preload repaired that first-paint discrepancy; the final integrated desktop and mobile captures both display the intended condensed hero without clipping or overflow. All captures use labelled illustration fixtures, not Max's photographs or actual inventory.

Tracked captures are in [screenshots](screenshots/): `desktop-home.png`, `mobile-home.png`, desktop/mobile category, item and checkout, and `admin-320-reflow.png`. All nine tracked captures now come from `storage/testing/runs/check-1792728256002-83858314/playwright`. The agent inspected the final desktop/mobile home, desktop category, mobile item and desktop checkout captures, plus earlier matching mobile checkout/admin reflow captures. The integrated suite mutates category fixtures during earlier admin checks, so the final home shows three category links and remaining untagged fixture cards use the Collectible fallback; this is synthetic test data state, not missing merchandise. No screenshot was synthesized or visually edited.

Motion is intentionally restrained; there is no entry gate or required reveal. Browser checks exercised reduced motion, touch, delayed and failed photography and disabled CSS effects while retaining gallery, bag and checkout actions. Actual photo character, condition detail, reflections and real-photo transfer/decode cost remain unaccepted until Max supplies assets. This is alignment with the selected darker direction, not exact replication of the original reference board.

## Checks and repairs

| Command | Recorded outcome |
| --- | --- |
| `node tools/check.mjs fresh` — browser phase | `check-1792728256002-83858314`: all 18 passed, 0 failed, 0 skipped, 0 flaky; full fresh overall failed the PHP phase |
| `node tools/check.mjs browser tests/Browser/accessibility.spec.js` | Final scoped run `check-1792728196456-93739874`: 4 passed, 0 failed, 0 skipped, 0 flaky |
| `node tools/check.mjs browser tests/Browser/storefront.spec.js` | `check-1792728215494-5d1a355a`: 7 passed, 0 failed, 0 skipped, 0 flaky |
| `node tools/check.mjs build` | `check-1792728051753-eea92752`: locked dependency installation/build and 1 production browser test passed |

Browser tests do not have PHPUnit's risky category. These scoped results do not describe the complete PHP/browser suite. The scoped production run preceded final checkbox-only CSS, removal of an unused external font request and the home font preload. Its measurements were superseded by the complete integrated browser phase, which rebuilt assets and reverified those final changes.

Initial accessibility reproduction confirmed 2 passing/2 failing tests. The menu test retained a locator by its changing name, so it could not find the expanded button. It now locates the controlling button by `aria-controls` and explicitly asserts both Menu and Close menu names plus expanded state and keyboard focus. No interaction check was removed. The login checkbox and checked admin checkbox had insufficient contrast; normal and selected controls now use pale sage ink/tick on the dark field. A populated textarea focus check now uses its exact textbox accessible name rather than mutable label text. Existing contrast thresholds and focus assertions remain intact.

Keyboard evidence covers skip link, mobile menu, catalogue search, gallery arrows/selection, favourites, bag Escape/focus restoration, checkout validation and quote continuation, account errors and admin authoring. Rendered contrast records cover normal/error/selected states. Admin reflow was exercised at 320px and 200% CSS zoom. CSS zoom is supplementary and does not establish browser-menu zoom or assistive-technology acceptance; no screen-reader session was performed.

## Performance conditions and limits

Evidence status: final presentation source revalidated by the complete integrated browser phase in `check-1792728256002-83858314`. This does not override the failing PHP gate or constitute final release approval.

Measured on Apple M5 Pro, Mac17,9, arm64; Docker reports Ubuntu 24.04.4 LTS/aarch64. Browser: Chromium 156.0.8078.4 in pinned Playwright 1.64.1 Noble container. Desktop/mobile viewports were 1440×1000 and 390×844 at device scale 1. CDP emulation used 150ms network latency, 1.6Mbps download, 750kbps upload, 4× CPU slowdown and disabled cache, with a bounded 600ms observer settling window. This shared host is not a calibrated handset.

The eight measured home/category/item/checkout navigations recorded LCP 2.680–3.612 seconds and CLS 0–0.0546, below the existing regression budgets of 8 seconds and 0.1. No console/page errors, overflow or development-server asset references were observed. Uploaded synthetic media generated real WebP derivatives: desktop selected 960px/11,074 bytes for a 657px display; mobile selected 480px/4,318 bytes for a 348px display. Raw measurements: `storage/testing/runs/check-1792728256002-83858314/playwright/performance.json`.

The condensed font initially introduced cold-load CLS 0.105; this failed the unchanged 0.1 budget and was repaired with optional display-font loading, then the production check passed. The final home-only preload preserves condensed first-paint typography without permitting a late font swap; the integrated production measurement records zero CLS on both home viewports. The representative fixtures are deliberately low entropy. These measurements do not establish real Max-photo performance, field Core Web Vitals or production hosting latency.

## Pending acceptance

K16 visual and K17 accessibility-performance remain pending named human attestation under `acceptance.md`; no reviewer identity or approval has been invented. Real photography/content review, actual assistive-technology testing, browser-menu zoom verification and field performance remain unresolved. Source identity scan of `resources/views`, `resources/js` and `app/Notifications` found no Picklewear/REALDILL/clothing/free-shipping/rating claims. This scan supports the identity check; it does not replace inspecting every delivered email in the final acceptance run.
