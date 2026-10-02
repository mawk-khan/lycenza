# ADR 0064: Financial Period Close and Finance Retention Foundation

- Status: Accepted and implemented (E21.3A, 2026-10-02). **Foundation
  only:** no historical Finance evidence is deleted, and none can be. The
  retention cutover is E21.3A2.
- Date: 2026-10-02
- Programme: **E21 — Retention** (`docs/security/E21-RETENTION-DETERMINATION.md`,
  `docs/security/E21-CLOSURE-AUDIT.md` §6, the D8 brief).
- Baseline: `origin/main` `e4096bb` (E21.2G, regression checkpoint).
- Builds on, and does not rewrite:
  - ADR 0030 (double-entry ledger, append-only journal);
  - ADR 0031 (Payments; its implementation amendment for manual payments);
  - ADR 0034 (Payroll posting and reversal);
  - ADR 0062 (Fees, concessions, receipts and the receipt series key);
  - ADR 0021/0022 (RLS, the runtime role), ADR 0019 (UUIDv7 ids);
  - ADR 0058 (production gates; E21 stays open).
- Related: CLAUDE.md rules 4, 6, 9–11, 17, 18, 24, 28, 50–54, 86.

Paths under `app/`, `database/`, `routes/`, `resources/` and `tests/` are
relative to `apps/platform/`.

## 1. Context and decision

Before E21.3A every Finance balance was derived from the entire posting
history (account balances = Σ journal lines; charge outstanding =
amount − allocations − live adjustments; Student dues = Σ outstanding).
Nothing could be retained for a fixed period, because deleting a closed
year's detail would silently change today's balances (E21.2G: D8 BLOCKED
BY ARCHITECTURE).

**Decision.** Finance gets an authoritative financial period per School and
financial year, an immutable period identity on every journal entry, a
transactional, audited, irreversible close, and immutable carried-forward
baselines. Every current balance can then be computed as *latest closed
baseline + later detail*, and a dual-read check proves that this equals the
all-history reading, exactly, before a close may commit.

This is the foundation a retention cutover needs. It is not the cutover:
reads still use all history, and nothing is deleted (§8).

## 2. The period model (`financial_periods`)

- One row per School financial year:
  - `period_key`: the receipt series key, `payments_fy_series_key` ("2026-27");
  - `starts_on` / `ends_on`: from the School's start month (`fee_settings`,
    default April), via the one boundary rule `finance_period_bounds()`;
  - `status`: `open` or `closed`;
  - `closed_at`, `closed_by_user_id`, `close_fingerprint`.
- **Two states only.** There is no `closing` state: a close is one
  transaction, so an observer sees either `open` or `closed`. A
  `reopened` state does not exist (§4.5).
- **Database-enforced** (`financial_periods_guard`, CHECK
  `financial_periods_shape_check`):
  - created open, with boundaries that match the start month in force;
  - never overlapping, unique per `(school_id, starts_on)` and
    `(school_id, period_key)`;
  - boundaries, key and School immutable;
  - `closed` ⇔ `closed_at` ⇔ fingerprint; a closed row never changes;
  - no period may be created before a closed one, so closed history never
    gains a period in a gap;
  - no runtime DELETE; RLS forced (rule 18).
- Rows are created on demand by the application with a UUIDv7 id
  (`FinancialPeriod::ensureContaining`, ADR 0019), never by a database
  default. Creating the identical period twice (two first postings racing)
  is a silent no-op under the School's period advisory lock.

## 3. Posting identity and backfill

- `journal_entries.financial_period_id` (composite FK to
  `financial_periods (id, school_id)`, RESTRICT).
- **Every new entry** gets its period in the database
  (`trg_journal_entries_assign_financial_period`, BEFORE INSERT), from its
  **School-local posting date**. That covers every posting path,
  including raw SQL. The trigger:
  - resolves the containing period; refuses `no_financial_period` if none
    exists;
  - holds the period `FOR SHARE` until the posting commits;
  - refuses a date outside the period, or another School's period;
  - refuses a closed period (SQLSTATE 55000, `financial_period_closed`).
- `LedgerService` dates postings with the **application clock**
  (`posted_at = now()`), so the period and every School-local "today"
  agree. `JournalEntry`'s `creating` hook ensures the period exists first.
  The service maps the closed-period refusal to `FinancialPeriodClosedException`
  (409 `FINANCIAL_PERIOD_CLOSED`).
- **Backfill** for entries posted before E21.3A:
  `platform:finance-periods-backfill {--school=} {--dry-run} {--batch=}`
  (`FinancialPeriodBackfillService`).
  - Deterministic; oldest first, batched, rerunnable. It only touches
    entries whose period is still NULL.
  - A dry run writes nothing, not even a period.
  - Outcomes, counts only: `mapped`, `ambiguous`, `blocked`, `error`.
  - **Ambiguous fails closed.** An entry stays unmapped and retained when
    an audited start-month change happened at or after its posting, or
    when its own posting audit event is no longer retained (the audit
    history from its posting on cannot be shown complete).
  - `blocked`: the entry would fall in or before a closed period. That
    cannot happen while a close refuses unmapped entries; it is counted,
    never forced.
  - The only write is the narrow SECURITY DEFINER
    `finance_assign_journal_entry_period`:
    - tenant-tied (`app.current_school_id`);
    - NULL → the containing **open** period only;
    - pinned `search_path`; EXECUTE for `school_os_app` only.

    `journal_entries` stays append-only for the runtime role.
    `DatabaseRoleVerifier` checks the function (`finance_period_functions_narrow`).
  - Audited once per School pass that did something
    (`financial_period.backfill_applied`, counts only).

## 4. The close (`FinancialPeriodCloseService`)

### 4.1 Transaction and the one lock order

The close runs as one READ COMMITTED transaction:

1. Ensure the current period exists, so no posting during the close needs
   to create one.
2. Lock **every open period of the School `FOR UPDATE`, ordered by
   `starts_on`, then id.** This is the only lock the close takes.
3. Validate (§4.2).
4. Write the baselines (§5) and mark the period closed with its
   fingerprint.
5. Dual-read verify the whole School (§6). Any difference throws, which
   rolls back everything.
6. Audit `financial_period.closed` (period key, dates, entry count,
   baseline count, notices, fingerprint; never amounts).

Every posting holds its period `FOR SHARE` (§3). So postings, reversals,
manual payments, payroll postings, charge and adjustment cancellations, and
the backfill all serialize with a close:
- whichever side holds first, the other waits;
- both complete;
- the posting lands in the open period.

No path takes these locks in another order, so there is no deadlock. A
start-month change only *tries* the period-creation lock (§10). Real
two-process races prove each pairing (`FinancialPeriodConcurrencyTest`).

### 4.2 Deterministic validation

Blockers are machine codes. Nothing is written when any blocker stands:

- `period_already_closed` / `period_not_open`;
- `period_not_ended`: `ends_on` must be before the School-local today;
- `earlier_period_open`: years close in order;
- `unmapped_journal_entries`: any entry without a period dated on or before
  `ends_on` (not backfilled, or ambiguous);
- `<participant>.<code>`: each participant's own blockers (none today);
- `confirmation_mismatch`: the typed period key must match.

Notices are shown but do not block:
- `no_postings`;
- `charges.receipt_series_differs_from_period` (§9).

### 4.3 What the brief asked for, and how this design meets it

The D8 brief (E21-CLOSURE-AUDIT §6) listed more validations. Here is how
each is met:

- **"Balanced":** `journal_entries_balanced_check` already guarantees every
  entry balances, so every period does too.
- **"No draft or unposted work":** a posting is always dated now, never
  into an ended period. Unfinished work (a draft payroll run, an
  unexecuted fee run) posts into the open period when it is finished. It
  cannot change a closed period, so it does not block.
- **"Receipt series sealed":** receipts are never renumbered or reissued;
  the series key follows the settlement date (§9).
- **"Charges settled or carried":** they are carried, per charge (§5).

### 4.4 Authorization and evidence

- `finance.periods.manage`: new, School-scoped, held by `school_admin` by
  default, never by Principal or Teacher. It is checked in the Application
  service (rule 6).
- Viewing and evaluation need `finance.ledger.view`.
- The browser action also needs the typed period key and a **fresh MFA
  code** (`FreshMfaRequirement`). The order is: capability, then MFA,
  then the service. A refused person never spends a code.
- There is no role-name check (rule 24).

### 4.5 No reopen; corrections

There is no reopen action, state or capability. The database refuses any
change to a closed row. A correction to closed history is a **new posting
in the open period, linked to its original**:
- a journal reversal (`reversal_of_journal_entry_id`);
- a charge cancellation (`cancellation_journal_entry_id`);
- an adjustment void;
- a payroll reversal.

The original keeps its closed period. These later facts are exactly the
"later detail" the reconstruction adds (§5).

## 5. Carried-forward state (the baselines)

Both baseline tables are append-only (runtime UPDATE/DELETE revoked), RLS
forced, written only while their period is still open
(`financial_period_baselines_open_only`), and so immutable after the close.

**Ledger accounts** (`financial_period_account_balances`, owned by Finance):
- per account and currency, the **cumulative** debit and credit totals
  through the closed period: previous baseline + that period's lines;
- current balance = latest baseline + lines of later periods. Entries not
  yet mapped count as later, since a close refuses them inside its period.

**Charges** (`financial_period_charge_states`, owned by Payments, which
owns the outstanding computation):
- per charge assessed through the period, its state **as of** the period:
  amount, allocations through it, live adjustments as of it, and
  cancellation. "Through P" means the fact's journal entry is in P or
  earlier:
  - an allocation, by its Payment's settlement entry;
  - an adjustment, by its posting entry and void entry;
  - a cancellation, by its cancellation entry.
- current state = latest state + later facts:
  - later allocations;
  - minus adjustments posted through P but voided later;
  - plus later adjustments still live;
  - a later cancellation.
- Student dues = Σ charge outstanding. There is no separate Student
  ledger, so this is exact by construction and verified per Student (§6).
- Payroll needs no baseline of its own: payroll results post to the ledger,
  so its totals are ledger-account totals (§9).

**Module boundaries (rule 4).** Finance defines
`FinancialPeriodCloseParticipant` and never references Fees or Payments
(`finance_never_depends_on_fees` still holds). Payments implements
`ChargePeriodStateParticipant`. It reads charges and adjustments only
through Fees' `ChargeService::ledgerFacts()` / `adjustmentLedgerFacts()`,
and periods only through Finance's `JournalPeriodIndex`. The participant is
container-tagged in `AppServiceProvider`. Dependency directions are
unchanged: Payments → Fees → Finance.

**Fingerprint.** SHA-256 of the period key and each participant's
canonical digest of what it wrote (`ledger` + `charges`).

## 6. Dual-read verification (`FinancialBalanceVerifier`)

- OLD: the all-history reading every Finance read uses today, including
  `ChargeOutstandingReader` for charge outstanding.
- NEW: latest closed baseline + later detail.
- They must be **exactly equal** (bcmath / PostgreSQL numeric):
  - for every ledger account and currency;
  - for every charge: cancelled, allocated, adjusted, outstanding;
  - for every Student's dues.

  Missing or orphan charge states are mismatches too.
- Inside the close: any mismatch rolls the close back
  (`FinancialPeriodVerificationFailedException`). That is a defect to
  investigate, never something to override.
- Read-only operator check: `platform:finance-balances-verify {--school=}`.
  It runs one REPEATABLE READ snapshot, prints ids and codes only, and
  exits non-zero on any mismatch.

## 7. UI

Finance → Financial periods (`/app/finance/periods`,
`FinancialPeriodController`, `resources/js/Pages/App/Finance/Periods/Index.vue`):
- for each year: key, range, status, entry count, blockers and notices,
  and closed-at / closed-by;
- the count of unmapped older entries;
- a close form: typed key, MFA code, "permanent" warning.

There is no reopen and no manual balance entry: baselines are always
computed from the ledger.

## 8. Retention boundary

- **No deletion.** E21.3A adds:
  - no Finance prune command;
  - no `retention_expire_*` function for Finance;
  - no DELETE on any ledger, period or baseline table.

  `FinanceRetentionGuardTest` still forbids retention code naming a
  ledger-bound table (the period and baseline tables included). It now also
  proves the period code deletes nothing and that no
  `finance-retention-prune` exists.
- `FinanceRetentionReadiness` (read-only, never ready). Blockers:
  - `unmapped_journal_entries`;
  - `no_closed_financial_period`;
  - `balance_verification_failed`;
  - the permanent `retention_cutover_not_implemented`.
- Tenant closure readiness:
  - the D8 gate is now `d8_finance_retention_cutover_pending`, replacing
    `d8_financial_year_close`;
  - `d8_finance_period_mapping_incomplete` is added while any entry lacks
    a period;
  - the catalog's `finance_ledger` category includes the three new tables
    and stays a technical blocker.
- **E21.3A2 — Finance Retention Cutover & Historical Expiry** is the next
  checkpoint. It must:
  - switch every Finance read model to baseline + retained detail;
  - define what a closed period's detail may be deleted against (8 years
    after the year closes, the adopted D8 period; not claimed to be legally
    mandated);
  - add narrow, floored, held, dry-run expiry with the verifier as a
    precondition;
  - rewrite the guard contract.

  It needs ratification first (E21 stays OPEN).

## 9. Receipts, provider events, payroll

- **Receipts are unchanged.** The series stays keyed by the settlement
  date's financial year, gap-free and never renumbered, so a payment
  received in March and recorded in April gets the March year's next
  number while its ledger posting is in April's open period. The close
  surfaces this as the notice `charges.receipt_series_differs_from_period`.
  The period key and series key use the same function, so they agree
  whenever the dates do.
- **Provider events are untouched.** `payment_provider_events` keep their
  idempotency contract; a settlement posts like any other posting.
- **Payroll reuses Finance periods.** Payroll periods (pay months) stay
  what they are; the financial year of a payroll posting or reversal is its
  journal entry's period. A posting dated in a closed year is refused and
  leaves the run approved.

## 10. Start month

- The start month may not change once **any** period exists for the School
  (`trg_fee_settings_lock_start_month`), i.e. once anything is posted.
  This tightens ADR 0062's "until the first receipt", because periods must
  never be reinterpreted.
- A month change and a School's first period creation serialize on the
  period advisory lock:
  - a change committed first shapes the first period (the waiting insert
    re-reads the month once);
  - a change during a first posting is refused at once. It *tries* the
    lock rather than waiting, because the settings path already holds the
    Fees settings lock that the posting's receipt takes later.
- `FeeSettingsService` reports it as `ReceiptNumberingLockedException`
  ("…once anything has been posted to the ledger").

## 11. Alternatives rejected

- **A `closing` state or a background close job:** unnecessary. One
  transaction is atomic, and a job would need its own lock and recovery.
- **Reopen:** it would make baselines mutable and defeat retention.
  Corrections post forward instead.
- **Opening-balance journal entries:** they would double-count in every
  existing all-history read. Separate baselines leave today's reads
  untouched until E21.3A2 switches them deliberately.
- **A Student balance table:** dues are a pure sum over charges, so a third
  copy could only drift.
- **Period creation inside the trigger (`gen_random_uuid`):** it violates
  ADR 0019 (UUIDv7, generated in PHP).
- **Locking charges in the close:** unnecessary for correctness and a new
  lock-order risk. Period locks alone serialize every posting.

## 12. Consequences and risks

- Every posting now takes a `FOR SHARE` row lock on its period. That is
  cheap, but a close briefly pauses a School's postings. A close is rare
  and fast (one School).
- Tests that changed the start month after posting were updated to set it
  first. The rule is stricter by design.
- The backfill on live ledgers is batched and audited, and ambiguous rows
  fail closed. Schools with an audited start-month change after early
  postings need an explicit later decision (E21.3A2 or a dedicated
  resolution).

## 13. Rollback

- `2026_11_09_090100`'s `down()` refuses while any baseline exists.
- `2026_11_09_090000`'s `down()` refuses while any period is closed.

Both are deliberate: closed periods and baselines are authoritative
Finance evidence. Before any close, both migrations roll back and reapply
cleanly (verified on DDEV with a schema-dump comparison).
