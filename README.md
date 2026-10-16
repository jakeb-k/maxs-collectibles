# Max’s Collectibles application workspace

Core storefront, admin, checkout, protected order, account and communication flows are implemented. Integrated verification, final independent acceptance and real staging/launch acceptance remain pending. Canonical research, decisions and the assessed plan live in [jlib Projects](../jlib/projects/maxs-collectibles/MAP.md); see [current capabilities and limits](../jlib/projects/maxs-collectibles/implementation/capabilities.md).

[Open the implementation plan](../jlib/projects/maxs-collectibles/planning/plan-reader.html) · [Research reader](../jlib/projects/maxs-collectibles/research/research-reader.html)

Use the guarded local runner: `node tools/check.mjs compatibility`, `node tools/check.mjs browser tests/Browser/commerce.spec.js`, or `node tools/check.mjs build`. The complete integrated gate is `node tools/check.mjs fresh`. Read [local environment](docs/operations/local-environment.md) and [compatibility evidence](docs/operations/compatibility.md) before running checks. Local fixtures/providers are synthetic; production credentials/data and deployment are outside these local procedures.
