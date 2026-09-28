# Manual / offline payment recording (Phase 0O.11A)

Records a payment a School has **already received outside Lycenza** — cash,
an offline bank transfer or a cheque. **Lycenza never moves money.**
This is not a payment gateway, checkout, refund, void, payment reversal or
reconciliation (ADR 0057 §3–4).

- **Design:** ADR 0031, "Implementation amendment — manual / offline
  settlement recording".
- **Module detail:** `docs/modules/FINANCE.md` "0O.11A as-built".
- **Rule:** CLAUDE.md rule 91.

## Who can record

- **Capability:** `finance.payments.record`, in the School. The seeded
  catalog grants it to **School Admin only**. A School can create its own
  role (e.g. a cashier) holding it.
- **Not enough on its own:** viewing Payments (`finance.payments.view`) or
  managing Charges or the ledger.
- **No path for Group or platform authority.** An elevated platform session
  is refused (403) on every School route.
- **Only an `active` School can record.** A suspended School is refused,
  whether it was suspended before or during the request.

## Recording (Finance → Payments → Record offline payment)

1. **Choose the Student.** Their uncancelled Charges are listed with what is
   still outstanding. A Charge page's "Record offline payment" link
   pre-selects that Charge.
2. **Enter the amount applied to each Charge.** One payment may cover
   several Charges. The applied amounts must add up exactly to the amount
   received, and no Charge can receive more than it still has outstanding.
3. **Enter the payment details:**
   - the amount received (INR);
   - the date received — the School's local date, today or earlier;
   - the method (Cash, Bank transfer, Cheque);
   - optionally, a reference — a cheque number or a bank transfer UTR;
   - the **Received into** account (an active asset account such as Cash
     in Hand or Bank).
4. **Review, then Record payment.** The summary is the last chance to check.

**A recorded payment cannot be edited, deleted, reversed or refunded.** v1
has no correction action (owner decision 2026-09-28). A mistake needs the
future Finance correction contract. Until then, escalate it and keep the
record as is. **Never** try to "fix" a payment by reversing its journal
entry: the ledger refuses that (`JOURNAL_ENTRY_NOT_REVERSIBLE`).

## References

- **Never** enter card numbers, CVV, bank account numbers, passwords or any
  bank credential. The field accepts only 1–64 letters, digits, spaces and
  `. / _ -`.
- A reference is optional for every method.
- It is **not** checked for uniqueness: cheque numbers repeat across banks.
  Lycenza does not claim to detect a real-world payment recorded twice
  through two separate forms. Check the Student's Payments first.

## Duplicate submissions

Each opened form carries a one-time key.
- **Resubmitting the same form** — a double click, a retry after a network
  error — returns the already-recorded Payment ("This payment was already
  recorded") and records nothing new.
- **Reusing the key with different details** is refused. Reload the form.

## Where it shows

- **The Payments list and detail** show the source (**Offline** with its
  method, or **Provider**), the reference, the received date, the recorded
  time and who recorded it.
- **The ledger** shows one balanced entry, "Offline payment recorded:
  <method>":
  - debit the Received-into account;
  - credit each Charge's receivable account.
- **Audit:** `payment.recorded_manually`, with no amount, reference or key
  in its metadata.

## Diagnostics

| Symptom | Meaning |
|---|---|
| 403 on the page | The user lacks `finance.payments.record` in the active School |
| 409 on submit with "Select a School" | No active School in the session, or the School is not active |
| "An amount applied to a charge is more than that charge still has outstanding" | Another payment was recorded against it first (concurrent recording is serialized, never double-allocated) |
| "One of the selected charges has been cancelled" | The Charge was cancelled before the payment committed |
| "This payment form was already used to record a different payment" | Key reuse with changed details: reload the form |
| 429 | More than 30 recordings a minute by one user (`finance-payment-recording`) |

No Finance metrics exist. Failures surface as ordinary request errors and
logs, which never contain the form payload, reference or key.
