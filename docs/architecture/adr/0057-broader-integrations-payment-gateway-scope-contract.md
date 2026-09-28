# ADR 0057: Broader Third-Party Integrations & Payment Gateway Scope Contract

- Status: Accepted (scope contract; documentation only)
- Date: 2026-09-28 (Phase 0O.11)
- Resolves: **O2** and **O15** (`docs/architecture/PHASE-0O-READINESS.md` §8)
- Amends, by note (no rewrite):
  - ADR 0018 — which categories are in production v1;
  - ADR 0031 — the payment-provider foundation versus the deferred gateway,
    and a future human payment-recording path;
  - ADR 0049 — the O15 partner-scope decision.
- Related:
  - ADR 0030 (ledger), ADR 0047 (School lifecycle), ADR 0050 (secrets);
  - ADR 0053 (service auth), ADR 0054 (Host boundary);
  - ADR 0055 (email), ADR 0056 (account recovery).

## 1. Context (audited 2026-09-28, `origin/main` `48f4f3d`)

**O2.** The decision reads: "Is the first real payment gateway Phase 0O
scope (roadmap premise is false)?"
- The roadmap scoped Phase 0O as integrations "beyond the payment gateway
  from Phase 0G". It recorded Phase 0G as including "the first real
  payment-gateway integration".
- No gateway was ever built.

**O15.** The decision reads: "Which 'broader third-party integrations'
(ADR 0018 list) are in 0O".
- ADR 0018 lists these categories:
  - payment gateways;
  - SMS / WhatsApp / email providers;
  - government and board compliance systems;
  - other school-management or accounting software.
- ADR 0049 (Phase 0O.2) also made enabling any production partner API scope
  depend on "an approved O15/integration decision or an amendment of this
  ADR".
- `PRODUCTION-RELEASE.md` §7 called O15 "partner integrations". That
  ambiguity is resolved in §4.

**What exists (read-only audit):**

- **Payments foundation (ADR 0031, Phase 0G.5).**
  - `PaymentProviderEventService::recordSettlement()` is a trusted internal
    ingestion boundary. It performs no signature verification.
  - Tables: `payment_provider_events` (unique on `(school_id, provider,
    provider_event_id)`), `payments` (immutable settled facts: no status,
    no payer, no payment method) and `payment_allocations`.
  - Ledger posting and allocation run in one transaction.
  - Every Finance table is INR-only by a database CHECK.
  - Its only non-test caller is the DDEV demo seeder.
  - No route reaches it: `FinanceHttpArchitectureGuardTest` forbids any
    payment mutation route and any provider-callback route.
- **Absent:**
  - a processor adapter or SDK;
  - a callback route or provider signature verification;
  - checkout, card or bank charging, or payment tokens;
  - provider refunds or voids;
  - reconciliation, payouts or provider fees;
  - provider configuration.
- **Absent, human side:** there is **no way to record any payment at all**,
  including cash. `finance.payments.manage` was deliberately never created
  (0G.5), and manual recording was deferred.
- **Integrations:**
  - Email is implemented (O13, ADR 0055).
  - Object storage is implemented (O8, ADR 0050).
  - The AI Gateway exists with only the null provider (Phase 0M is blocked).
  - Outbound webhooks are implemented, with two catalog events.
  - The partner API is foundation only: `PartnerScopeRegistry::PRODUCTION`
    is empty and issuing a client is refused in production.
  - SMS, WhatsApp and push are placeholders: channel enum values and
    local/testing fakes, with no driver registered.
  - Government/board systems (UDISE+, DigiLocker, APAAR) and accounting
    (Tally) are named in docs and not implemented.
  - Statutory payroll exports are data preparation only; nothing is
    submitted to a portal (ADR 0036).
  - SSO does not exist (ADR 0056 §1).
  - External LMS interoperability was cancelled (roadmap Phase 0I;
    `docs/modules/LMS.md`).

**No written v1 definition requires a real gateway or any unresolved O15
integration** (O1 remains open).

## 2. Decision — O2 (payment gateway)

1. **The first real payment gateway is DEFERRED from Phase 0 / production
   v1.** No processor is selected.
2. **Phase 0 adds none of:**
   - checkout, card processing or bank debit;
   - a provider SDK, callback route or payment-provider credentials;
   - provider refunds, chargebacks or payout reconciliation;
   - any PCI-bearing payment UI.
3. **The existing foundation stays as it is** (ADR 0031): trusted,
   idempotent, INR-only settlement ingestion with no route. It is neither
   removed nor exposed.
4. **The future gateway needs its OWN contract/ADR.** It must resolve, at
   least:
   - provider selection;
   - merchant/account scope (§7);
   - jurisdiction (§8);
   - PCI-DSS (§9);
   - hosted versus embedded checkout;
   - operation and provider idempotency (rule 34);
   - callback authentication (§6);
   - refunds, voids and disputes;
   - settlement/payout reconciliation (ARCHITECTURE.md §10 rule 6);
   - provider fees;
   - behaviour for a suspended School (ADR 0047: record provider evidence,
     defer business effects);
   - legal and deployment evidence.

   None of these is decided here.

## 3. Decision — manual/offline payment recording (a Finance correction, not O2)

Finance must not ship with no way to record a payment.

1. **Manual/offline payment recording is REQUIRED for production v1.** An
   authorized Finance user records a payment that has **already happened
   outside Lycenza** — for example cash, a bank transfer or a cheque. Lycenza
   never moves money.
2. **It is not a provider integration.** It is a Finance capability
   correction, implemented in a dedicated later checkpoint: **Phase 0O.11A —
   Manual / Offline Payment Recording Foundation**. That checkpoint first
   records its detailed design as an amendment to ADR 0031. Today ADR 0031
   creates a Payment only through the trusted provider-ingestion boundary,
   and FINANCE.md (0G.5) deliberately has no human "record a payment"
   action.
3. **Boundary the later contract must satisfy.**
   - **Service boundary.** It uses the existing Finance/Payments
     Application-layer boundary and the database-enforced invariants: the
     same immutable settlement, allocation and ledger model. Never a direct
     table edit.
   - **Capability.** A dedicated human capability — not the provider
     ingestion boundary, and not `finance.payments.view` — in an authorized
     School context.
   - **Required concepts:**
     - amount and currency (INR today);
     - allocation to Charges;
     - a payment method from a **closed catalog**, whose exact members that
       contract determines from product requirements;
     - payer/reference evidence where required;
     - a `recorded_by` actor;
     - explicit `occurred_at` versus `recorded_at` semantics;
     - a platform or School audit;
     - duplicate prevention and idempotency (a retried submission never
       records twice — rule 29/30 applied);
     - immutability once posted.
   - **Corrections.** A wrongly entered manual payment is corrected by an
     auditable, append-only mechanism that the later contract defines. It
     is never an edit of posted financial history. ADR 0031 records that
     correcting a payment "requires a future Refund/compensation checkpoint,
     never an `UPDATE`". No existing Finance contract provides that
     mechanism yet, so this ADR does not choose it.

## 4. Refunds, reversals and reconciliation boundary

- **Unchanged.**
  - Internal ledger reversal exists: `LedgerService::reverse()`,
    `finance.ledger.reverse`; Charge cancellation uses it.
  - **Payment reversal does not exist.**
  - **Processor refund/void does not exist.**
- **The 0O.11A correction does not introduce any of these:** refund, void,
  chargeback or payment reversal. Only a later explicit Finance contract may
  approve one, subject to the correction rule in §3.
- **No external processor or bank settlement exists, so there is no provider
  reconciliation.** A manual payment may carry a human reference/evidence
  field; that is not processor reconciliation.
- **When automated money movement arrives,** reconciliation becomes a
  first-class concern (ARCHITECTURE.md §10 rule 6).

## 5. Decision — O15 (broader integrations and partner scopes)

**Boundary.** O15 covers **both**:
- (A) the ADR 0018 integration categories; and
- (B) approval of production partner API scopes for such integrations.

The partner API is the **third-party consumer** API surface. Provider
integrations (inbound and outbound) do not have to use it: each uses its own
bounded adapter/event contract under ADR 0018.

| Category | Production v1 status | Evidence / note |
|---|---|---|
| Email | **IN V1 — resolved by O13** (ADR 0055) | Not reopened here |
| Payment gateway | **DEFERRED** (O2, §2) | Manual/offline recording is the separate v1 correction (§3) |
| SMS | **DEFERRED** | Channel enum + local/testing fake only |
| WhatsApp | **DEFERRED** | Channel enum + local/testing fake only |
| Push | **DEFERRED** | Channel enum + local/testing fake only; no device-token model |
| Government / board systems (UDISE+, DigiLocker, APAAR, …) | **DEFERRED** | No runtime integration. The existing statutory payroll **exports** (data preparation, ADR 0036) are not integrations and are unaffected |
| Tally / external accounting | **DEFERRED** | Not implemented |
| Other school-management software | **DEFERRED** | Not implemented |
| SSO / federated identity | **NOT IN V1** | ADR 0056 §1 |
| LMS interoperability (LTI, OneRoster, …) | **CANCELLED** (existing Phase 0I decision) | Not reopened |
| Production partner API scopes | **NONE APPROVED** — the catalog stays **empty**; production client issuance stays refused | §5.1 |

"DEFERRED" means: not in production v1. Any future adoption needs its own
approved contract. Nothing here schedules it.

### 5.1 Partner API

- **The foundation stays intact:**
  - one School per credential;
  - a hashed secret;
  - finite scopes;
  - per-client rate limits;
  - a fail-closed production scope catalog;
  - the local/testing-only probe.
- **O15 approves no production partner scope.** There are no partner
  writes, no partner webhooks and no `api_client` idempotency actor.
- **A future production scope needs** a new approved integration/product
  decision or an explicit amendment of ADR 0049. That includes documenting
  the scope in OpenAPI/API.md, as ADR 0049 already requires.

### 5.2 Existing outbound webhooks

- **The foundation is kept but not broadened.** The catalog stays
  `school.setting.changed.v1` and `platform.webhook_test.v1`.
- **Finding: `platform.webhook_test.v1` is subscribable in every
  environment.**
  - It is `externallyVisible: true` in `WebhookEventRegistry`.
  - Only its emitting route is local/testing-only.
  - `INTEGRATIONS.md` described the event as local/testing-only.
- **Conclusion: low-severity implementation debt, not a security issue.**
  - Production never emits the event, so such a subscription is inert.
  - It carries no data and needs the ordinary `integrations.webhooks.manage`
    capability.
  - A later executable checkpoint that touches the webhook subsystem should
    environment-gate its subscribability (the partner-probe pattern). This
    checkpoint changes no code and corrects the documentation.

### 5.3 No generic provider relay

ADR 0018 stands. There is no shared "integration webhook token" and no
generic inbound relay.

Each real future provider contract defines its own authentication scheme,
and reuses the established patterns where they fit:
- a bounded body and authentication before parsing;
- timestamp/replay controls;
- deduplication on the provider's own identifiers;
- the School derived from stored data, never the payload;
- rate limiting;
- ADR 0050 secret custody;
- audit and log redaction;
- canonical-origin URLs (ADR 0054).

The ADR 0055 email-provider event endpoint is the reference implementation of
that pattern, not a shared endpoint.

## 6. Provider credentials

- **O2/O15 introduce no new external-provider credential.** Existing ones
  keep following ADR 0050 (managed secret store, per process, never in the
  database or a School row, never visible to a School role).
- **The future payment merchant-account scope is UNDECIDED:** a platform
  merchant account, a merchant account per School, or another structure.
  This is a required owner/product decision in the future gateway ADR. No
  existing record settles it: `payments` being School-scoped does not.

## 7. Jurisdiction

**Recorded facts:**
- Finance currency is **INR**, enforced by the database.
- Indian statutory payroll exists.
- Indian systems (Tally, UDISE+, DigiLocker, APAAR) are referenced.

**Not frozen by the repository:**
- the merchant jurisdiction;
- the business entity;
- GST or other tax treatment of payments.

Future payment-provider selection is **blocked** until those business and
deployment assumptions are decided.

## 8. PCI and legal

- **PCI-DSS** remains **[LEGAL/COMPLIANCE REVIEW REQUIRED]** when a real
  gateway is designed (DATA-CLASSIFICATION.md).
- **Phase 0 manual/offline recording never collects or stores:**
  - a PAN or CVV;
  - full card details;
  - bank credentials;
  - processor authentication data.

  There is no PCI-bearing form.
- **Any future provider** (payments, SMS, WhatsApp) needs a legal and
  processor review before production. That is the O13 precedent.

## 9. Safe disabled modes (complete for v1)

| Integration | Disabled state |
|---|---|
| Payment gateway | No adapter, no callback route, no credential, no checkout |
| Partner API | Production scope catalog empty; client issuance refused |
| SMS / WhatsApp / push | No production driver; local/testing fakes only |
| Government / accounting | No runtime provider integration |

- **Nothing to switch off.** No production guard has to disable a deferred
  integration, because none can be switched on by configuration.
- **What keeps them closed** is the architecture tests
  (`FinanceHttpArchitectureGuardTest`, `ApiHardeningArchitectureGuardTest`
  for the partner scope catalog),
  the absence of any adapter, and this ADR.

## 10. Contribution to O1 (does not close O1)

**In v1:**
- the Finance ledger and Charges;
- the manual/offline payment-recording correction (0O.11A);
- email (O13);
- the external surfaces completed elsewhere (O5, O7/O11, O9, O14).

**Not required for v1:**
- a real online payment gateway;
- SMS, WhatsApp and push;
- government/board integration;
- Tally/accounting integration;
- production partner API scopes;
- SSO;
- LMS interoperability.

**After this ADR,** the only open Phase 0O owner decision is **O1**. The
repository still has one implementation checkpoint before O1 closeout:
**0O.11A**.

## 11. Definition of done (O2, O15)

**O2 is resolved** by:
- deferring the real gateway;
- separating manual/offline recording from gateway scope;
- recording the future gateway's prerequisites;
- correcting the roadmap premise.

**O15 is resolved** by:
- an explicit status for every ADR 0018 category;
- an explicit production partner-scope status;
- not reopening O13;
- keeping deferred integrations fail-closed;
- requiring new approval for any future provider credentials or scope.

## 12. Alternatives considered

1. **Bring the first real gateway into Phase 0.** Rejected:
   - no v1 definition requires it;
   - provider selection is blocked by undecided jurisdiction, merchant
     scope and PCI review;
   - it would not fix the actual gap, which is that staff cannot record
     any payment.
2. **Ship Finance with no payment recording and wait for a gateway.**
   Rejected: a School could assess fees but never record their payment.
3. **Let staff edit or insert `payments` rows, or reuse the provider
   boundary for humans.** Rejected: it breaks ADR 0031's immutable-
   settlement model and the "provider ingestion is a trusted system
   boundary, never a human capability check" rule (FINANCE.md 0G.5). A
   separate, audited human path is required (§3).
4. **Approve `academic_structure.read` as the first partner scope.**
   Rejected for v1: no integration needs it (ADR 0049 already declined it
   at 0O.3).
5. **One generic inbound provider relay.** Rejected (ADR 0018 alternative
   1).

## 13. Consequences

- **O2 and O15 are RESOLVED.** Phase 0O's only open owner decision is O1.
- **Next implementation checkpoint:** Phase 0O.11A — Manual / Offline
  Payment Recording Foundation (not started).
- **Documentation drift corrected, with dated amendment notes:**
  - roadmap Phase 0G and the Phase 0O premise;
  - the FINANCE.md 0G.8 status and payment scope;
  - PRODUCTION-RELEASE §7;
  - API.md and INTEGRATION-SECURITY.md (inbound webhooks);
  - INTEGRATIONS.md (the test event);
  - ARCHITECTURE.md §10, RELIABILITY.md and CLAUDE.md rule 34;
  - DOMAIN-MAP (Payments).
- **Recorded debt:**
  - environment-gate `platform.webhook_test.v1` subscribability (§5.2);
  - a partner write would need an `api_client` idempotency actor (ADR 0049
    already anticipates this).
