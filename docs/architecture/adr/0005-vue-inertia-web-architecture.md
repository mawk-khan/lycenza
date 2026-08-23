# ADR 0005: Vue 3 + TypeScript + Inertia for the Web Client

- Status: Accepted
- Date: 2026-08-22

## Context

The web client needs to be productive to build across many future
modules (SIS, Fees, Attendance, Examinations, LMS, ...), maintain a
single source of truth for authorization/routing with the backend, and
give administrators, teachers, and accountants a responsive UI on
ordinary school-office hardware and variable Indian internet
connectivity.

## Decision

The web client is **Vue 3 + TypeScript**, server-driven via
**Inertia.js** on top of Laravel — no separate standalone SPA build,
no separate REST-consuming frontend deployment for the primary web app.
`apps/platform/resources/js` is the only web frontend for the ERP
console; `packages/shared-types` (ADR 0009) generates TypeScript types
from the OpenAPI contract for any TS code (web, or future admin
tooling) that talks to the versioned `/api/v1` surface directly (mobile
and third-party integrations use that surface; the Inertia-driven
console usually doesn't need to).

## Rationale

- Inertia lets Laravel own routing, authorization, and data-loading per
  page in PHP (where the authoritative domain logic already lives, ADR
  0002) while still rendering a modern reactive Vue SPA-like experience
  — no duplicate routing/authorization logic on a separate frontend
  server, and no hand-maintained REST layer just to feed the *web* UI.
- TypeScript on the Vue side catches an entire class of prop-shape bugs
  between backend and frontend at build time — valuable across a
  product with this many future modules and this much shared component
  surface (tables, forms, filters reused across SIS/Fees/Attendance/...).
- Vue 3's Composition API and single-file components are a good fit for
  a team building many structurally similar CRUD-and-workflow screens.
- This keeps one deployable web artifact (Laravel + its Vite-built
  assets) instead of a separately versioned/deployed SPA that must stay
  compatible with a moving backend API — one less coordination problem
  for a small team.

## Alternatives considered

1. **Standalone SPA (Vue or React) consuming a REST/GraphQL API.**
   Rejected for the primary console: doubles the amount of
   routing/authorization logic that must stay in sync between frontend
   and backend, and requires the backend to already have a complete,
   versioned API surface before any UI work can start — which conflicts
   with Phase 0A's "no business modules yet."  The versioned API (ADR
   0009) still exists and is required for mobile/third-party/public
   developer use, just not as the web console's only way to talk to
   the backend.
2. **Server-rendered Blade + Livewire/Alpine.js.** Rejected: less
   suited to the kind of rich, stateful, data-dense screens a school
   ERP eventually needs (multi-step admissions forms, timetable
   builders, fee ledgers), and a smaller ecosystem fit for a team that
   also builds a Flutter app and wants shared design/interaction
   patterns more naturally expressed in a component framework.
3. **React instead of Vue.** Both are viable; Vue + Inertia has a more
   established, lower-friction integration story with Laravel
   specifically, and no other factor in this decision favored React
   strongly enough to prefer it.

## Consequences

- Every future business module ships its Inertia pages inside
  `apps/platform/resources/js/Pages`, following the module boundary
  convention in `app/Domain/README.md` — controllers stay thin and pass
  typed props to Vue pages (see `app/Http/Controllers/SystemStatusController.php`
  for the Phase 0A shape this follows).
- The web console is tied to Laravel's deployment lifecycle — there is
  no independent "ship the frontend without the backend" path for the
  console (this is accepted; it matches ADR 0001's single-deployable
  philosophy for the ERP core).
- The `/api/v1` surface must still be built out properly (not as an
  afterthought) because mobile (ADR 0006) and third-party integrations
  (ADR 0018) depend on it, even though the web console itself mostly
  doesn't.

## Future extraction/evolution path

If a specific surface later genuinely needs to be a standalone SPA or a
public embeddable widget (e.g. a public admissions-inquiry form embedded
on a school's own website), that surface can be built against the
versioned `/api/v1` contract independently, without requiring the whole
console to move off Inertia.
