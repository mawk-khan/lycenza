# Domain Modules (Convention)

This directory is the home for bounded-context modules in the modular monolith,
per `docs/architecture/adr/0002-laravel-authoritative-erp.md` and
`docs/architecture/DOMAIN-MAP.md`.

No business modules exist yet — this is Phase 0A (foundation only). When a
future phase implements a module (e.g. `Students`, `Fees`, `Admissions`), it
must follow this internal layout:

*(2026-10-14, SWC.1 drift correction, ADR 0073 §12.2: the sentence above is
historical. Business modules are implemented here — one directory per
bounded context (`Students`, `Admissions`, `Fees`, `Payroll`, `HR`, `Leave`,
`Attendance`, …), mapped in `docs/architecture/DOMAIN-MAP.md`. The layout
below still applies to every module.)*

```
app/Domain/<ModuleName>/
    Domain/            # Entities, value objects, domain events, invariants — no framework deps
    Application/        # Use cases / application services, orchestrate domain + infra
    Infrastructure/      # Eloquent models, repositories, external adapters
    Http/                # Controllers, Form Requests, API resources (thin — delegate to Application)
```

Rules (enforced in code review, see root `CLAUDE.md`):

- Controllers stay thin: validate input, call an Application service, return a response.
- Cross-module communication happens through domain events or explicitly exposed
  Application-layer contracts — never by one module reaching into another
  module's Eloquent models or tables directly.
- A module may depend "downward" per `docs/architecture/DOMAIN-MAP.md`'s
  dependency direction; bidirectional module coupling is not allowed.
- Every module that touches tenant-scoped data must be tenant-aware per
  `docs/architecture/TENANCY.md`.
