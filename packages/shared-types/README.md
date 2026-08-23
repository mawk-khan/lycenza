# packages/shared-types

TypeScript types generated from `packages/contracts/openapi/school-os-api.yaml`.

```bash
npm install
npm run generate   # writes src/generated/school-os-api.ts
npm run type-check
```

`src/generated/` is committed so consumers (currently: none wired up yet —
this is the Phase 0A plumbing proof) don't need the generator installed
just to read types. Never hand-edit files under `src/generated/` — edit
the OpenAPI spec and regenerate instead.
