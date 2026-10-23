# Local development and verification

Prerequisites: a running Docker Engine with Docker Compose (Docker Desktop includes both), Git, and Node.js 20 or newer. PHP, Composer, MySQL, npm dependencies and browser binaries run inside containers. Linux x86-64 and macOS ARM use the same checkout-relative commands. The runner prefers `docker compose`, then `docker-compose`; older macOS ARM installations can use its checksum-verified Compose fallback. Images are digest pinned; Debian package installation still requires the package repository and is not an offline OS snapshot.

## Start a browser preview

From a fresh clone:

```sh
git clone https://github.com/jakeb-k/maxs-collectibles.git
cd maxs-collectibles
node tools/dev.mjs up
```

Open **http://127.0.0.1:8000**. The launcher installs locked Composer/npm dependencies, builds assets, starts MySQL, verifies its sentinel, applies additive migrations and seeds visibly labelled synthetic inventory on first launch. It waits for `/up` before printing readiness. No `.env` editing or host PHP/Composer/MySQL is required. First launch needs network access and may take several minutes. Port 8000 must be available; inspect the occupying process rather than deleting unrelated containers.

Browse the home and catalogue, open a listing, add an available item to the cart, and open checkout. Log in at `/login` using `browser-admin@example.test` / `isolated-password` to inspect administration. These are public local fixture credentials. Photography and inventory are synthetic; fake payment/mail providers cannot exercise real external acceptance.

```sh
node tools/dev.mjs stop
node tools/dev.mjs up
node tools/dev.mjs logs
```

The preview remains running after the launcher exits. Stop/restart retains its database, files, session key and cookies. Re-run `up` after code changes to rebuild assets. Development runs under Compose project `maxs-dev`, with its own Docker network, `.cache/development/mysql` and `storage/testing/runs/development-preview`. It never shares the disposable test database or runs `migrate:fresh`. Configuration is generated in ignored `.cache/development/run.env`; no developer paths are embedded. The normal isolated testing guards/fake transports also protect this local preview; it is not a production server. Do not expose its loopback port or use real credentials.

## Execute checks

```sh
node tools/check.mjs fresh
node tools/check.mjs compatibility
node tools/check.mjs php tests/Feature/Auth/AuthenticationTest.php
node tools/check.mjs browser tests/Browser/accessibility.spec.js
node tools/check.mjs build
```

`fresh` is the integrated gate: locked dependency installation, production client asset build, guarded migration/reset, the **complete PHPUnit suite**, then the **complete Playwright suite**. It attempts both suites even when one fails and exits unsuccessfully for any failure. Each suite's structured summary separates passed, failed, errored, risky and skipped counts; warnings/flaky results are also retained. Browser setup is independently reset after PHP tests so PHP failures cannot leave its fixtures unusable. A startup or dependency failure is a runner failure, never a passed suite. CI runs this same command and uploads the retained result logs and screenshots.

`compatibility` additionally checks exact runtimes, manifests/platform, image codecs, dependency audits, negative environment/path/server-sentinel checks, fake transports and cross-request session/reset isolation. Its complete PHPUnit inventory and advisory exits fail the command when unresolved; historical drift is not a success exception. Scoped PHP/browser checks do not establish complete-suite acceptance. Build mode includes real Chromium asset smoke. Optional runtime SSR is disabled in local fixture servers; the documented build compiles the client bundle only; runtime SSR is not built or accepted and requires a rendering service.

Only `maxs-test` owns the disposable test schema (`maxs_test`) and `.cache/mysql`. Its MySQL endpoint is loopback port 13307; browser servers use ephemeral loopback ports and are stopped after each check. Every run has its own app key, cookie, persistent file sessions/cache, views, application files and logs beneath `storage/testing/runs`. PHPUnit can use array drivers for in-process tests. The runner ignores inherited application environment and `.env`, rejects cached Laravel configuration, verifies DB host/name/non-root user and a read-only server sentinel before migrations/reset, and rejects symlink or outside-path mutation. Provider transports reject unscripted external requests. No production data reset is permitted.

A checkout lock refuses concurrent runners because they share a disposable schema and dependency/build outputs. Preview development data remains separate, but stop the preview before dependency/build changes if actively using it. After an interrupted check, inspect processes/containers and establish that its runner is stopped before removing a stale `.cache/check.lock`; never delete another run to fix locking. Evidence is retained under `storage/testing/runs/<run>/result.json`, command logs, PHPUnit JUnit and Playwright JSON/screenshots. Actual failed/risky/skipped results remain visible.

Manual acceptance entry points (`review visual`, `review accessibility-performance`, `staging persistent-deploy`, `staging stripe-boundaries`, `launch launch-inputs`) require a signed evidence JSON argument; see [acceptance](acceptance.md). Missing evidence fails. These commands never deploy, reset staging or charge a payment.
