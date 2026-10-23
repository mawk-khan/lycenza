# School OS — Inventory Module (Phase 10E)

Inventory is an independent bounded context proving School operational
stock foundation: an Item catalogue, a Location directory, and a
quantity-based stock lifecycle (receive/issue/transfer) backed by an
immutable movement ledger. It is NOT put inside Finance, HR, or a
generic `Operations` mega-domain — the same independence Library
(10A), Transport (10B), Visitor (10C), and Hostel (10D) established
structurally.

## 1. Checkpoint scope

Proven in this checkpoint:

- Inventory Item catalogue (reference records): create, list/show,
  update, activate/deactivate.
- Inventory Location directory (reference records, Campus optional):
  create, list/show, update, activate/deactivate.
- Quantity stock lifecycle: receive (stock in), issue (stock out),
  transfer (atomic move between two Locations for one Item).
- A single authoritative current-quantity resource
  (`InventoryStockBalance`) per (Item, Location), reconciled by
  construction against an immutable, append-only `StockMovement`
  ledger — proven never to drift.
- A database-enforced non-negative-stock invariant, proven under real
  concurrency.
- A concurrency-safe missing-balance-row creation primitive, proven
  under real concurrency (two processes racing the very first receipt
  to an Item/Location pair).
- A deterministic, deadlock-free transfer lock order, proven under
  real opposing-concurrent-transfer concurrency.
- Cross-School rejection at the database level for every reference.
- Row-Level Security on all four tables.
- Capability-based authorization (no role-name checks).
- Admin JSON API (`/api/v1`) + Inertia UI.
- Audit trail for every significant state change.
- Platform idempotency on all three stock mutations.

Explicitly NOT in scope: individually tracked assets/serial numbers,
custody (Employee/Student), procurement/suppliers/purchase orders,
costing/valuation/Finance posting, Fees/Payments, Canteen consumption
integration, barcode/RFID/mobile scanning, reorder automation,
approval workflows, Documents/Communications integration, movement
reversal, unit conversion, analytics, AI. See §16 "Explicit non-scope"
for the full list and reasoning.

**P2 assumption**: no local Phase 10 / Inventory planning document was
found in this repository, the user's home directory, or any accessible
paste-cache at the time this checkpoint began (the same search
performed, and the same conclusion reached, for every prior Phase 10
module). This checkpoint implements only the narrow quantity-stock
foundation explicitly specified in the Phase 10E checkpoint brief and
the preceding architecture-readiness gate — it does not invent a
complete commercial inventory/warehouse-management product.

## 2. Domain boundary

`apps/platform/app/Domain/Inventory/` — `Application/Infrastructure/
Http`, the same layered shape every other Phase 10 module uses (no
`Domain/` subdirectory was needed — Inventory has no value-object/
domain-event layer distinct from its four Eloquent models and one
Application service).

Inventory OWNS: `InventoryItem`, `InventoryLocation`,
`InventoryStockBalance`, `StockMovement`.

Inventory REFERENCES, never duplicates or owns:

- `Campus` (Campus/Academic Structure domain) — a Location MAY belong
  to one Campus; Inventory never duplicates Campus name/location data.

Inventory does NOT reference Finance, Fees, Payments, Students, or
Employees — see §12 ("Finance / costing boundary") and §14 ("Custody
deferral") for why, and for the exact seam a future checkpoint would
use if either integration is ever built.

No bidirectional coupling: Campus has zero knowledge of Inventory.
Inventory depends on it; it never depends on Inventory. A repository
grep confirms no Students/AcademicStructure/Finance/Fees/Payments/
Library/Transport/Visitor/Hostel/HR module file references
`App\Domain\Inventory` in either direction.

## 3. Domain model

### InventoryItem (directory/reference entity)

A School-wide catalogue definition of what is stocked. `code`, `name`,
`unit_of_measure` (`each`/`box`/`packet`/`kg`/`litre`, a small bounded
set — no separate `UnitOfMeasure` reference table, no conversion
engine), `status` (active/inactive, no delete endpoint). Carries no
quantity, cost, or valuation column of its own — see §8/§12.

### InventoryLocation (directory/reference entity)

A physical stock-holding location. School-owned; `campus_id` is
**optional** — see §9. `code`, `name`, `status`.

### InventoryStockBalance (current-state resource)

One row per `(school_id, inventory_item_id, inventory_location_id)`
— the **authoritative current quantity**. See §5.

### StockMovement (historical transaction entity)

One immutable receipt/issue/transfer fact: `inventory_item_id`,
`movement_type`, `from_location_id`/`to_location_id` (both nullable,
shape enforced by database CHECK constraints — see §7), `quantity`,
`occurred_at`. The **authoritative history**. See §5, §10.

### Quantity vs. asset scope decision

Phase 10E ships **quantity/consumable stock only** — no individually
tracked asset instances (laptops, projectors, furniture with serial
numbers), no `InventoryAsset`, no `InventoryCategory`, no
`UnitOfMeasure` reference table, no `Supplier`/`PurchaseOrder`/
`InventoryValuation`. This was a deliberate scope decision made at the
architecture-readiness gate, not an oversight: individually tracked
assets have a structurally different core operation (custody
assignment/return against a per-unit identity, closer in shape to
Hostel's per-Bed-identity model) than quantity stock's core operation
(balance arithmetic under concurrency). Forcing both into one
foundation checkpoint would have produced either an awkward
nullable-heavy unified model or doubled the scope/testing burden of a
"foundation" checkpoint — the same reasoning every prior Phase 10
module used to scope to one coherent workflow first. Asset tracking +
custody is architecturally addable later on top of the same
`InventoryItem`/`InventoryLocation` catalogue without disrupting this
design, exactly like Hostel never had to touch Academic Structure's
`Room`.

## 4. Security / data classification

Added an explicit **Inventory** row to `docs/security/
DATA-CLASSIFICATION.md`: **Confidential** tier — not the "Internal"
tier the architecture-readiness gate initially proposed. On closer
reading of this repository's own tier definitions, the deciding factor
is the **handling baseline**, not merely "is this personal data":
`Internal` is authenticated-only ("a non-sensitive configuration
value, a public holiday calendar" — no capability gate implied), while
`Confidential` is "business-sensitive... requires authentication + a
capability check" (its own example: "internal financial summaries,
vendor contract terms" — operational/business data, not personal
data). Every Inventory read endpoint (`inventory.directory.view`/
`inventory.stock.view`) already requires a specific capability, not
merely an authenticated session — which is precisely what
distinguishes `Confidential` from `Internal` in this document.
`Confidential` is correct and does not require `Sensitive`: item
catalogue, stock levels, Locations, and operational movement history
contain no personal data whatsoever in this checkpoint (custody, the
only path to a Student/Employee association, is deferred — §14).
Should custody ever be added later, that specific Employee/Student-
association data would need its own Sensitive-tier row at that time;
this checkpoint does not pre-classify data that doesn't exist.

## 5. Stock truth architecture (authoritative vs. derived)

**Authoritative for current quantity**: `inventory_stock_balances.
quantity_on_hand` — written EXCLUSIVELY by
`App\Domain\Inventory\Application\InventoryStockService`'s three
methods, always in the same transaction as the corresponding
`StockMovement` insert.

**Authoritative for history**: `stock_movements` — append-only,
immutable (§10), never updated after insert.

There is exactly ONE Application-layer write path to either table; no
controller, model, or admin screen mutates a balance directly, and no
independent `StockMovement` creation path exists (§7 CHECK constraints
also structurally prevent any caller from inventing an arbitrary
movement shape). The stored balance is mathematically reconstructible
from `stock_movements` at any time — this is what keeps it from being
a second, ungoverned source of truth rather than a governed cache with
a proof. **Proven directly**:
`InventoryStockReconciliationTest::derived_balances_from_movement_history_exactly_match_stored_balances_after_a_mixed_sequence`
— after a mixed sequence of receipts/issues/transfers across two
Items and three Locations, the signed sum derived independently from
`stock_movements` is asserted to exactly equal every affected
`inventory_stock_balances.quantity_on_hand`, using exact-decimal
(BCMath) arithmetic throughout, cross-checked against hand-computed
expected values.

This is Model B (stored balance + immutable ledger), deliberately not
a pure Finance-ledger-style derived-only balance (Model A) despite
Finance's own ledger being derived-only: Finance's balance is an
accounting value rarely gated in real time; stock issuance is a
hot-path, concurrency-critical "is there enough right now" check,
structurally identical to Hostel's row-lock-then-decide pattern, not
to a rarely-real-time-gated ledger query. Event-sourcing/projection
machinery (Model C) was not introduced — already rejected at the
platform level for the repository's current stage (ADR 0002 §3).

## 5.1. `issueMany()` — Phase 10F's additive extension

Phase 10F (Canteen, `docs/modules/CANTEEN.md` §5) added a fourth
public method to `InventoryStockService`: `issueMany(InventoryLocation
$location, array $requirements, ?User $actor = null): array`, issuing
stock for **multiple** Items against **one** Location as a single
atomic operation — needed because one Canteen Order fulfillment can
require several distinct ingredients at once. `receive()`/`issue()`/
`transfer()` are entirely unchanged by this addition.

Contract summary (full detail in `docs/modules/CANTEEN.md` §5): all
Items must be active and same-School, the Location must be active;
every involved balance row is resolved via the SAME `ensureBalanceRow()`
primitive §8 already describes, then ALL of them are locked together
in deterministic ascending-balance-id order (generalizing §11's
two-balance transfer lock order to N balances) — never the caller's
requirement-array order, which would deadlock two concurrent
`issueMany()` calls naming the same Items in opposite order; proven
under real two-process concurrency in `InventoryStockConcurrencyTest::
opposing_concurrent_issue_many_calls_for_the_same_two_items_in_opposite_order_do_not_deadlock`.
Sufficiency is re-checked for every item after every lock is held; if
any one item is insufficient, the entire call mutates nothing. On
success, one `issue`-type `StockMovement` is inserted per item, all
inside one `DB::transaction()`.

Canteen is currently `issueMany()`'s only caller. Inventory itself
gained no dependency on Canteen from this addition — the method is a
generic N-item primitive with no Canteen-specific knowledge.

## 6. Schema / database integrity

Four tables, all `school_id` + `App\Support\Tenancy\BelongsToSchool` +
UUIDv7 + `App\Support\Tenancy\TenantRls::enable()` (RLS enabled AND
forced) + `unique(id, school_id)`:

| Table | Notes |
|---|---|
| `inventory_items` | `unique(school_id, code)`; `CHECK (unit_of_measure IN (...))`. |
| `inventory_locations` | `unique(school_id, code)`; composite FK `(campus_id, school_id)` RESTRICT, nullable. |
| `inventory_stock_balances` | `unique(school_id, inventory_item_id, inventory_location_id)` — both the stock-identity rule AND the conflict target for §8's primitive; `CHECK (quantity_on_hand >= 0)`. |
| `stock_movements` | No `updated_at`. `CHECK (quantity > 0)`; `CHECK (movement_type IN ('receipt','issue','transfer'))`; a shape CHECK enforcing receipt = to-only, issue = from-only, transfer = both; a CHECK that a transfer's `from_location_id <> to_location_id`. |

Composite FKs (School-equality enforced at the database level, never
RLS/SchoolScope alone), **all RESTRICT**:

| Child | Column(s) | Parent |
|---|---|---|
| `inventory_locations` | `(campus_id, school_id)` | `campuses(id, school_id)` |
| `inventory_stock_balances` | `(inventory_item_id, school_id)` | `inventory_items(id, school_id)` |
| `inventory_stock_balances` | `(inventory_location_id, school_id)` | `inventory_locations(id, school_id)` |
| `stock_movements` | `(inventory_item_id, school_id)` | `inventory_items(id, school_id)` |
| `stock_movements` | `(from_location_id, school_id)` | `inventory_locations(id, school_id)` |
| `stock_movements` | `(to_location_id, school_id)` | `inventory_locations(id, school_id)` |

`school_id → schools(id)` is CASCADE on every table (tenant-boundary
deletion, consistent with all four prior modules). No mutable
quantity/total column exists on `InventoryItem` or `InventoryLocation`
— current stock exists only in `inventory_stock_balances` (§5).

Every FK/CHECK above is proven at the raw-SQL level in
`tests/Feature/Postgres/{InventoryItemsRlsIsolationTest,
InventoryLocationsRlsIsolationTest,
InventoryStockBalancesRlsIsolationTest,
StockMovementsRlsIsolationTest}.php` — 35 tests total.

## 7. Movement shape is a database guarantee

The three CHECK constraints on `stock_movements` make a receipt/issue/
transfer's `from_location_id`/`to_location_id` shape a structural
guarantee, never left to controller validation alone: a receipt can
never accidentally carry a `from_location_id`, an issue can never lack
one, a transfer can never omit either side or reference the same
Location on both sides. Combined with the API/UI never exposing a
generic "create a movement" endpoint (§11), a caller cannot invent an
arbitrary movement shape through any surface.

## 8. Balance creation primitive (the concurrency-critical piece)

A naive `firstOrCreate()` then `lockForUpdate()` is NOT safe: two
processes may both observe no balance row exists for an Item/Location
pair, and a row that does not yet exist cannot be locked.
`InventoryStockService::ensureBalanceRow()` instead: attempts a
conflict-safe `INSERT ... ON CONFLICT DO NOTHING` (Laravel's
`insertOrIgnore()`, which the Postgres grammar compiles exactly to
`ON CONFLICT DO NOTHING`) against
`inventory_stock_balances_item_location_unique`, then unconditionally
re-reads the canonical row by its natural key
`(inventory_item_id, inventory_location_id)`. Exactly one of any
number of concurrent callers physically inserts; every other caller's
insert is silently ignored by PostgreSQL (no exception raised, unlike
a raw INSERT), and every caller's subsequent re-read resolves to the
SAME row — the winner's. Only after this resolution does the caller
`lockForUpdate()` the canonical row.

**Proven directly** under real two-process concurrency:
`InventoryStockConcurrencyTest::
two_real_concurrent_first_ever_receipts_to_the_same_item_and_location_lose_no_update`
— both concurrent first-ever receipts succeed (no unique-constraint
leak to either client), exactly one balance row exists, and the final
balance equals the exact sum of both receipts.

## 9. Receive

`InventoryStockService::receive(item, location, quantity, actor)`
requires an active Item and Location, validates the quantity (§13),
resolves and locks the balance row (§8), increments it via exact
BCMath arithmetic (`bcadd`, never a float), inserts exactly one
`receipt` `StockMovement`, audits, and commits atomically — all inside
one `DB::transaction()`. Concurrent receipts (including the very
first) never lose an update (§8).

## 10. Issue / negative-stock guarantee

`InventoryStockService::issue(item, location, quantity, actor)`
requires an active Item and Location, validates the quantity,
resolves and locks the SAME balance row, then re-checks sufficiency
**after** the lock is held (`bccomp($balance->quantity_on_hand,
$quantity, 3) < 0` → `InsufficientStockException`) — never a
pre-lock `currentQuantity() >= issueQuantity` check, which would be
unsafe under concurrency. The row lock is the primary mechanism; the
database's `inventory_stock_balances_quantity_non_negative_check`
CHECK constraint is the final structural backstop, translated to
`ConcurrentStockConflictException` (409) if it is ever somehow
reached. Exactly zero remaining is a valid, accepted result;
negative is structurally impossible.

**Proven directly** under real two-process concurrency (the gate's own
required scenario): `InventoryStockConcurrencyTest::
two_real_concurrent_processes_issuing_seven_from_a_stock_of_ten_leave_exactly_three`
— initial stock 10, two concurrent `issue(7)` calls, exactly one
succeeds, the loser receives `InsufficientStockException`, final
balance is exactly 3 (never −4), exactly one `issue` `StockMovement`
exists.

## 11. Transfer / lock ordering

`InventoryStockService::transfer(item, from, to, quantity, actor)` is
ONE atomic transaction producing exactly one decrement, one increment,
and one `transfer` `StockMovement` — never an issue+receipt pair.
Both balance rows are resolved via §8's primitive BEFORE any lock is
acquired (lock order cannot be determined from ids that do not exist
yet — a first-ever transfer to a Location creates its destination
balance row as part of this same resolution step). Only once both
canonical row ids are known are they sorted **ascending by their own
UUID** (`SORT_STRING`) and locked in that order — deliberately NOT a
fixed source-then-destination role order: both locked resources are
the same entity type, so a role-based order would deadlock two
opposing simultaneous transfers (A→B and B→A each locking their own
"source" first). Ascending-id ordering is direction-independent and
provably deadlock-free. Insufficient source stock throws before either
balance row is mutated; the whole transaction rolls back, including
any newly-created zero-balance destination row created during
resolution.

**Proven directly** under real two-process concurrency:
`InventoryStockConcurrencyTest::
opposing_concurrent_transfers_between_two_locations_both_succeed_without_deadlock`
— Location A and B both hold sufficient stock, process 1 transfers
A→B while process 2 concurrently transfers B→A for the same Item, a
bounded 15-second subprocess timeout is set so a genuine deadlock
would fail the test deterministically rather than hang the suite, and
both complete successfully with correct final balances and both
`StockMovement` rows present.

## 12. Finance / costing boundary

No costing in Phase 10E. `stock_movements` and `inventory_stock_
balances` carry no `unit_cost`, `purchase_price`, `total_value`,
`account_id`, `journal_entry_id`, `charge_id`, or `payment_id` — none
of these are required for a correct quantity foundation, and Finance's
own integrated architecture does not currently require or expose a
consumption contract for them (a repository search confirms zero
references to `App\Domain\Inventory` anywhere in `App\Domain\
{Finance,Fees,Payments}`, and vice versa).

The architectural seam for a **future** costing checkpoint (not built
now): the same pattern Fees already uses against Finance — a future
`inventory_stock_valuations` satellite table (never a column bolted
onto `stock_movements` itself) would hold a `journal_entry_id` FK and
call `LedgerService::post()` synchronously inside its own transaction,
exactly like `ChargeService::assess()` does. `stock_movements.id` is a
stable, permanent identifier such a future table could reference
(`stock_movement_id` FK) without any change to the movement table
itself.

## 13. Unit / decimal handling

All quantities are stored as PostgreSQL `NUMERIC(14,3)`, cast in
Eloquent as `decimal:3` (backed by `brick/math`'s exact `BigDecimal`
internally — never a PHP float), and compared/added/subtracted in the
Application layer exclusively via BCMath (`bccomp`/`bcadd`/`bcsub`,
scale 3 throughout) — matching this repository's existing
`App\Support\Money\Money` "never float for exact quantities"
discipline (rule 9's monetary principle, applied here to operational
quantities).

`unit_of_measure` is a small bounded set (`each`/`box`/`packet`/`kg`/
`litre`) — no separate `UnitOfMeasure` reference table, no conversion
engine. **Deliberately no `allows_fractional_quantity` column**:
whether an Item's quantity may carry a fractional part is fully
determined by `unit_of_measure` itself
(`InventoryItem::allowsFractionalQuantity()` derives this from a
static map: `kg`/`litre` fractional, `each`/`box`/`packet` whole-number
only) — adding a second, independently-settable column would risk the
two disagreeing, which is exactly the duplicate-source-of-truth
problem the architecture gate warned against. `InventoryStockService::
assertValidQuantity()` enforces both rules (positive; whole-number for
a non-fractional unit, via exact string inspection of the fractional
part, never a float-based `fmod`/rounding check) before any lock is
acquired. The API/UI layer additionally validates the wire-format
quantity string (`regex: ^\d+(\.\d{1,3})?$`) at the request boundary,
so a quantity with more than 3 decimal places is rejected before it
ever reaches the service.

## 14. Custody deferral

`InventoryCustodyAssignment` is explicitly OUT of Phase 10E — no
serial-number asset units, no Employee/Student custody, no polymorphic
owner column. This mirrors the architecture-readiness gate's own
conclusion: custody is only conceptually clean once individually
tracked asset instances exist (§3's quantity-vs-asset decision), and
quantity-stock custody is a materially weaker, rarely-required concept
that doesn't justify a dedicated table in the foundation checkpoint.
If custody is ever built (only alongside a future asset-tracking
extension), it must follow the Documents/`documents` table precedent
exactly: two explicit nullable FK arms (`employee_id`, `student_id`),
each with its own composite FK, plus a CHECK enforcing exactly one is
set — never a polymorphic `owner_type`/`owner_id` column (the same
rule 70 rejection Finance's own module doc already documents for its
own financial-subject/payer problem).

## 15. Active/inactive lifecycle

Items and Locations use `active`/`inactive` only — not exhausted,
damaged, blocked, or archived. An inactive Item or Location cannot
participate in a **new** stock mutation
(`ItemNotAvailableException`/`LocationNotAvailableException`,
pre-lock, mirroring `HostelBed::isAvailableForAssignment()`'s
precedent); existing balance and movement history remain queryable
and unchanged. Deactivation never silently zeroes/drains a positive
balance, and reactivation never alters balances/history — proven
directly: `InventoryLifecycleTest::
deactivating_an_item_with_positive_balance_does_not_alter_the_balance`
and `::historical_movements_and_balance_survive_item_and_location_deactivation`.
No delete endpoint exists for either entity (rule 73).

## 16. Movement immutability limits

`stock_movements` is append-only in **application** semantics: no
update/delete endpoint exists anywhere, and
`InventoryStockService` only ever `create()`s a movement, never
queries one back for mutation -- **proven directly** by
`StockMovementArchitectureGuardTest` (no PATCH/PUT/DELETE route under
`inventory-stock/movements`, no generic `/stock-movements` creation
route, no `update`/`delete`/`SoftDeletes` on `StockMovement.php`, no
`$movement->update(`/`->delete(`/`->save(` in `InventoryStockService.php`).

**Since ORG.7H-1R (ADR 0076 §55) PostgreSQL enforces it too, by
privilege:** the runtime role `school_os_app` has no UPDATE and no
DELETE on `stock_movements` (`TenantRls::makeAppendOnly()`, migration
`2026_12_29_090000`; `platform:verify-database` checks it through
`DatabaseRoleVerifier::NO_RUNTIME_UPDATE`/`NO_RUNTIME_DELETE`). The
ledger is evidence for the Location Campus freeze below, so a
deletable or re-pointable movement would let a used Location look
unused. A School's own cascade (platform-only, rule 86) is unaffected.
Reversal/correction of a posted movement stays deferred -- document as
`movements immutable; reversal deferred`, matching Visitor/Hostel/
Transport's own first-checkpoint precedent of shipping without an
undo concept; a future reversal is a NEW movement, never an edit.

## 17. Historical parent deletion

Carrying forward the corrected Phase 10C/10D rule: every reference
from a historical/current-state row to its parent (Item, Location) is
RESTRICT, never CASCADE — §6's table. No delete endpoint exists for
either parent (rule 73), and the database itself now structurally
rejects deletion attempts regardless of any future maintenance
script/raw-SQL session/refactor. **Proven directly** — an Item
referenced by a stock balance cannot be deleted; an Item referenced
by a historical `StockMovement` cannot be deleted (even after its
balance row is separately removed, isolating the movement FK as the
sole remaining blocker); a Location referenced by a stock balance
cannot be deleted; a Location referenced as a movement's
`to_location_id` cannot be deleted — each proven via a real raw-SQL
DELETE attempt (never merely "no delete endpoint exists"), using the
same-`pgsql`-connection-as-fixture pattern (see §18 for why this
matters).

## 18. Test-infrastructure lesson: connection visibility for raw-SQL RLS tests

During this checkpoint's own test-writing, four raw-SQL tests
initially used `DB::connection('pgsql_admin')` to insert a row
referencing same-test fixtures created via the default `pgsql`
connection (mirroring what appeared to be Hostel's own precedent) and
failed with `SQLSTATE[23503]: ... school_id is not present in table
"schools"` — `pgsql_admin` genuinely cannot reliably see `pgsql`'s
still-uncommitted transaction (the base `Tests\TestCase` wraps only
the default connection via `DatabaseTransactions`, rule confirmed
directly from `Illuminate\Foundation\Testing\DatabaseTransactions::
connectionsToTransact()`). The fix, applied to all four affected
tests: set the RLS session context (`setSchool()`) and perform the
insert on the SAME `pgsql` connection that created the fixtures,
exactly like this test file's own pre-existing `insertMovement()`
helper already did correctly. Recorded here as a P3 test-infrastructure
finding for future modules — the reliable pattern is same-connection-
as-fixture-creator, not merely "use `pgsql_admin` for a cross-School
insert" as a blanket rule.

## 19. Capabilities

Two independently gateable areas, mirroring Hostel's directory/
residency split: `inventory.directory.view`/`.manage` (Items AND
Locations together — both are reference/structural entities with no
independent meaning worth their own capability, exactly like Hostel's
`hostel.directory.*` covering Hostel+Room+Bed) and `inventory.stock.
view`/`.manage` (balances, movement history, receive/issue/transfer).
No capability per movement type. `school_admin` and `principal` hold
all four by default, matching the established "day-to-day operational
parity" precedent. No dedicated "Storekeeper" system role was created
— a School wanting narrower staff can already compose a custom role.
*(SR.0 correction, 2026-10-09: a School cannot compose, create or configure a role — no runtime role writer exists (ADR 0059 §1), and tenant-custom roles are deferred (ADR 0063 T3). The fixed system catalogue in ADR 0071 provides `stores_officer` (all four `inventory.*` keys).)* *(SR.3 update, 2026-10-09: implemented — the fixed production catalogue is seeded by `CapabilityAndRoleSeeder` and snapshot-pinned; ADR 0071 §25.)*

Proven: `tests/Feature/Authorization/InventoryCapabilityTest.php` (12
tests) — catalog integrity, no speculative capabilities, default role
grants, area/view-manage independence, tenant isolation,
central-identity-alone denial, existing grants unaffected.

## 20. API surface

`/api/v1/schools/{schoolId}/inventory-items[...]`,
`/inventory-locations[...]`, `/inventory-stock` (current balances),
`/inventory-stock/movements` (history), and three **command-style**
mutation endpoints only — `POST .../inventory-stock/receive`,
`.../issue`, `.../transfer` — each backed by the corresponding
`InventoryStockService` method. **No generic `POST /stock-movements`**
exists (§7, §16). GET endpoints authorize inside the controller;
mutating routes carry `capability:`/`throttle:school-api-mutations`,
and all three stock commands additionally carry `idempotent`.

OpenAPI (`packages/contracts/openapi/school-os-api.yaml`) documents
every path/schema (9 paths, 8 schemas, 2 parameters);
`packages/shared-types` was regenerated via `npm run generate` with
verified zero drift (identical output on a second run).

## 21. Administrative UI

Session-authenticated Inertia pages under `/app/inventory-items`,
`/app/inventory-locations`, `/app/inventory-stock`: Item/Location
directories (list/search/create/update/activate-deactivate), a Stock
page (current balances + movement history, both independently
paginated), and receive/issue/transfer forms with capability-gated
Item/Location live search. Reuses the existing `EmptyState`/
`Pagination`/`StatusBadge` components and layout conventions verbatim
— no app-shell redesign.

## 22. Audit

Every significant state change is recorded via the existing
`App\Support\Audit\AuditRecorder::school()`: `inventory.item.created`,
`inventory.item.updated`, `inventory.location.created`,
`inventory.location.updated`, `inventory.stock.received`,
`inventory.stock.issued`, `inventory.stock.transferred`. Metadata
carries movement id, Item id/code, Location id(s)/code(s), quantity,
and status transitions only — quantity is legitimate operational data
for audit (not Sensitive personal data, since custody is deferred —
§14). No free-text/unrestricted field exists anywhere in this module
to leak into audit metadata.

## 23. Idempotency decisions

**Receive/issue/transfer**: all three carry the `idempotent`
middleware — each creates a new immutable `StockMovement` and mutates
a `StockBalance`, exactly the "consequential retryable mutation" shape
rule 29 targets, mirroring every prior module's "assign"-type create.
Proven per-operation: `InventoryApiTest`'s three full idempotency
suites (first request, identical replay with no double-applied stock
effect, same-key-different-payload conflict, capability re-evaluated
on replay after the actor is disabled) — 9 tests total across the
three commands.

No new idempotency machinery — `App\Support\Idempotency\
IdempotencyGuard`/`App\Http\Middleware\EnsureIdempotent` are reused
exactly as-is.

## 24. Events / Documents / Communications decision

**Events**: zero Inventory domain events in this checkpoint. Finance's
own module doc names Inventory costing only as an acknowledged future
dependency, not a currently built consumer contract — inventing
`StockReceived`/`StockIssued`/`StockTransferred` events now would
repeat exactly the speculative-event mistake every prior module's
checkpoint declined to make.

**Documents**: no changes — no procurement/purchase-receipt/invoice
attachments in scope.

**Communications**: no changes — no low-stock alerts, no
stock-change emails, no new audience type.

## 25. Explicit non-scope (deferred, not forgotten)

- Individually tracked assets, serial numbers, `InventoryAsset`.
- Custody (Employee/Student) — §14.
- Procurement, suppliers, purchase orders, goods-receipt workflow,
  invoices, approvals — a receipt means only "an authorized
  administrator recorded that quantity entered a Location," never why
  it arrived.
- Costing, valuation, `unit_cost`/`purchase_price`/`total_value`,
  Finance journal posting — §12.
- Fees, Payments.
- Canteen stock-consumption integration (a future Canteen checkpoint
  would call `InventoryStockService::issue()` directly, once its own
  Fees/billing architecture is separately designed).
- Barcode hardware, RFID, mobile scanning.
- Stock forecasting, automatic reorder/replenishment, low-stock
  alerts.
- Approval workflows.
- Documents/Communications integration — §24.
- Unit-conversion engine.
- Movement reversal/correction — §16.
- Analytics dashboards, AI-assisted allocation.
- `InventoryCategory` — no product requirement justified a taxonomy
  entity for v1.

## 26. Security review

Reviewed and addressed/proven-safe for every item the checkpoint brief
required:

- Cross-School IDOR: every Item/Location/balance/movement lookup 404s
  on a cross-School id, and a cross-School `inventory_item_id`/
  `inventory_location_id` in a mutation payload is rejected
  (`InventoryApiTest`).
- RLS escape: proven at the raw-SQL level for all four tables (§6).
- Cross-School Item/Location composite-FK bypass: proven rejected for
  every parent reference (§6).
- Unauthorized catalogue/stock search: the anti-P1 regression test
  (`InventoryAdminUiTest::
  a_member_without_stock_manage_cannot_use_the_stock_search_endpoints`).
- Unauthorized mutations: full capability allow/deny/wrong-School/
  area-independence coverage (§19).
- Balance-row first-create race: proven safe (§8).
- Negative-stock race: proven safe (§10).
- Lost-update receipt race: proven safe (§8, same primitive).
- Transfer partial failure: proven — insufficient source stock leaves
  both balances and the movement ledger untouched, by construction of
  the single transaction (`InventoryStockServiceTest::
  a_failed_transfer_does_not_mutate_either_balance_or_create_a_movement`).
- Transfer deadlock: proven safe under real opposing concurrency (§11).
- Duplicate idempotent effects: proven safe for all three mutations
  (§23).
- Quantity precision/overflow: `NUMERIC(14,3)` throughout, exact
  BCMath arithmetic, wire-format regex validation (§13).
- Fractional-unit validation: proven both directions (§13).
- Movement shape bypass: structurally impossible (§7).
- Inactive Item/Location mutation: proven rejected (§15).
- Hard-delete history loss: structurally impossible, proven at the
  raw-SQL level (§17).
- Direct balance mutation paths: none exist outside
  `InventoryStockService` (§5).
- Movement update/delete application surfaces: proven absent
  (`StockMovementArchitectureGuardTest`, §16).
- Finance/costing scope drift: proven absent by repository-wide grep
  for `unit_cost`/`purchase_price`/`total_value`/`account_id`/
  `journal_entry_id`/`charge_id`/`payment_id` across the Inventory
  module — zero matches.
- Unrestricted free text: no notes/description/reference field exists
  anywhere in this module's schema or API.
- Unsafe mass assignment: every controller validates an explicit field
  allow-list; no `$request->all()` passed to `create()`/`update()`.

No P0/P1 findings remain open. One P3 test-infrastructure finding is
recorded (§18) — fixed during this checkpoint, kept documented as a
lesson for future modules.

## 27. Test evidence

- Postgres/RLS: 35 tests (`tests/Feature/Postgres/{InventoryItemsRls
  IsolationTest, InventoryLocationsRlsIsolationTest,
  InventoryStockBalancesRlsIsolationTest,
  StockMovementsRlsIsolationTest}.php`, 4 files).
- Real two-process concurrency: 3 tests
  (`InventoryStockConcurrencyTest` — over-issue, concurrent first
  receipts, opposing concurrent transfers).
- Reconciliation (Model B anti-drift proof): 1 test
  (`InventoryStockReconciliationTest`).
- Authorization: 12 tests
  (`tests/Feature/Authorization/InventoryCapabilityTest.php`).
- Directory/Location lifecycle + inactive-blocks-mutation (API): 15
  tests (`tests/Feature/Inventory/InventoryLifecycleTest.php`).
- Stock service lifecycle: 21 tests
  (`tests/Feature/Inventory/InventoryStockServiceTest.php`).
- Movement architecture guard (no update/delete surface): 4 tests
  (`tests/Feature/Inventory/StockMovementArchitectureGuardTest.php`).
- Full API surface + idempotency (x3) + IDOR: 21 tests
  (`tests/Feature/Inventory/InventoryApiTest.php`).
- Admin Inertia UI + anti-P1 search-endpoint regressions: 12 tests
  (`tests/Feature/App/InventoryAdminUiTest.php`).

Total: 124 tests (120 in the `Inventory`-filtered run plus the 4
architecture-guard tests counted within it), 299+ assertions, all
passing, zero skipped. Full-project regression (default and random
order): 3648 tests, 11886/11909 assertions respectively, 0 failures.

## Retention (E21.3E, 2026-10-02)

Inventory balances and stock movements are tenant-lifetime School operations
without personal data (E21.2G O5): no E21 mechanism expires them (pinned by
`ResidualRetentionArchitectureGuardTest`). Canteen stock consumptions follow
their orders under Finance (D8).

## Campus scope (ORG.7A, ADR 0076 §33)

- **Adopted:** `inventory.stock.view` and `.manage`, decided by the
  Location's Campus.
  - A Location with no Campus is a School-wide store: whole-School scope
    only.
  - Balances are shown when their Location is in scope.
  - Movements are shown only when every Location they touch is in scope.
  - A transfer needs scope over both Locations.
  - An out-of-scope Location is 404.
- **Not adopted:** the Item and Location directory (`inventory.directory.*`)
  stays School-only. The active Item lookup on the stock pages is the
  declared shared lookup.
- **One access class.** All reads and targets go through
  `App\Domain\Inventory\Application\InventoryStockAccess`. Canteen's
  system stock issue (`issueMany`) is unchanged; Canteen is not adopted.

## Location Campus freeze (ORG.7H-1, ADR 0076 §54)

- **A Location's Campus is provenance.** Before any operational use it may
  be corrected (NULL -> Campus, A -> B, Campus -> NULL). Once used it never
  changes: a used shared Location stays shared, a used Campus Location stays
  on its Campus, so stock history can never move to another Campus.
- **"Used"** = any stock movement from or to the Location, any stock balance
  row, or any Canteen Order naming it -- the database function
  `inventory_location_has_operational_history()`, the one definition.
- **One writer:** `InventoryLocationService` (web and API create/update),
  which locks the Location FOR UPDATE and answers 409
  `INVENTORY_LOCATION_CAMPUS_FROZEN`. Name and status stay editable.
- **Database backstop:** `trg_inventory_location_campus_freeze` refuses the
  change for every writer; `trg_inventory_location_evidence` share-locks the
  Location on each evidence insert, so first use and a Campus change
  serialize.
- **Stock writes** share-lock their Location(s) first and refuse (the
  generic "not available") if the Location changed Campus after the caller
  resolved it. Transfers between Locations are unchanged.
- **"Used" is monotonic (ORG.7H-1R, ADR 0076 §55).** The runtime role can
  never erase or move the evidence: movements are append-only (no UPDATE,
  no DELETE); balance rows and Canteen Orders are never deleted (no DELETE;
  a balance's quantity and an Order's lifecycle stay writable); and the
  evidence guard refuses any UPDATE that re-points an evidence row's
  Location (`inventory_location_evidence_immutable`). A used Location never
  becomes unused again.

## Issue recipient and write-off (ORG.9C-2, 2026-10-23, ADR 0076 §70)

Both run on the ONE ledger and the ONE writer (`InventoryStockService`), with the same lock order: Location(s) FOR SHARE, then the balance FOR UPDATE, then sufficiency re-checked under the lock. Nothing else changes.

**Issue (unchanged movement type `issue`).**
- An issue is terminal consumption out of ONE Location. Stock going to another Location is a transfer; a Canteen sale stays the Canteen order lifecycle (the issue carries no money, price or Student).
- It may name an optional organisational recipient: an HR Department (`recipient_department_id`). The Department must be active and School-wide or on the Location's Campus, checked under a share lock through HR's `DepartmentReference`.
- The issue form's Department lookup offers School-wide Departments and those on in-scope Campuses only.
- **Not built (STOPPED for an owner decision):**
  - **Person recipients (Employee, Student).** Movements are tenant-lifetime data without personal data (E21.2G O5), and a person reference would block that Employee's E21-D9 purge indefinitely. It needs an E21/owner decision, not a slice.
  - **Returnable issue / custody.** Still §14: only alongside asset tracking.

**Write-off (new movement type `write_off`).**
- Units physically no longer usable or present. The closed `WriteOffReason` is damaged, expired or lost, DB-required on exactly the write-offs (`stock_movements_write_off_reason_check`).
- No free text is stored (E21.2G O5).
- No "count correction": there is no stocktake model, and a counting or entry mistake is not damage.
- **DIRECT_WITH_ASSURANCE** (`ApprovalWorkflowRegistry::DIRECT_ASSURED`), never an approval chain:
  - the existing `inventory.stock.manage` in the Location's scope;
  - re-read fresh under the locks (`ApprovalDecisionAuthority::requireCurrent()` with the Location's Campus, `CapabilityResolver::holdsInCampusNow()`);
  - a fresh MFA code (`FreshMfaRequirement`, outside the transaction);
  - an explicit web confirmation (UX assurance only).
- Audited as `inventory.stock.written_off` (reason code, `freshMfa`).
- **Correction:** a write-off is never edited or deleted. Stock that turns up again is a new receipt.
- **No accounting:** Inventory carries no cost or journal (§12).
- **No fixed-asset disposal:** there is no asset model (§3, §14).

**Duplicate submissions.**
- **Web:** web issue and write-off forms carry a server-issued key (`stock_movements.idempotency_key`, unique per School). The same key with the same terms returns the recorded movement (nothing new is written or audited); other terms are refused.
- **API:** the API routes keep the `idempotent` middleware.
- **Not covered:** receive and transfer on the web keep their earlier protection (the disabled button), unchanged.

**History.** The web movement history filters by type and shows each issue's Department and each write-off's reason; names come from one read per page.

**Evidence.**
- **Append-only, unchanged:** the runtime role still has no UPDATE or DELETE on `stock_movements` (H1R).
- **The migration's `down()`** refuses while any write-off, recipient or claimed key exists.

## Stock register and month view (ORG.9C-3, 2026-10-23, ADR 0076 §71)

Two read-only pages on `inventory.stock.view`, read from the ONE ledger by `InventoryStockHistory`. Nothing is stored: no second balance table, no saved opening or closing.

**Scope first, filters second.**
- Every query starts from the reader's Locations (`InventoryStockAccess`: the stored Location Campus; a School-wide Location is whole-School scope only).
- A Campus, Location or Item filter only narrows that set. A filter outside the scope selects nothing.
- The Campus and Location options list the reader's own scope only.

**One sign rule:** `StockMovementEffect`, for a set of Locations.

| Movement | Effect on the set |
|---|---|
| receipt | +q at its destination |
| issue (Canteen consumption is an issue) | −q at its source |
| write-off | −q at its source |
| transfer | −q out, +q in; 0 when both ends are in the set |

So no transfer changes a School-wide total.

**Stock register** (`/app/inventory-stock/register`; INV-016 by Campus, INV-023 by Location).
- One row per movement, newest first (occurred_at, then id), 50 per page.
- Filters: Campus, Location, Item code or name, type, and School-local from/to dates.
- Visibility is the existing rule: a movement shows only when every Location it touches is in scope.
- Each row shows the issue's Department and the write-off's reason code. It never shows a person, an actor, a form key or any MFA data.

**Stock by month** (`/app/inventory-stock/monthly`; INV-020). For one School-local month (the current month runs to today; a future month is refused), per Item over the selected Locations:
- **opening:** everything before the month's first School-local instant;
- **in:** received and transferred in;
- **out:** issued, written off and transferred out;
- **closing:** opening plus the signed month.

How it reconciles:
- **Months chain:** each month closes where the next opens.
- **Today's closing equals the canonical balance:** this month's closing for one Location equals `inventory_stock_balances` (tested).
- **Transfers across the edge:** a transfer between two selected Locations nets to zero. One crossing the selection counts at the selected end, without naming the other Location.
- **No cross-Item totals:** each row is in its Item's own unit.

**Query shape.**
- The selected Location ids are resolved once and tested with `= ANY(...)`, never joined per movement.
- At 100,000 synthetic movements (unanalyzed tables), the month view took 81 ms (whole School) and 22 ms (one Location); the register took 16 ms.
- **Follow-up (ADR §71.4):** the month view sums all history before the month, so its cost grows linearly with a School's ledger.
- **Edge transfers:** a transfer crossing the selection's edge is counted as an aggregate at the in-scope end, never as a row and never naming the other Location (rule 106).

**Not built:**
- categories and average price (no model);
- issues split by person recipient type (OD-9.4);
- count or stocktake variance;
- returnable or outstanding issues;
- fixed assets;
- exports (D-11).
