# Max’s Collectibles

Laravel/Inertia/React storefront and administration for collectables, with inventory, checkout, protected orders and account flows. Local inventory and images are labelled synthetic fixtures; staging and launch acceptance require real owner inputs.

Install Docker with Compose and Node.js 20 or newer, then run from this checkout:

```sh
node tools/dev.mjs up
```

Open **http://127.0.0.1:8000**. PHP, Composer, MySQL, frontend dependencies and builds run in containers. The preview persists between runs and has separate data from destructive tests. Stop it with `node tools/dev.mjs stop`. Fixture administrator: `browser-admin@example.test` / `isolated-password`.

Run the complete build/PHP/browser gate with `node tools/check.mjs fresh`. CI executes the same gate. See [local setup, smoke tests and evidence](docs/operations/local-environment.md), [acceptance](docs/operations/acceptance.md) and [compatibility](docs/operations/compatibility.md). Implementation is not accepted until its required checks pass.

Canonical research, decisions and assessed plans live in the separate [jlib repository](https://github.com/jakeb-k/jlib/tree/main/projects/maxs-collectibles). They are not required to launch this checkout.
