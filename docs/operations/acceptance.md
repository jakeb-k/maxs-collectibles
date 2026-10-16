# Procedure evidence

The executable procedure catalogue is `procedures.json`; evidence shape is `evidence.schema.json`. Supported IDs exactly match K16/K17/K19/K20/K21. These are external/manual gates, not automated passes.

Record `mode`, `procedure`, `signedBy` (named accountable reviewer), ISO `signedAt`, and one passed result for every named step. Each result needs `id`, actual `command` or manual action, `output` path and nonempty `artifacts` paths under this workspace. Files must exist with nonempty contents. The signature is a named human attestation, not a cryptographic signature; reviewer authenticity and artifact truth still require independent review. Missing, failed or not-run steps fail validation.

Staging evidence additionally identifies an explicit HTTPS `target`, `providerMode: "test"`, and `readOnly: true` for the runner's evidence validation. Actual procedure actions are performed and recorded by an authorized staging operator, with no staging resets. Launch validation is read-only and requires an explicit HTTPS target. Record unresolved owner/content/hosting/commercial inputs as pending; do not create passed records for them.

Example shape (replace paths with actual artifacts; this illustrative object is incomplete and fails validation):

```json
{"mode":"review","procedure":"visual","signedBy":"reviewer name","signedAt":"2026-10-16T10:00:00Z","results":[{"id":"design","status":"passed","command":"Reviewed desktop/mobile screenshots against accepted direction","output":"storage/testing/review/design.txt","artifacts":["storage/testing/review/desktop.png"]}]}
```

Visual: review desktop/mobile design and source comparison, then branding/copy/email identity. Accessibility-performance: record keyboard, focus, zoom/reflow and measured contrast plus representative image/performance measurements. Persistent-deploy: prove records/uploads survive, workers/scheduler run, and isolated restore/code rollback work. Stripe-boundaries: real provider test-mode webhook/no-return, contention/replay/pending expiry, conditional Zip and mail recovery. Launch-inputs: review real content/commercial approvals and release-specific operational evidence. The later implementation tasks supply the detailed release and recovery tools and actual acceptance artifacts.

## T08 slice evidence boundary

The home/catalogue/item/checkout study is implemented with safe server catalogue/quote DTOs. Its test media consists of labelled code-native illustration fixtures, not Max’s photography or real stock. Do not interpret synthetic screenshots as owner content approval. The isolated runner seeds these fixtures only for the storefront spec or full browser suite; media is served only from the verified current run.

The orchestration run `maxs-implementation-20261016/T08/task-result.json` points to desktop/mobile screenshots, the agent design decision, source identity scan and exact check results. Independent agent design inspection is required before extending the visual treatment. K16 still requires the named human attestation described above, and actual photographs must receive a subsequent content/visual review. No human signature has been created by the implementation agent.
