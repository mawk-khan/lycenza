# Design — Lycenza staff web UI

This folder holds the visual design the staff web application
(`apps/platform`, Inertia + Vue 3 + Tailwind v4) is being moved onto, and
the reconciliation between that design and the repository.

- Decision record: **ADR 0082** (`docs/architecture/adr/0082-ui-design-foundation-v3.md`).
- Reconciliation (DS-0): `foundation-v3/DS-0-RECONCILIATION.md`.
- How to style a page (DS-3): `foundation-v3/COMPONENTS.md`.
- Design-system reference, verbatim from the owner's export:
  `foundation-v3/reference/industry-styles.css`,
  `foundation-v3/reference/industry-readme.md`.

## Source

"Lycenza Foundation v3", an interactive prototype the owner made in Claude
Design. It was supplied on 2026-11-03 as `Lycenza School ERP.zip`
(SHA-256 `3c1bc915af8f01046873f4028087c860b0ed7aad191dd1769d7fcfcdb6d094d0`).
The 740 KB prototype bundle is **not** committed. It is a presentation tool
(persona and state pickers, walkthroughs), not product. Only the design
system it is built on is kept here, as a reference.

| Reference file | SHA-256 of the exported original |
|---|---|
| `industry-styles.css` | `5fee433ce29648f605f013e35330e56f4a17961cd9fcdebe07e53a8f714dab08` |
| `industry-readme.md` | `38d9c4f77b7f1ff095e07b99bd9fdc69e9267aab539f9b7a95b3fd2eeb07d7ba` |

The copies are byte-identical except that a trailing blank line was removed
from `industry-styles.css` for the repository whitespace gate.

The reference files are documentation. Nothing builds, links or serves them.
The `@import` of Google Fonts at the top of `industry-styles.css` is part of
the verbatim copy and is **not** how the application loads fonts (ADR 0082 §2.3).

## What the design is, and is not

- **It is** the look, the component vocabulary and the UX patterns of the
  staff web application: the "Industry" style (steel accent, Barlow
  Condensed over Barlow, square hairline "blueprint" frames) and 13
  interaction patterns (refusal reasons, fresh MFA, closed reasons,
  consequential confirmation, maker/checker, stale write, …).
- **It is not** a source of authority. The prototype was made without
  repository access (its own register item D-01). Its persona capabilities,
  its module list and several of its rules are invented or stale. The
  repository's capability catalogue, module catalogue, closed reason enums
  and domain contracts always win; `DS-0-RECONCILIATION.md` §2 records every
  difference.

## Programme

| Slice | Scope | Status |
|---|---|---|
| **DS-0** | Reconciliation, ADR 0082, reference copy. Docs only. | Published (`e418a67e`) |
| **DS-1** | Tokens in Tailwind `@theme`, self-hosted Barlow, runtime School accent. | Published (`27547ab2`) |
| **DS-2** | Application shell (`AccountLayout`): top bar, navigation, focus. | Published (`7fc9f197`) |
| **DS-3** | Primitives and the 13 patterns on the existing components. | Published (`9757f44b`; `foundation-v3/COMPONENTS.md`) |
| **DS-4+** | Page migration, one domain per slice (codemod `foundation-v3/ds_migrate.py`). | DS-4a Students published (`8b462ae8`); DS-4b Guardians, Enrollments, Admissions, Rollovers published (`ec965384`); DS-4c Finance & Fees implemented (ADR 0082 §10.7) |

Every slice needs owner authorization; none starts automatically.
