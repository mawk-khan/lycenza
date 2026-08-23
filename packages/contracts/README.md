# packages/contracts

Source-of-truth API and event contracts, independent of any single
consumer (web, mobile, third-party integrations, the AI Gateway).

- `openapi/school-os-api.yaml` — OpenAPI 3.1 spec for the versioned
  `/api/v1` surface exposed by `apps/platform`. `packages/shared-types`
  generates TypeScript bindings from this file; it must never be
  hand-edited on the generated side.
- `events/` — JSON Schemas for internal domain events (see
  `docs/architecture/EVENTS.md`). `student-admitted.example.schema.json`
  is illustrative only — no producer or consumer exists yet in Phase 0A.

Changing a contract here is a cross-team change: update the spec/schema
first, regenerate `packages/shared-types`, then implement the Laravel
side and any consumers.
