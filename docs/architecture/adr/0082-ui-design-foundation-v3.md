# ADR 0082: UI Design Foundation v3 for the staff web application (DS)

- Status: **Accepted (DS-0, documentation only, 2026-11-03). DS-1
  implemented (2026-11-03, §7). DS-2 implemented (2026-11-04, §8). DS-3
  implemented (2026-11-04, §9). DS-4a to DS-4d implemented (2026-11-04, §10).** No route, capability, role, table or
  migration change. Each further slice needs owner authorization.
- Date: 2026-11-03
- Programme: **DS — Design Foundation v3 adoption** (`docs/design/README.md`).
- Owner decision: adopt the owner's "Lycenza Foundation v3" design for the
  School ERP (2026-11-03). Open owner decisions: §5.
- Evidence: `docs/design/foundation-v3/DS-0-RECONCILIATION.md`.
- Builds on: ADR 0049 §11 (enforced CSP), ADR 0080 §11 (presentation and
  brand, rule 115), rules 2, 87, 98, 101.

## 1. Context

The staff web application (Laravel + Inertia + Vue 3 + Tailwind v4) has no
design system:
- `resources/css/app.css` sets only a font family (which is never loaded);
- about 321 of 329 Vue files carry raw palette classes (`text-slate-500`
  ~1,200 times, `border-slate-300` ~1,180, `bg-slate-900` ~360, `text-red-*`
  ~580);
- a few shared components exist (`Form/*`, `StatusBadge`, `EmptyState`,
  `ConfirmDialog`, `RefusalMessage`, `MfaCodeField`, `Pagination`,
  `NavigationGroupList`), but most styling lives in pages.

The owner supplied an interactive prototype built on the "Industry" design
system: a light ground, one steel accent (`#5980a6`), Barlow Condensed
headings over Barlow, square hairline "blueprint" frames with "+" corner
marks, thin Lucide icons, and 13 UX patterns. The prototype was made without
repository access (its register item D-01).

## 2. Decision

1. **Adopt the look and the patterns; never the prototype's authority.**
   - Personas, capability sets, module lists, reason lists and limits in the
     prototype are not requirements. The capability catalogue,
     `ModuleCatalogue`, the closed reason enums and the domain contracts
     decide. DS-0 §2 records each difference (D-09, D-18, D-20, D-26 are
     stale or wrong in the prototype).
   - Pages keep rendering from the props the server sends. No DS slice adds
     a capability check, a role-name branch, or a client-side list of who may
     do what.
2. **DS is presentation only.** A DS slice never changes a route, a
   controller, a prop's meaning, a capability, a module rule, a Campus scope
   or what data a page shows. A page that looks wrong because of a missing
   server value is reported, not patched in Vue.
3. **Tokens, not copied markup.**
   - The prototype's 640 inline `style` attributes cannot be copied: the
     enforced CSP is `style-src 'self'` (ADR 0049 §11). Every value becomes
     a token in Tailwind v4 `@theme` in `resources/css/app.css`, used through
     classes.
   - Token names are semantic, not Industry-specific: `--color-ground`,
     `--color-surface`, `--color-ink`, `--color-ink-muted`, `--color-line`,
     `--color-control-line`, `--color-accent` with a 100–900 ramp,
     `--color-danger` (§5 OD-DS-1), and `--font-heading` / `--font-body`.
     Ramps and the spacing, radius and shadow scales are taken from
     `reference/industry-styles.css`.
   - Fonts are self-hosted and bundled by Vite; nothing is loaded from Google
     Fonts (CSP `font-src 'self'`, and no staff IP address goes to a third
     party).
4. **The School accent still works (rule 115).**
   - The shell sets `--color-accent` on its root element from the School's
     effective brand through Vue's `:style` binding (CSSOM, allowed by the
     CSP; the shell already sets the brand label this way).
   - The ramp steps are derived in CSS with `color-mix(in oklch, …)` from
     that one value. With no School brand, the Industry steel applies.
   - Any `#RRGGBB` stays accepted. Text never uses the raw accent: it uses
     the deep derived step, so a light School accent cannot produce
     unreadable text. Limiting the accent to swatches (prototype D-21) is a
     product change, not part of DS.
5. **Accessibility corrections are part of the system.** WCAG 2.1 AA wins
   over the reference where they disagree (DS-0 §5):
   - primary button fill `accent-700`, not the accent;
   - secondary text `neutral-700`, never `neutral-600`;
   - form control borders `neutral-600` (3:1 non-text contrast);
   - kicker and meta text at least 12 px in a 4.5:1 colour.
   The existing `eslint-plugin-vuejs-accessibility` gate stays green, and
   focus stays a visible 2 px accent ring.
6. **Blueprint frames are CSS-only and decorative.** Corner marks are drawn
   with pseudo-elements or backgrounds, never four extra elements, and never
   reach the accessibility tree. They frame cards and page sections. Whether
   the primary button also carries them is decided in the DS-3 visual review
   (density of data-heavy pages).
7. **Order of work.**
   - DS-1 tokens and fonts; DS-2 the shell (`AccountLayout`); DS-3 primitives
     and the patterns on the existing components; DS-4+ pages, one domain per
     slice.
   - Each slice runs the normal gates (Pint and Larastan if PHP is touched,
     vue-tsc, ESLint, Prettier, build, the relevant Feature tests) and a DDEV
     visual review.
   - When the last domain is migrated, a guard (ESLint rule or architecture
     test) forbids raw palette utilities (`slate-*`, `red-*`, …) in
     `resources/js`, so the tokens stay the only source of colour.
8. **Pattern corrections** (DS-0 §4):
   - the activity timeline never shows an actor where rules 119 and 123
     forbid one;
   - the "Offline" system state is not adopted (the staff web app is
     online-only);
   - fresh MFA appears only where the server already requires it.

## 3. Not adopted

- The prototype's persona, state and environment pickers, walkthroughs,
  relationship map and register (presentation aids).
- The Mobile tab (Flutter, `apps/mobile`; SDK unverified here).
- The bundled "modernist" design system.
- Duotone photography and School logos or banners: no governed media
  pipeline (rule 115).

## 4. Alternatives rejected

- **Copy the prototype's HTML and inline styles.** Refused by the enforced
  CSP, and every later change would touch hundreds of files again.
- **Add a component kit (PrimeVue, shadcn-vue, Headless UI, …).** A second
  styling system next to Tailwind with no need the existing components
  cannot meet (rule 2). Revisit only if a DS-3 primitive (for example an
  accessible combobox) cannot reasonably be built.
- **Widen the CSP for Google Fonts.** Weakens an enforced policy and sends
  every staff page view to a third party.
- **Restyle pages first, extract tokens later.** Doubles the migration.

## 5. Owner decisions (open)

- **OD-DS-1 — Danger colour.** The Industry palette is single-accent; its
  status families use glyph + word in steel and grey only. Recommended:
  keep that for status, and reserve ONE danger hue (a deep red at ≥ 4.5:1 on
  the ground) for validation errors and destructive buttons only.
  Default until decided: the recommendation.
- **OD-DS-2 — Icons.** Lucide at stroke 1.5, through `lucide-vue-next`
  (ISC licence, lockfile-pinned, named imports so only used icons ship).
  Added in DS-3, the first slice that needs icons. Decorative icons are
  `aria-hidden`; an icon is never the only carrier of meaning.
- **OD-DS-3 — Font source.** Barlow and Barlow Condensed (SIL OFL 1.1) from
  `@fontsource/barlow` and `@fontsource/barlow-condensed`, lockfile-pinned
  and bundled by Vite, rather than hand-vendored files.
- Carried from the prototype register: **D-04** ("Mark all Present"),
  **D-21** (accent swatches), **D-23** (Principal finance visibility). None
  blocks DS-1.

## 6. Consequences

- One place (`app.css` `@theme`) decides colour, type, spacing, radius and
  elevation; a later rebrand is a token change.
- Visual-only diffs are large across DS-4+. Tests asserting markup classes
  may need updating; tests asserting behaviour, props, `data-testid` or
  authorization must not change in a DS slice.
- The three new npm packages (§5) go through the normal lockfile and
  supply-chain rules (rule 87).

## 7. DS-1 implementation (2026-11-03)

Owner authorization: "please proceed" after DS-0 (2026-11-03). OD-DS-1 and
OD-DS-3 were not answered, so their recommended defaults apply and stay
reversible: one reserved danger hue (`--ds-danger` `#b42318`), and fonts from
`@fontsource`. OD-DS-2 (icons) is not needed until DS-3.

1. **Tokens** (`resources/css/app.css`).
   - The raw values live on `:root` as `--ds-*` custom properties.
   - `@theme inline` maps them to Tailwind utilities: `ground`, `surface`,
     `ink`, `ink-muted`, `line`, `control-line`, `graphite-100…900`, `accent`,
     `accent-strong`, `on-accent`, `accent-100…900`, `danger`,
     `danger-tint`, and the `font-sans` / `font-heading` families.
   - The Industry greys are named `graphite`, so Tailwind's own `neutral`
     palette is not redefined.
2. **Fonts.**
   - Barlow 400 (+ italic), 500 and 600, and Barlow Condensed 500 and 600,
     from `@fontsource/barlow` and `@fontsource/barlow-condensed` 5.3.0
     (exact, lockfile-pinned, `devDependencies`, SIL OFL 1.1). Vite bundles
     the files, so the CSP is unchanged.
   - Barlow is the default family everywhere; `h1`–`h4` use Barlow
     Condensed. Font weights `font-bold`/`font-light` are unused, so not
     shipped.
3. **School accent.**
   - `BrandColor::strong()` darkens an accent (sRGB channels scaled toward
     black in 1 % steps, keeping the hue) until it reaches 4.5:1 on the
     ground (`BrandColor::GROUND`); an accent already that strong is kept.
   - `BrandResolver` returns it as `accentStrong`, null when neither the
     School nor its Network supplies an accent. The shared `brand` prop
     carries it.
   - `AccountLayout` sets `--ds-accent` and `--ds-accent-strong` on `<html>`
     through the CSSOM and marks it `data-brand-accent`. The CSS then derives
     the ramp in OKLCH from the one value (`700` = the strong step; `900` at
     least 55 % black). With no accent supplied, the Industry steel ramp
     applies unchanged.
4. **Deliberately not applied yet: the ground.** The page background stays
   as it is. Today's ~1,200 `text-slate-500` uses measure 4.25:1 on
   `#f2f2f3` (4.76:1 on white). The ground arrives with DS-2 (shell) and the
   DS-4 page migrations, each moving secondary text to `text-ink-muted` in
   the same change.
5. **Visible effect of DS-1.** Fonts only. No colour, spacing or layout
   changes; the accent tokens are unused until DS-2.
6. **Tests.**
   - `Tests\Unit\Presentation\BrandColorStrongTest`: any accent's strong
     step is ≥ 4.5:1 on the ground and under white, and is deterministic.
   - `Tests\Feature\Configuration\DesignTokensTest`: no remote font or
     stylesheet, the fontsource imports, exact pins, the CSP's `font-src`
     and `style-src 'self'`, the AA pairs of the token sheet, and the accent
     hook.
   - `PresentationProfileTest`: `accentStrong` in the resolver and the shared
     prop.
   - Visual check: a specimen page rendered from the built stylesheet in
     headless Chromium, default steel and a `#F5D90A` School accent. The
     shared DDEV site was not used: another session's uncommitted work was
     running there.
7. **No engineering rule yet.** A CLAUDE.md rule ("colour comes only from
   the tokens") lands with the guard of §2.7, once pages are migrated; until
   then new pages may still use the existing palette classes.

## 8. DS-2 implementation: the application shell (2026-11-04)

Owner authorization: "please proceed" after DS-1 (2026-11-04).

1. **Account bar** (`AccountLayout`): the deep accent step (`bg-accent-900`)
   with ground-coloured text (>= 12:1); the product name in Barlow Condensed
   capitals; School name and e-mail in `accent-200`; Menu and Log out as
   outlined bar buttons (`accent-400` border, `accent-800` hover); account
   links underlined. Square corners (`rounded-xs`, the design's 2 px).
2. **Navigation** (`AccountLayout` Home link, `NavigationGroupList`): on the
   ground with a hairline divider (`border-line`); group labels in Barlow
   Condensed capitals and section labels in `text-ink-muted` (5.87:1, was
   `text-slate-500`); links `text-ink`, hover `graphite-200`; the active item
   is the strong step with `text-on-accent` (>= 4.5:1 by construction).
3. **Focus:** a 2 px `accent-strong` ring on the ground and white, an
   `accent-300` ring on the dark bar. The skip link gains the ring too. Amber
   focus rings are gone from the shell.
4. **Progress bar:** the Inertia bar, peg and spinner use `--ds-accent-strong`
   instead of `#29d`.
5. **Pages stay on white.** The main content area is `bg-white` beside the
   grey navigation column, because migrated pages arrive only in DS-4 and
   today's `text-slate-500` is 4.25:1 on the ground (§7.4).
6. **Brand (ADR 0080 §11.6 amended).** The ORG.12F accent stripe is replaced:
   a School or Network accent now colours the shell chrome -- bar, active
   item, focus ring and progress bar -- through the DS-1 tokens. The label
   chip stays. Page text and page backgrounds remain never brand-controlled.
   With no accent supplied, the steel applies.
7. **Not changed in DS-2:** the elevation banner keeps its amber warning
   styling (a security signal, revisited with the DS-3 feedback patterns), as
   do the flash and refusal messages (DS-3 "Feedback"). No prop, route or
   behaviour changed; every `data-testid`, landmark and ARIA attribute is
   kept.
8. **Tests.** `DesignTokensTest::the_shell_is_styled_only_through_the_design_tokens`
   (no raw palette utility in the shell files, the bar and active-item
   tokens, one `:style` only, no `#29d`); the existing shell guards
   (`NavigationAccessibilityBaselineTest`, `LogoutAccessTest`,
   `PresentationArchitectureTest`) unchanged and passing.
9. **Visual check.** The real built application booted in headless Chromium
   with a hand-written Inertia page payload (Dashboard, sample navigation):
   steel default, a `#F5D90A` accent with its label chip, a `#1D4E89`
   accent, and a 390 px mobile width. The shared DDEV site was not used.

## 9. DS-3 implementation: components and patterns (2026-11-04)

Owner authorization: "please proceed" after DS-2 (2026-11-04).

1. **Component layer** (`resources/css/app.css`, `@layer components`), built
   only from the tokens: `btn` (+ `primary`, `secondary`, `ghost`, `danger`),
   `field-label`, `field-optional`, `field-hint`, `field-error`, `input`,
   `checkbox`, `notice` (+ `success`, `attention`, `error`), `tag` (+ five
   families), `data-table` (not `table`, which is a Tailwind utility) and
   `frame`. Usage guide: `docs/design/foundation-v3/COMPONENTS.md`.
2. **Blueprint frame** (§2.6): one `::before` pseudo-element draws all four
   "+" marks with eight background gradients -- no extra markup,
   `pointer-events: none`, invisible to assistive technology. The primary
   button carries no marks (density; decided here as §2.6 deferred).
3. **Shared components restyled** onto the classes, with markup, props,
   events, ARIA and `data-testid` unchanged: `Form/TextField`,
   `SelectField`, `CheckboxField`, `ErrorSummary`, `SearchCombobox`
   (defaults and active option), `ConfirmDialog` (framed; `btn-danger` for
   `tone="danger"`), `EmptyState`, `Pagination`, `MfaCodeField`,
   `FlashMessage` (✓ notice), `RefusalMessage` (✕ "Not saved:" notice),
   `DuplicateStudentWarning` (attention notice, `btn` actions).
   - An invalid field is now marked by the `aria-invalid` the fields
     already set, instead of a duplicate class binding.
4. **Status families.** `StatusBadge` maps each of its 32 statuses to one of
   six families -- positive ✓, info ●, progress ◷, attention !, negative ✕,
   neutral – -- shown as a glyph (decorative) + the word. Mono by design
   (OD-DS-1): Cancelled, Failed, Rejected and Blocked are dark steel with a
   cross, not red. Labels are unchanged.
5. **Icons (OD-DS-2): not needed.** The patterns use text glyphs, so no
   icon package is added. Lucide stays the choice if a real icon need
   appears.
6. **OD-DS-4 (new, owner): the elevation banner.** It keeps its amber styling
   -- a security signal for a platform actor inside a School -- rather than
   being folded into the mono palette. Default until decided: unchanged.
7. **Tests.**
   - `DesignTokensTest::the_shared_components_are_styled_only_through_the_design_tokens`:
     no raw palette class in the 13 components; the badge's glyph + word and
     exactly six families; the component classes exist; frame marks
     decorative; `aria-invalid` drives the invalid border.
   - Visual check: a temporary, uncommitted gallery page rendering the real
     components inside the real shell (headless Chromium), including flash
     and refusal messages.

## 10. DS-4: page migration (DS-4a Students, 2026-11-04)

Owner authorization: "please proceed" after DS-3 (2026-11-04); the
recommended first domain (Students) was taken.

1. **Method.** One codemod, `docs/design/foundation-v3/ds_migrate.py`, used
   for every DS-4 slice so the mapping stays identical:
   - **Structure:** an `input`/`select`/`textarea` with the bordered bundle
     becomes `input` (keeping layout classes; `w-auto` where the original
     had no width); a dark button or link becomes `btn btn-primary`, an
     outlined one `btn btn-secondary`, a red one `btn btn-danger`.
   - **Tokens:** every remaining palette class maps by hue family and shade
     (neutral text 700+ -> `ink`, 400-600 -> `ink-muted`; neutral borders ->
     `line`/`control-line`; dark neutral fills -> `accent-strong`; red ->
     `danger`/`danger-tint`; amber -> `accent-100`/`accent-300` + `ink`;
     green -> `graphite`; blue family -> accent), and `rounded`/`-sm`/`-md`/
     `-lg` -> `rounded-xs`.
   - It edits only `class`/`:class` values and class-like script strings;
     never text, props, events or logic. Prettier runs afterwards.
2. **DS-4a scope:** `Pages/App/Students/**` (26 pages),
   `Components/Students/**` and `Components/StudentIdentityFields.vue` -- 29
   files. Status maps stay paired with their words (`stateLabel`); missing
   or rejected documents and blocked bulk-move rows keep the reserved danger
   hue as actionable failures.
3. **The ground stays off pages until DS-4 ends.** Switching background per
   domain would make navigation between migrated and unmigrated pages
   flicker between white and grey. The page area turns to the ground once
   every domain is migrated, in the closing slice, together with the
   all-of-`resources/js` guard (§2.7).
4. **Guard.** `DesignTokensTest::migrated_pages_are_styled_only_through_the_design_tokens`
   lists the migrated folders (`MIGRATED`); each slice appends its own. It
   refuses raw palette classes and rounded corners other than `rounded-xs`
   (or `rounded-full`).
5. **Visual check.** Real page payloads captured by a throwaway (uncommitted)
   feature test -- directory, profile, edit, history, create -- rendered by
   the built application in headless Chromium.
6. **DS-4b: the rest of the Student area (2026-11-04).** `Pages/App/Guardians`,
   `Enrollments`, `Admissions` (incl. Applicants) and `EnrollmentRollovers`,
   plus `Components/GuardianIdentityFields.vue` and
   `Components/RelationshipFlags.vue` -- 27 files, same codemod, no manual
   edits; zero palette classes left. Visual check on nine captured pages
   (Guardian directory and profile, Enrollments, Rollover list and plan,
   Admissions, Applicant list and profile, Application). The admission-fee
   panel (`Components/Fees/AdmissionFeePanel.vue`) moves with Finance.
7. **DS-4c: Finance and Fees (2026-11-04).** `Pages/App/Finance/**`,
   `Components/Fees/**` and `Components/ChargeEndForm.vue` -- 34 files.
   - **Codemod extended:** a rounded status pill (`rounded-full` + `py-0.5` +
     `text-xs`) becomes a square one. Re-running it over DS-4a/b changed
     nothing there (idempotent).
   - **Hand edits, all presentation:** the payment status map uses the
     status families (`tag-positive` Received, `tag-info` Partly refunded /
     Refunded, `tag-attention` Reversed -- the same family `StatusBadge`
     gives "reversed"), so a status is no longer in the danger hue; a
     cancelled Charge line on the fee statement is an attention tag; the
     filter chips are square.
   - The danger hue stays on money that needs action (the overdue total)
     and on refusals.
   - Visual check on 13 captured pages with seeded charges and ledger
     accounts (hub, charges, charge entry, outstanding, payments, ledger
     accounts, journals and journal entry, periods, fee setup, concessions,
     statements, correction requests).
8. **DS-4d: HR, Leave, Payroll and staff self-service (2026-11-04).**
   `Pages/App/HR`, `Leave`, `Payroll`, `StaffAttendance`, `StaffAccounts`,
   `My` and `Components/HR` -- 46 files, codemod only, no hand edits (no
   script status maps, no pills). Visual check on 18 captured pages
   (HR hub, directory, employee profile and overview; Leave configuration,
   entitlements, requests, approvals, accruals; Payroll hub, periods,
   structures, components; staff attendance and monthly; Staff accounts; My
   attendance and payslips).
