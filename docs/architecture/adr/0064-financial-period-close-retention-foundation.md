# ADR 0064: Financial Period Close and Finance Retention Foundation

- Status: Accepted and implemented. E21.3A (2026-10-02) built the
  foundation. **E21.3A2 (2026-10-02) amends it with the read cutover and
  D8 historical expiry (§14–§24 below). D8 is IMPLEMENTED; its period
  remains project-adopted, pending final legal/compliance ratification.**
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

---

# Amendment — E21.3A2: Finance retention cutover and historical expiry (2026-10-02)

Baseline: `origin/main` `d7bd7e7` (E21.3A, regression checkpoint).

## 14. The read cutover

Before this amendment every production read derived from the whole history.
Now the authoritative reads are:

- **Account balances:** `LedgerBalanceReader` (Finance). Balance = the
  latest closed period's cumulative baseline + the lines of every later or
  unmapped entry.
  - Finding: before E21.3A2 no production code summed account balances at
    all. This reader is the only one.
- **Charge state:** `Payments\Application\Charges\ChargeStateReader`.
  State = the carried state in the latest closed period + later facts, by
  journal entry:
  - later allocations;
  - minus adjustments posted through the period and voided later;
  - plus later live adjustments;
  - a later cancellation.

  It covers outstanding, allocated, adjusted and cancelled. Every consumer
  goes through it:
  - `ChargeOutstandingReader` (`forCharges`, `locked`), and through it late
    fees;
  - the settlement capacity pre-check (`SettledPaymentRecorder`);
  - the manual-payment form;
  - the Student fee statement.
- **Student dues:** the sum of `ChargeStateReader` outstanding. No Student
  balance table exists.
- **Payroll totals:** ledger-account totals, so `LedgerBalanceReader`. No
  payroll carry-forward ledger exists.

**The old computations are verification only.**
- `AllHistoryChargeReader` is used solely by the balance verifier's charge
  participant.
- The all-history account sum lives only in `LedgerPeriodBalances::mismatches`.
- `FinanceReadCutoverGuardTest` pins all of this.

Once detail has been expired, the verifier's all-history side starts from
the **expiry anchor**: the latest period with an expiry record. It reads
that period's baseline plus every later line.

Per-charge integrity checks (the allocation-capacity trigger, cancellation
guards) still read the charge's own facts. That is exact, because a
retained charge always keeps all of its facts (§16).

## 15. D8 eligibility

- **Trigger:** the period's close. A period's detail is age-eligible exactly
  when `closed_at <= now − N calendar years`, with N =
  `FINANCE_RETENTION_YEARS`, at least 8.
- **Arithmetic:** no month overflow, identical to the database floor
  `now() − interval '8 years'`:
  - closed 2028-02-29 12:00 becomes eligible at 2036-02-29 12:00, the
    boundary inclusive;
  - closed 2092-02-29 becomes eligible at 2100-03-01 (2100 has no
    29 February).
- **Never eligible:** an open period, an unmapped entry, a period without
  its baseline, a held School.
- **Not the trigger:** posting, creation and receipt dates.
- **Backfilled historical years:** E21.3A closes them at the cutover, so
  `closed_at` is the cutover date. Their retention clock starts then. Legacy
  detail is therefore kept longer than 8 years after its business year. That
  is deliberate and safer than inventing a close date.
- **Horizon:** the latest closed period up to which every period, oldest
  first, is eligible (`FinanceRetentionEligibility::horizon`).

## 16. Retention units

| Resource | Treatment |
|---|---|
| Charge + its fee assessment, assessment run items, adjustments, targeted concessions, carried states | Expires with its **cluster**: charges sharing a Payment, a late-fee charge with its source. Only when **every** entry of the cluster is at or before the horizon and every charge is cancelled or owes exactly 0.00. An owing charge stays (`open_charge`) until it is settled and that settlement has aged past a later close. |
| Payment, allocations, receipt, provider event | With the cluster that holds all its allocations. A provider event goes only when no remaining Payment references it. Unlinked provider events stay. |
| Late-fee assessment, run items | With the cluster holding both the source and the late-fee charge. |
| Standalone journal entry (manual posting) | With its reversal group (an entry and every reversal of it). All of the group must be at or before the horizon; a reversal in a later period keeps the whole group. |
| Journal entry referenced by a module | Only with that module's unit, never on its own. |
| Payroll postings and their journal entries | **Never through D8.** Payroll runs, results and postings are D9 evidence and reference their entries. Reported as retained (`payroll_evidence_retained`). |
| Canteen-linked charge | Retained; the database refuses a charge a canteen order references. |
| Receipt counters | Never touched: no receipt number is reused, and every retained receipt keeps its series. |
| Financial periods, account baselines, expiry lineage | Kept for the School's lifetime. They carry every later balance. |
| Fee and late-fee run headers, standing concessions | Kept (configuration and decisions); they hold no per-Student amounts that affect a balance. |

## 17. The privileged path

The runtime role still has no DELETE on any ledger (`DatabaseRoleVerifier`).
Expiry runs through one fixed-purpose SECURITY DEFINER function,
`retention_expire_finance_unit(school, charge ids, entry ids, record id, dry run)`.

**What it verifies itself:**
- the School is the current tenant;
- the unit is closed under every reference: all allocations of its
  Payments, both late-fee sides, all reversal links, and no outside charge,
  adjustment, Payment, payroll posting or canteen order;
- every charge is settled;
- every entry is in a period that is closed with `closed_at <= now − 8 years`;
- the latest closed period has its account baseline.

**Locks:** the School's period-maintenance lock first (the close takes it
too), then the charges, then the entries.

**What it records:** one `financial_period_expiries` row per unit: the
latest period touched, when, and counts.

**How deletes get through:** three cascade-only guards (receipts, fee and
late-fee run items) also open when two conditions hold together:
- a transaction-local flag that only this function sets;
- the owner's privileges, which the runtime role never has.

**Access:** EXECUTE for the runtime role only. Only `RetentionExpiry::financeUnit`
calls it, and only `FinanceRetentionService` calls that
(`FinanceRetentionGuardTest`).

## 18. Orchestration and accounting proof

`platform:finance-retention-prune [--school] [--dry-run]` runs daily at 05:10.

**Fail-closed:**
- deletion needs `FINANCE_RETENTION_ENABLED=true` and
  `FINANCE_RETENTION_YEARS` of at least 8;
- a dry run needs only the period;
- invalid configuration exits non-zero.

**Per School (`FinanceRetentionService`):**
1. Holds: a held School only counts.
2. Compute the horizon.
3. Run the dual-read check **before** anything is deleted; on failure,
   delete nothing.
4. Expire units in deterministic order, up to a batch limit. Each unit is
   **one REPEATABLE READ transaction**:
   - exact readings (every account balance, each affected charge's
     outstanding, each affected Student's dues, every receipt series' next
     number);
   - the database expiry;
   - the same readings again.

   Any difference rolls the unit back.
5. Run the dual-read check again.

Output, logs and metrics are counts only. Real two-process races cover
expiry against posting, reversal (both orders), adjustment void (both
orders), payment and close (both orders).

## 19. Product behaviour after expiry

- An expired journal entry, Payment or receipt is an ordinary not-found
  (404) in the browser and the API. No "deleted by retention" message
  exists, so there is no oracle.
- Lists keep working.
- The fee statement carries `detailExpiredThrough` and explains that earlier
  years' settled charges are no longer listed. Its outstanding total is
  unchanged.
- No transaction is fabricated from a baseline.
- No Analytics report or export reads Finance detail.

## 20. Readiness

`FinanceRetentionReadiness` reports, per period:
- `period_not_closed`;
- `period_too_young`;
- `period_mapping_incomplete`;
- `baseline_missing`;
- `legal_hold`;
- `retention_cutover_not_enabled`;
- `dependency_blocked`;
- `ready`.

At School level it reports:
- `unmapped_journal_entries`;
- `no_closed_financial_period`;
- `balance_verification_failed`;
- `retention_cutover_not_enabled`;
- `legal_hold`.

Tenant-closure readiness has these Finance gates:
- `d8_finance_period_mapping_incomplete`;
- `d8_finance_periods_unclosed`;
- `d8_finance_verification_failed`;
- `d8_finance_retention_not_enabled`;
- `d8_d9_payroll_ledger_retained` (the recorded intersection, §21).

The catalog's `finance_ledger` category is now **adopted**.
`finance_period_evidence` is tenant-lifetime.

## 21. The D8 × D9 intersection (recorded, not weakened)

Payroll-linked journal entries stay while D9 payroll evidence references
them. No Payroll D9 mechanism expires payroll runs, results or postings
(E21.2E kept them as ledger evidence). Until one exists:
- payroll ledger detail is retained;
- paid Employees stay retained (E21.2E);
- the catalog keeps `payroll_ledger` as the one technical blocker.

Payroll totals are exact either way. They are account totals, carried in
the baselines.

A Payroll D9 result-expiry mechanism is not in E21.3B–E21.3E. It needs its
own checkpoint.

## 22. Rollback

`2026_11_10_090000`'s `down()` removes the mechanism (function, flag helper,
guard patches, lineage table) and refuses once any unit has been expired.
Deleted evidence cannot be restored, and the lineage must stay with the
baselines.

On DDEV, rollback restored the E21.3A schema exactly (schema-dump
comparison), and reapply was identical.

## 23. Metrics

`lycenza_retention_rows_total.operation` is now a closed set of **families**:
- audit, email, authority, communications, erasure, storage;
- student, employee, finance.

It is no longer one value per category, which had reached the label
ceiling. Logs keep the exact category.

## 24. What this does not do

- No reopen.
- No tenant purge: still unauthorized.
- No Student or Employee deletion from Finance. Their own retention runs
  re-evaluate the references that Finance expiry released.
- No change to E21.3B–E21.3E or to TCH / E33.
- Eight years is the project-adopted D8 period, not a claimed statutory
  minimum. Final ratification remains deferred to the pre-production
  closeout.
