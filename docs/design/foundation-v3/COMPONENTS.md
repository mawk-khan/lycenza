# Foundation v3 — component classes and patterns (DS-3)

How to style a staff page with the design system. Source of truth:
`apps/platform/resources/css/app.css` (tokens and `@layer components`).
Decision record: ADR 0082 §9.

## Rules

- **Colour only from tokens.** Use `text-ink`, `text-ink-muted`,
  `bg-ground`, `border-line`, `border-control-line`, `bg-accent-strong`,
  `text-on-accent`, `accent-100…900`, `graphite-100…900`, `text-danger`. Do
  not use Tailwind's `slate-*`, `amber-*`, `red-*` (…) palettes in migrated
  files.
- **Secondary text is `text-ink-muted`,** never `graphite-600` or the raw
  `accent` (they fail AA for body-size text).
- **The danger hue is reserved** for validation errors, the "Not saved"
  refusal and destructive buttons (OD-DS-1). Status is never red.
- **Square corners** (`rounded-xs`), never pills.
- **No inline styles** (the CSP refuses them); the one runtime exception is
  the shell's brand handling.

## Classes

| Class | Use |
|---|---|
| `btn` + `btn-primary` | The one main action. The label repeats the verb ("Withdraw placement"), never "Confirm" or "OK". |
| `btn` + `btn-secondary` | Cancel and other actions. |
| `btn` + `btn-ghost` | Low-emphasis actions inside content ("Reload latest", "Clear filters"). |
| `btn` + `btn-danger` | Destructive, final actions only (void, reverse, cancel a Charge). |
| `field-label`, `field-optional`, `field-hint`, `field-error` | Field text. Prefer the `Form/*` components, which already use them. |
| `input` | Text inputs and selects (border `control-line`, 3:1; red border from `aria-invalid`). |
| `checkbox` | Native checkboxes (accent-coloured). |
| `notice` + `notice-success` / `notice-attention` / `notice-error` | In-page messages. Start with a glyph (`✓`, `!`, `✕`, `aria-hidden`) and name the object and outcome. |
| `tag` + `tag-positive/info/attention/negative/neutral` | Small labels. For a record status use `StatusBadge`. |
| `data-table` | Data tables: condensed uppercase header, hairline row rules. |
| `frame` | Cards and page sections: hairline border with "+" corner marks (decorative, CSS only). Transparent: no fill. |

## Patterns (DS-0 §4) → components

| Pattern | Use |
|---|---|
| Status families | `StatusBadge` (glyph + word; six families). |
| Feedback | Server flash → `FlashMessage`; a known refusal → `RefusalMessage` ("Not saved: …"). Never "Something went wrong" for a known refusal. |
| Fresh MFA | `MfaCodeField`, only where the server requires it. |
| Consequential confirmation | `ConfirmDialog` (`tone="danger"` for destructive). Its body answers: what object, what action, what changes, can it be undone, what remains, MFA, reason. |
| Closed reason | `SelectField` with the server's enum labels — never a client-side list, never free text where the domain has a closed list. |
| Possible duplicate | `DuplicateStudentWarning` (warn, show the candidate, never merge). |
| Entity selector | `SearchCombobox` — name **and** number in every option. |
| Empty / filtered empty | `EmptyState` (title + what to do next). |
| Pagination | `Pagination`. |
| Activity timeline | Date, event and closed reason. No actor where the domain forbids one (rules 119, 123). |

## Migrating a page (DS-4)

Run the codemod on the domain's files, then Prettier, then check the result
in the browser:

```bash
python3 -I docs/design/foundation-v3/ds_migrate.py apps/platform/resources/js/Pages/App/<Domain>/*.vue
npx prettier --write <the same files>
```

ADR 0082 §10 records the mapping. Since DS-4h every page is migrated and the
guard covers all of `resources/js`, so a new page must use the classes above
from the start.

## Not yet themed

- `ElevationBanner` keeps its amber security styling, and the Emergency
  communication label keeps the danger hue (OD-DS-4).
- Every page and component is migrated; `DesignTokensTest` refuses a raw
  palette class anywhere under `resources/js` (exemption: the elevation
  banner).
- The page area stays white until every domain is migrated (ADR 0082 §10.3).
