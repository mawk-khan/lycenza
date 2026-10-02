# Finance retention cutover (E21-D8)

ADR 0064 (§1–§13 the foundation, §14–§24 the E21.3A2 cutover and expiry).
The D8 period, 8 calendar years after a financial period **closes**, is
project-adopted and pending final legal/compliance ratification. It is not
claimed to be a statutory minimum.

**Nothing in this runbook may run against production without explicit
deployment authorization (CLAUDE.md rule 16).**

## One-time cutover, per environment

Do not skip a step, and do not reorder steps 4–6.

1. **Deploy the E21.3A foundation** (migrations `2026_11_09_090000` and
   `090100`, then `DatabaseSeeder` for `finance.periods.manage`).
2. **Create the periods.** Periods are created on demand, by the first
   posting of each year and by the backfill.
3. **Backfill:** `platform:finance-periods-backfill --dry-run`, review the
   counts, then run it without `--dry-run`. It is rerunnable.
4. **Resolve every `ambiguous` entry** as an explicit, recorded decision.
   An ambiguous entry stays unmapped and blocks closing, and expiring, every
   year up to its date. Never force one.
5. **Close the ended historical years, oldest first**, under Finance →
   Financial periods. This needs `finance.periods.manage`, the typed key and
   a fresh MFA code.
   - Each close verifies itself and records `closed_at` = now.
   - Their D8 clock therefore starts at this close, not at the year end.
     Legacy detail is kept 8 years from the cutover. That is intended.
   - Never fake a close date.
6. **Verify:** `platform:finance-balances-verify` must print
   `verification=passed` for every School.
7. **Deploy E21.3A2** (migration `2026_11_10_090000`). It switches the
   production reads to carry-forward + later detail. No setting is involved:
   it is the only read path.
8. **Verify again:** `platform:finance-balances-verify`, and
   `platform:verify-database` (`retention_functions_narrow`,
   `finance_period_functions_narrow`, `runtime_destructive_privileges_restricted`).
9. **Configure** `FINANCE_RETENTION_YEARS=8`. Less than 8 is refused, and
   the database floor is 8 regardless.
10. **Enable** with `FINANCE_RETENTION_ENABLED=true`, the explicit switch
    (default off).
11. **Dry run:** `platform:finance-retention-prune --dry-run`.
12. **Review the counts:**
    - `periods_eligible`;
    - `units_eligible` / `would_delete`;
    - `held`;
    - `blocked`: owing charges, payroll evidence, canteen-linked charges,
      later activity;
    - `errors`;
    - `verification_failed`.
13. **Run the expiry:** `platform:finance-retention-prune`. It is bounded by
    `RETENTION_PRUNE_BATCH_SIZE` per School per run and is scheduled daily
    at 05:10.
14. **Verify:**
    - `platform:finance-balances-verify` (`expired_through` names the
      latest expired year; verification must still pass);
    - `platform:school-closure-status <school>`.

## Holds

`RETENTION_HOLD_SCHOOL_IDS` stops every Finance expiry for a School; its
units are counted as `held`. Closing a period is unaffected.

## What never expires through D8

- Financial periods, account baselines and the expiry lineage
  (`financial_period_expiries`).
- Receipt counters, so no receipt number is ever reused.
- Owing charges, until settled and aged past a later close.
- Anything with activity after the eligible horizon.
- **Payroll-linked journal entries** while D9 payroll evidence references
  them (the D8 × D9 intersection, ADR 0064 §21). Since E21.3F,
  `platform:payroll-retention-prune` (05:20) deletes the postings of an
  emptied payroll run; the next run of this command then expires those
  entries like any standalone entry, once their period is 8 years closed
  (ADR 0064 §25–§30).
- Canteen-linked charges.
- Unlinked provider events.

## Failure handling

- **`verification_failed=1`:** nothing more was deleted for that School,
  and the command exits non-zero. Each committed unit had already proved
  its own readings unchanged. Investigate; never edit a baseline.
- **`errors`:** a unit was refused or rolled back (for example, a racing
  reversal or void). It stays and is retried on the next run.
- **Rollback of `2026_11_10_090000`:** it refuses once any unit has
  expired. Deleted evidence cannot be restored, and the mechanism does not
  pretend to.
