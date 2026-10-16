# Compatibility resolution

Resolved 16 October 2026 through the approved C0 bounded candidate. Composer selected Laravel 13.37.0, PHP 8.4.26, Inertia Laravel 2.0.28, Cashier 15.8.0, Stripe PHP 16.6.0, permission 6.25.0, tags 4.12.0, Breeze 2.4.2, Ziggy 2.6.5 and PHPUnit 12.5.40. Node build image is 24.22.0; browser image is Node 24.21.0 with Playwright 1.64.1. React remains 18.3.1 and Tailwind remains 3.4.19. Compatible frontend set: Vite 8.3.4, React plugin 6.1.2, Laravel Vite plugin 3.2.0, Inertia React 2.3.29. Exact transitive versions are in the lockfiles.

UUID moved from 10 to 14.0.3 to remove its advisory. The sole JS consumer imports `v4` and calls it without options; that API remains supported. Compatibility executes a named ESM v4/uniqueness/version check and builds the consumer. No CommonJS consumer exists in this application. React/Tailwind majors are preserved.

Composer audit reports zero advisories. npm's remaining seven entries (five high, two moderate) concern the retained Tailwind 3 graph: Tailwind, braces, chokidar, fast-glob, micromatch, postcss-nested and postcss-selector-parser. npm proposes Tailwind 4 as the fix. This is an explicit retained compatibility/security limitation requiring specialist risk review; no clean-audit or launch claim is made. No force-upgrade was run.

The first handoff incorrectly classified three missing-GD errors as inherited domain drift. Those were T01 toolchain defects: the PHP image now builds GD with JPEG/PNG/WebP support and Composer explicitly requires ext-gd. K01 checks actual resampling and encode/decode round trips for all three formats.

The inherited PHPUnit inventory is deliberately distinct from K01 environment verification: the post-GD verification on 16 October 2026 records 77 tests, 298 assertions, zero errors, eight failures and two risky tests after disabling absent optional SSR. Remaining cases concern product fixture/category/type validation, admin delete fixture contract, anonymous order authorization, invalid checkout mobile fixtures and success redirect expectations. Those repairs belong to the domain tasks. Original intermediate runs and full JUnit traces remain available; the current trace is in the implementation run's `T01/resume-20261016/inherited-junit.xml`. K18 fresh therefore currently fails; compatibility inventories this drift without claiming the suite passed.

The source always hydrated in production, even when SSR returned no HTML. Real Chromium exposed React 418/423. Bootstrap now uses createRoot for an empty root and hydrates only existing server markup. Optional SSR is disabled explicitly for isolated PHP/CSR checks. SSR bundles compile; full SSR runtime rendering of product pages remains unverified and has the previously recorded browser-global concern for frontend tasks.

Source derivation: `source-export.json` records the exact allowlisted export, all exclusions and zero collisions. README pointer was preserved. No reference Git/remotes/hooks, environment values, storage records or deploy workflows were copied. Exported executable reference seeders were subsequently removed and replaced by an empty DatabaseSeeder; only the runner creates an explicitly labelled example.test auth fixture. Reference sender addresses and FontAwesome account kit were removed/configured. Source supplier importer route is absent and importer/AI naming job handlers refuse execution. Existing domain models/controllers/UI remain for the ordered implementation tasks. The reference checkout remained clean.

Primary sources consulted:

- [Laravel 12 upgrade guide](https://laravel.com/docs/12.x/upgrade) and [Laravel 13 upgrade guide](https://laravel.com/docs/13.x/upgrade) for adjacent upgrade constraints.
- [Node release lifecycle](https://nodejs.org/en/about/previous-releases) and [Vite requirements](https://vite.dev/guide/).
- [Laravel Vite plugin manifest](https://github.com/laravel/vite-plugin/blob/3.x/package.json), [React Vite plugin manifest](https://github.com/vitejs/vite-plugin-react/blob/main/packages/plugin-react/package.json) and actual npm peer solver.
- [UUID API](https://github.com/uuidjs/uuid) and [release notes](https://github.com/uuidjs/uuid/releases), plus the public 14.0.3 registry manifest.
- [React hydration contract](https://react.dev/reference/react-dom/client/hydrateRoot): client-rendered empty roots use createRoot.
- [Official PHP image extension instructions](https://hub.docker.com/_/php) and [PHP GD installation options](https://www.php.net/manual/en/image.installation.php) for system dependencies and codec configuration.
- [Playwright Docker guidance](https://playwright.dev/docs/docker) for matching dependency/image versions and [Compose 5.6.0 release](https://github.com/docker/compose/releases/tag/v5.6.0) for the checksum-verified local fallback.

The planning runtime's primary-source candidate manifests were also read. Actual lock resolution, platform checks and clean installs/builds determine the recorded versions; documentation alone was not treated as proof.
