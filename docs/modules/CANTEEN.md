# School OS — Canteen Module (Phase 10F)

Canteen is an independent bounded context proving a School's canteen
foundation: an Outlet directory, a menu Item catalogue with a
per-Item recipe, and a Student order lifecycle (place → fulfill →
cancel) that consumes Inventory stock and posts a Fees Charge at
fulfillment. It is NOT put inside Fees, Inventory, or a generic
`Commerce` mega-domain — the same independence Library (10A),
Transport (10B), Visitor (10C), Hostel (10D), and Inventory (10E)
established structurally, extended here to a module that genuinely
depends on TWO sibling modules (Students/SIS, Fees, Inventory) rather
than zero or one.

## 1. Checkpoint scope

Proven in this checkpoint:

- Canteen Outlet directory: create, list/show, update,
  activate/deactivate. Each Outlet is backed by exactly one
  InventoryLocation it issues stock from at fulfillment time.
- Canteen menu Item catalogue: create, list/show, update, price
  changes, activate/deactivate.
- Per-Item recipe (`CanteenItemInventoryRequirement`): how much of
  each InventoryItem one unit of a CanteenItem consumes, evaluated AT
  FULFILLMENT time.
- Student order lifecycle: place (snapshot price/location, no
  Inventory/Fees touch), fulfill (issue aggregated Inventory stock +
  assess a Fees Charge, atomically), cancel (pending only).
- An additive, backward-compatible extension to
  `InventoryStockService` (`issueMany()`) enabling one Order's
  multi-ingredient stock consumption as ONE atomic, deadlock-safe
  operation — see §5.
- Charge-at-fulfillment billing, configured per School
  (`CanteenBillingConfiguration`) against two validated
  `ledger_accounts` — see §9.
- Four concurrency scenarios proven under real two-process
  concurrency — see §13.
- A consumption-link table proving which `stock_movements` rows were
  caused by which Order fulfillment, with a database-enforced
  one-Movement-claimed-by-one-Order invariant.
- Cross-School rejection at the database level for every reference.
- Row-Level Security on all seven Canteen tables.
- Capability-based authorization (no role-name checks), split
  directory/orders/settings, mirroring Inventory's own split extended
  with a third pair for financial configuration.
- Admin JSON API (`/api/v1`) + Inertia UI.
- Audit trail for every significant state change.
- Platform idempotency on order placement and fulfillment.
- A dedicated security-review pass across the full checklist in §17.

Explicitly NOT in scope — see §16 "Explicit non-scope" for the full
list and reasoning: wallets/prepaid balances, dietary/allergen/medical
data, free-text notes anywhere in the schema, order-line/price
mutation after placement, refunds/reversals, multi-item recipes shared
across Outlets with different pricing, procurement, Guardian/
Student-facing ordering UI, Documents/Communications integration,
analytics, AI.

## 2. Domain boundary

`apps/platform/app/Domain/Canteen/` — `Application/Infrastructure/
Http`, the same layered shape every prior Phase 10 module uses.

Canteen OWNS: `CanteenOutlet`, `CanteenItem`,
`CanteenItemInventoryRequirement`, `CanteenOrder`, `CanteenOrderLine`,
`CanteenBillingConfiguration`, `CanteenOrderStockConsumption` — seven
tables.

Canteen REFERENCES, never duplicates or owns:

- `Campus` (Academic Structure) — an Outlet MAY belong to one Campus.
- `InventoryLocation`/`InventoryItem` (Inventory) — an Outlet is
  backed by exactly one Location; a recipe requirement references an
  Item. Canteen never duplicates Location/Item name/code data, and
  never writes `inventory_stock_balances`/`stock_movements` directly
  — every stock effect goes through
  `InventoryStockService::issueMany()` (§5).
- `Student` (Students/SIS) — an Order references a Student by id.
  Canteen never duplicates Student name/enrollment data (its own
  audit/list projections carry only `student_id`, never a name — see
  §14).
- `LedgerAccount` (Finance, via `CanteenBillingConfigurationService`)
  — read-only, for billing-configuration validation and the settings
  UI's account picker. Never a write; mirrors the same
  cross-module "read-for-display" precedent
  `App\Http\Controllers\App\Finance\ChargeController::searchStudents()`
  and Hostel/Library already established.
- `charges` (Fees, via `ChargeService::assess()`) — Canteen never
  writes `charges`/`journal_entries`/`journal_lines` directly. See §9.

No bidirectional coupling: Students/SIS, Fees, and Inventory have zero
knowledge of Canteen. Canteen depends on all three; none of them
depend on Canteen. A repository grep confirms no
Students/AcademicStructure/Finance/Fees/Payments/Library/Transport/
Visitor/Hostel/HR module file references `App\Domain\Canteen` in
either direction.

## 3. Domain model

### CanteenOutlet (directory/reference entity)

A physical/logical canteen counter. `code`, `name`, `campus_id`
(nullable — a School-wide canteen has no single Campus, mirroring
`inventory_locations.campus_id`'s own nullability), `inventory_
location_id` (required, immutable after creation — no PATCH field
accepts it), `status`. **Campus-consistency structural enforcement**:
an Outlet naming both a Campus and an InventoryLocation must never
disagree about which Campus the Location itself belongs to — enforced
with TWO composite foreign keys (never a trigger): one plain
`(inventory_location_id, school_id)` FK (always enforced), and one
WIDER `(inventory_location_id, campus_id, school_id)` FK against
`inventory_locations(id, campus_id, school_id)` (a new composite
unique key this checkpoint added to `inventory_locations` — see §6).
PostgreSQL's standard multi-column FK NULL-skip semantics exempt a
`campus_id IS NULL` Outlet from the second FK entirely; a non-null
`campus_id` makes both FKs jointly satisfiable only when the named
Location's own `campus_id` matches. Proven directly at the raw-SQL
level (`CanteenOutletsRlsIsolationTest`'s campus-consistency test).

### CanteenItem (directory/reference entity)

A School-wide menu Item. `code`, `name`, `price` (the CURRENT sellable
price — see §8), `currency` (INR only), `status`.

### CanteenItemInventoryRequirement (the recipe)

How much of one InventoryItem one unit of a CanteenItem consumes.
`canteen_item_id`, `inventory_item_id`, `quantity_required` (NUMERIC
14,3, matching `inventory_stock_balances.quantity_on_hand`'s own
precision). `unique(school_id, canteen_item_id, inventory_item_id)` —
no duplicate recipe line for the same (Item, ingredient) pair.
**Evaluated at fulfillment time, never snapshotted at placement** —
see §8.

### CanteenBillingConfiguration (School-wide singleton)

Names the two `ledger_accounts` (`type='asset'` receivable,
`type='income'` revenue) fulfillment posts a Charge against.
`unique(school_id)`. See §9.

### CanteenOrder / CanteenOrderLine (transactional entities)

One Student purchase at one Outlet, and its line items. See §8, §10.

### CanteenOrderStockConsumption (link/reconciliation entity)

Proves which `stock_movements` rows a given Order's fulfillment
caused. See §12.

## 4. Security / data classification

Added three explicit rows to `docs/security/DATA-CLASSIFICATION.md`
(the "Data categories mapped to tiers" table), following the doc's own
stated tier criteria exactly as the Inventory row already does:

- **Canteen catalogue/recipe/outlet data** (Outlet directory, menu
  Item catalogue, recipe requirements) → **Confidential**. This is
  business/operational data, not personal data — every read requires
  a specific capability (`canteen.directory.view`), not merely an
  authenticated session, which is precisely what the document's own
  language distinguishes `Confidential` from `Internal` by (its own
  worked example: "internal financial summaries, vendor contract
  terms" — operational data). No Student/Guardian personal data
  appears anywhere in this tier's rows.
- **Canteen Order / Order line data** (which Student ordered what,
  when, for how much) → **Highly Sensitive**. This links an
  identifiable Student (Highly Sensitive per the existing "Children's
  data specifically" row — Students are minors) to a financial
  transaction (Highly Sensitive per the existing "Financial data"
  row) — the combination of a child's identity and a monetary amount
  is exactly the document's own "highest harm potential" criterion,
  not merely ordinary Student personal data. This is why
  `CanteenOrderController::index()`'s summary projection deliberately
  excludes every money field and the line array (§14) — the tier's
  "minimized default visibility" handling baseline applied literally.
- **CanteenBillingConfiguration** (which two LedgerAccounts Canteen
  fulfillment posts against) → **Highly Sensitive**, the same tier as
  the existing "Financial data" row (financial account/configuration
  data), not merely `Confidential` like the catalogue rows above —
  gated by its own `canteen.settings.view`/`.manage` capability pair,
  deliberately narrower than `canteen.directory.*`/`canteen.orders.*`
  (§15).

## 5. The `InventoryStockService::issueMany()` extension

**Additive, backward-compatible.** `receive()`/`issue()`/`transfer()`
are entirely unchanged — `issueMany()` is a new fourth public method,
not a refactor of the other three. Signature:
`issueMany(InventoryLocation $location, array $requirements, ?User
$actor = null): array` where `$requirements` is a list of
`App\Domain\Inventory\Application\IssueRequirement` (a small typed
`{inventoryItemId, quantity}` DTO, not a raw array) and the return
value is a list of the `StockMovement` rows created — one per
requirement, in the same order.

Contract, mirroring `issue()`'s own guarantees generalized to N items
against ONE Location in ONE atomic operation:

- Validates no duplicate `inventoryItemId` in the requirement set
  (`DuplicateInventoryItemRequirementException`), rejects an empty set
  (`EmptyIssueRequirementsException`), before any database work.
- Every Item must exist for this School and be active; the Location
  must be active — all checked before any lock is acquired.
- Resolves (creating if necessary, via the SAME concurrency-safe
  `ensureBalanceRow()` primitive `issue()`/`transfer()` already use)
  every involved balance row, THEN locks all of them together in
  **deterministic ascending-balance-id order**
  (`lockBalancesInDeterministicOrder()`) — never in the caller's
  requirement-array order, which would deadlock two concurrent
  `issueMany()` calls naming the same two Items in opposite array
  order. This generalizes `transfer()`'s own two-balance
  ascending-id lock order to N balances. Proven directly under real
  two-process concurrency:
  `InventoryStockConcurrencyTest::
  opposing_concurrent_issue_many_calls_for_the_same_two_items_in_opposite_order_do_not_deadlock`.
- Re-checks sufficiency for EVERY item after every lock is held (never
  a pre-lock check) — if any one item is insufficient, the ENTIRE
  operation throws `InsufficientStockException` and mutates NOTHING
  (proven: `InventoryStockServiceIssueManyTest::
  insufficient_stock_on_the_middle_item_of_three_mutates_nothing_even_though_the_others_would_have_succeeded`).
- On success, decrements every balance with exact BCMath arithmetic
  and inserts exactly one `issue`-type `StockMovement` per item, all
  inside ONE `DB::transaction()`, and audits one
  `inventory.stock.issued` event per movement (matching `issue()`'s
  own per-movement audit granularity).

`docs/modules/INVENTORY.md` §5.1 (added by this checkpoint, see below)
carries a short pointer to this section rather than duplicating the
full contract there.

## 6. Schema / database integrity

Seven tables, all `school_id` + `App\Support\Tenancy\BelongsToSchool`
+ UUIDv7 + `App\Support\Tenancy\TenantRls::enable()` (RLS enabled AND
forced) + `unique(id, school_id)`:

| Table | Notes |
|---|---|
| `canteen_outlets` | `unique(school_id, upper(code))`; two composite FKs on `inventory_location_id` (§3); `campus_id` FK nullable. |
| `canteen_items` | `unique(school_id, upper(code))`; `CHECK (price >= 0)`; `CHECK (currency = 'INR')`. |
| `canteen_item_inventory_requirements` | `unique(school_id, canteen_item_id, inventory_item_id)`; `CHECK (quantity_required > 0)`. |
| `canteen_billing_configurations` | `unique(school_id)` (singleton); composite FKs pin `(id, school_id, currency)` on BOTH ledger-account columns against `ledger_accounts`; `CHECK (receivable_ledger_account_id <> revenue_ledger_account_id)`; `CHECK (currency = 'INR')`. |
| `canteen_orders` | Three shape CHECKs tie `(status, fulfilled_at, cancelled_at, charge_id)` together (pending/fulfilled/cancelled each has an exact required shape); partial unique index `canteen_orders_charge_id_unique ON (charge_id) WHERE charge_id IS NOT NULL` — one Charge belongs to at most one Order; `TenantRls::revokeDelete()` (a pending Order legitimately transitions via UPDATE, but is never hard-deleted). |
| `canteen_order_lines` | `CHECK (line_total = unit_price * quantity)` — exact NUMERIC arithmetic, database-proven; `CHECK (quantity > 0)`; `CHECK (unit_price >= 0)`; `CHECK (currency = 'INR')`. No update/delete route exists anywhere (application-level immutability only, matching `stock_movements`' own precedent of not introducing a new trigger-function framework — see `docs/modules/INVENTORY.md` §16). |
| `canteen_order_stock_consumptions` | `unique(school_id, stock_movement_id)` (named `canteen_order_stock_consumptions_movement_unique`) — one Movement claimed by exactly one Order. Carries only `canteen_order_id`/`stock_movement_id`, never a duplicated `inventory_item_id`/`quantity`/`location_id` (`StockMovement` already owns those facts). |

A structural prerequisite this checkpoint also added to Inventory's
own table: `add_campus_composite_unique_to_inventory_locations_table`
adds a `(id, campus_id, school_id)` unique key to `inventory_
locations` — additive, no existing column/constraint changed — solely
so `canteen_outlets`' campus-consistency FK (§3) has something to
reference.

Composite FKs (School-equality enforced at the database level, never
RLS/SchoolScope alone), **all RESTRICT**:

| Child | Column(s) | Parent |
|---|---|---|
| `canteen_outlets` | `(campus_id, school_id)` | `campuses(id, school_id)` |
| `canteen_outlets` | `(inventory_location_id, school_id)` | `inventory_locations(id, school_id)` |
| `canteen_outlets` | `(inventory_location_id, campus_id, school_id)` | `inventory_locations(id, campus_id, school_id)` |
| `canteen_item_inventory_requirements` | `(canteen_item_id, school_id)` | `canteen_items(id, school_id)` |
| `canteen_item_inventory_requirements` | `(inventory_item_id, school_id)` | `inventory_items(id, school_id)` |
| `canteen_billing_configurations` | `(receivable_ledger_account_id, school_id, currency)` | `ledger_accounts(id, school_id, currency)` |
| `canteen_billing_configurations` | `(revenue_ledger_account_id, school_id, currency)` | `ledger_accounts(id, school_id, currency)` |
| `canteen_orders` | `(student_id, school_id)` | `students(id, school_id)` |
| `canteen_orders` | `(outlet_id, school_id)` | `canteen_outlets(id, school_id)` |
| `canteen_orders` | `(inventory_location_id, school_id)` | `inventory_locations(id, school_id)` |
| `canteen_orders` | `(charge_id, school_id)` | `charges(id, school_id)` |
| `canteen_order_lines` | `(order_id, school_id)` | `canteen_orders(id, school_id)` |
| `canteen_order_lines` | `(canteen_item_id, school_id)` | `canteen_items(id, school_id)` |
| `canteen_order_stock_consumptions` | `(canteen_order_id, school_id)` | `canteen_orders(id, school_id)` |
| `canteen_order_stock_consumptions` | `(stock_movement_id, school_id)` | `stock_movements(id, school_id)` |

Every FK/CHECK/unique-index above is proven at the raw-SQL level in
`tests/Feature/Postgres/Canteen{Outlets,Items,BillingConfigurations,
Orders,OrderLines,OrderStockConsumptions,ItemInventoryRequirements}
RlsIsolationTest.php` — one file per table, seven files.

## 7. Charge-at-fulfillment rationale (and the academic-year-resolution detail)

Fulfillment, not placement, is when a Charge is assessed
(`CanteenOrderService::fulfill()` calls `ChargeService::assess()` —
`place()` never touches Fees). This matches the product reality: an
Order can be cancelled before it is ever prepared/collected, and a
cancelled Order must never have created a financial obligation.

**Flagged prominently, as neither prior architecture gate anticipated
it**: `ChargeService::assess()` requires an `academicYearId` —
`AssessChargeData`'s constructor makes it a required argument, not
optional. Neither the original Canteen architecture-readiness gate nor
Inventory's own foundation checkpoint surfaced this dependency before
implementation began; it was discovered only once `fulfill()` was
actually wired against `ChargeService`. `CanteenOrderService::fulfill()`
resolves this by looking up the School's currently `active`
`AcademicYear` at fulfillment time
(`AcademicYear::query()->where('school_id', ...)->where('status',
'active')->first()`) and throwing
`CanteenNoActiveAcademicYearException` (422) if none exists — a School
that has not activated an Academic Year for the current cycle (Rule
64/65's one-active-per-School invariant, `docs/modules/
ACADEMIC-STRUCTURE.md`) cannot fulfill ANY Canteen order until it
does, even though placement itself never touches Academic Structure at
all. This is a genuine cross-module coupling `CanteenOrderService`
introduces at fulfillment time only — `place()` remains free of it.
Proven directly: `CanteenOrderFulfillmentTest::
missing_active_academic_year_blocks_fulfillment`.

## 8. Price / location snapshot semantics

`CanteenOrderService::place()` snapshots, at the moment of placement:

- `canteen_order_lines.unit_price`/`line_total` from the CanteenItem's
  `price` **at that instant** — a later menu price change never
  rewrites an already-placed Order's amounts (proven:
  `CanteenOrderPlacementTest::
  a_later_price_change_does_not_affect_an_already_placed_orders_snapshot`).
- `canteen_orders.inventory_location_id` from the Outlet's
  `inventory_location_id` **at that instant**.

The location snapshot is defense-in-depth over an already-structural
guarantee: `CanteenOutletController::update()` (both the App and API
transport layers) validates ONLY `name`/`status` — no field accepts
`inventory_location_id`, so an Outlet's backing Location is in fact
**immutable after creation** through every existing route. "Location
remapping after placement" is therefore impossible by construction,
not merely prevented by the snapshot; the snapshot column exists
anyway as a second, independent line of defense should a future
checkpoint ever add an Outlet-relocation feature. Proven at fulfillment
time regardless: `CanteenOrderFulfillmentTest::
an_inactive_snapshotted_location_blocks_fulfillment` demonstrates
fulfillment consults the Order's own stored `inventory_location_id`,
not a re-derived value from the Outlet.

**Recipe is the deliberate exception** — see §3/§13: the recipe is
evaluated at FULFILLMENT time, never snapshotted at placement, because
a recipe correction between placement and fulfillment (e.g. fixing a
data-entry error in how much flour a samosa requires) should apply to
every not-yet-fulfilled Order, not be permanently frozen at whatever
the recipe happened to say at placement time. This is a deliberate,
documented asymmetry with price/location, not an oversight — proven:
`CanteenOrderFulfillmentTest::
fulfillment_uses_the_recipe_current_at_fulfillment_time_not_at_placement_time`.

## 9. Billing configuration + ledger account validation

`CanteenBillingConfigurationService::validateAccounts()` is the SINGLE
place account validation lives, called both eagerly at save time
(`configure()`, for UX) and authoritatively at fulfillment time
(`resolveValidated()`, since an account's status can change after the
configuration was saved — a stale save-time check is never trusted
alone). Every rule enforced server-side, both save-time and
fulfillment-time:

1. Receivable and revenue account ids must differ (`CANTEEN_BILLING_
   ACCOUNT_INVALID`; also a database CHECK,
   `canteen_billing_config_distinct_accounts_check`, as a backstop
   against a raw insert).
2. Both accounts must exist for THIS School (`LedgerAccount::query()->
   where('school_id', $school->id)->find(...)`, never a bare `find()`
   — same-School by construction, not merely by convention).
3. Both accounts must be `active`.
4. The receivable account's `LedgerAccount.type` must be exactly
   `'asset'`; the revenue account's must be exactly `'income'` — the
   only two `type` values this module ever accepts (`ledger_accounts.
   type` itself is one of `asset|liability|equity|income|expense`,
   docs/modules/FINANCE.md).

Proven directly: `CanteenBillingConfigurationTest`'s
`a_receivable_account_from_a_different_school_is_rejected`,
`an_inactive_account_is_rejected`,
`a_receivable_account_of_the_wrong_type_is_rejected`,
`a_revenue_account_of_the_wrong_type_is_rejected`,
`identical_receivable_and_revenue_accounts_are_rejected`, and
`resolve_validated_throws_when_a_previously_valid_account_has_since_gone_inactive`
(the save-time-vs-fulfillment-time re-check distinction).

The settings UI's ledger-account picker
(`CanteenBillingConfigurationController::ledgerAccounts()`) reads
`LedgerAccount` directly, read-only, scoped by `school_id` — the same
established "read-for-display" precedent as everywhere else in this
module — and is itself gated by `canteen.settings.manage` (not merely
`.view`). `LedgerAccount` also uses `App\Support\Tenancy\
BelongsToSchool` (SchoolScope + RLS), so cross-School leakage is
prevented structurally even before the endpoint's own explicit
`school_id` filter; proven at the HTTP boundary as defense-in-depth
evidence by
`CanteenOrderApiTest::the_ledger_account_lookup_never_returns_another_schools_accounts`
(added during this phase's security review, §17).

## 10. The fulfillment transaction sequence

`CanteenOrderService::fulfill()`, entirely inside ONE outer
`DB::transaction()`:

1. `lockForUpdate()` the ONE `canteen_orders` row; reject if already
   fulfilled/cancelled.
2. Resolve and validate the billing configuration
   (`resolveValidated()`, §9).
3. Resolve the School's active AcademicYear (§7); reject if none.
4. Lock the involved `canteen_items` rows in **deterministic ascending
   id order** (never Order-line insertion order) — this is what makes
   a concurrent recipe mutation and this fulfillment serialize
   cleanly rather than tear (§13, §3's recipe-evaluation timing).
5. Read the CURRENT recipe for those Items, aggregate the total
   InventoryItem quantity needed across all lines (summing overlapping
   ingredients via `bcadd`, never floats).
6. If any ingredient is needed, call
   `InventoryStockService::issueMany()` (§5) against the Order's
   snapshotted `inventory_location_id` — this becomes a PostgreSQL
   SAVEPOINT nested inside the outer transaction (Laravel's automatic
   nested-transaction behavior).
7. Call `ChargeService::assess()` for the Order's frozen `total_amount`
   against the billing configuration's two accounts — also a nested
   SAVEPOINT.
8. Create one `CanteenOrderStockConsumption` row per `StockMovement`
   `issueMany()` returned, linking each by id (§12).
9. Update the Order to `fulfilled`, set `fulfilled_at`/`charge_id`.
10. Audit `canteen.order.fulfilled`.

A failure at ANY step — including a Charge-assessment failure AFTER
Inventory has already been decremented in the same transaction — rolls
back the ENTIRE outer transaction: the Order stays `pending`, no
Charge exists, no stock movement exists, no consumption row exists.
Proven directly with a real trigger-forced mid-transaction failure:
`CanteenOrderFulfillmentAtomicityTest::
a_charge_insert_failure_after_inventory_has_already_been_decremented_rolls_back_everything`.

## 11. Cancellation rules

`CanteenOrderService::cancel()` is available ONLY for a `pending`
Order — the three CHECK constraints on `canteen_orders` (§6) make a
`fulfilled`-then-`cancelled` (or vice versa) state transition a
database-level impossibility, not merely an Application-layer
convention. Cancelling never touches Inventory or Fees (nothing was
ever committed to either at placement time). No financial
reversal/refund semantics exist for Canteen at all — a fulfilled
Order's Charge follows Fees' own cancellation/adjustment rules,
entirely outside this module's endpoints (§16).

## 12. Consumption-link schema + reconciliation invariant

`canteen_order_stock_consumptions` proves, after the fact, which
`stock_movements` rows one Order's fulfillment caused — necessary
because `issueMany()` (§5) is a generic Inventory primitive with no
knowledge of Canteen Orders at all. `CanteenOrderService::fulfill()`
is the ONLY writer, and every row it inserts is built exclusively from
the `StockMovement` objects `issueMany()` itself just returned — never
from any caller-supplied `stock_movement_id`. Proven by a dedicated
architecture guard, not merely asserted:
`CanteenArchitectureGuardTest::no_controller_accepts_a_caller_supplied_stock_movement_id`
(greps every Canteen controller file for the literal string) and
`::canteen_order_service_builds_consumption_rows_only_from_issue_many_return_values`.
The reconciliation invariant this enables: for any fulfilled Order,
summing its linked Movements' quantities per InventoryItem exactly
equals that Order's aggregated recipe requirement at the moment it was
fulfilled — a property that follows directly from step 6/8 of §10's
sequence always running inside the same transaction, not independently
tested by re-derivation (unlike Inventory's own §5 reconciliation
test, which does re-derive balances from movement history — Canteen's
consumption link is a provenance/audit table, not a second balance
source).

## 13. The four concurrency scenarios

All proven under REAL two-process concurrency (separate OS processes,
not a sequential simulation), in `CanteenOrderConcurrencyTest`:

- **A. Double fulfillment**: two concurrent `fulfill()` calls against
  the SAME pending Order — exactly one succeeds, the loser sees
  `CanteenOrderAlreadyFulfilledException`, exactly one Charge and one
  set of stock movements exist
  (`two_real_concurrent_processes_fulfilling_the_same_order_succeed_exactly_once`).
- **B. Two Orders racing scarce shared stock**: two different Orders,
  both needing the same scarce ingredient, fulfilled concurrently —
  exactly one succeeds, stock never goes negative
  (`two_orders_racing_scarce_shared_stock_leave_exactly_one_fulfilled_and_stock_never_negative`).
- **C. Cancel-vs-fulfill race**: `cancel()` and `fulfill()` racing the
  SAME pending Order — exactly one terminal state results, never both
  (`cancel_and_fulfill_racing_the_same_order_produce_exactly_one_terminal_state`).
- **D. Recipe-mutation-vs-fulfillment torn read**: `CanteenRecipeService::
  setRequirement()`/`removeRequirement()` (which itself locks the
  owning `canteen_items` row before writing, §3) racing a concurrent
  `fulfill()` reading that same Item's recipe — never a torn read;
  exactly one `StockMovement` results, computed from either the
  pre-edit or post-edit recipe in full, never a mix
  (`a_concurrent_recipe_mutation_and_fulfillment_never_produce_a_torn_read`).

A fifth, Inventory-owned scenario Canteen's fulfillment path depends
on: **opposing concurrent `issueMany()` calls deadlock-freedom** — see
§5, proven in `InventoryStockConcurrencyTest` (Inventory's own test
suite, re-run as part of this phase's regression, §18).

## 14. Highly Sensitive data handling + list/detail projection split

Both transport layers (`App\Domain\Canteen\Http\Controllers\
CanteenOrderController` for `/api/v1`, `App\Http\Controllers\App\
Canteen\CanteenOrderController` for the session-authenticated Inertia
UI) enforce the IDENTICAL projection discipline:
`index()`/`presentSummary()` returns ONLY `id`/`status`/`studentId`/
`outletId`/`placedAt`/`fulfilledAt`/`cancelledAt` — **never**
`unitPrice`/`lineTotal`/`totalAmount`/`currency`/`lines` (§4's Highly
Sensitive tier, "minimized default visibility"). Only `show()`/
`presentDetail()`, under the SAME `canteen.orders.view` capability,
includes every money field and every line — a deliberate
transport-layer minimization, not an authorization difference. Proven
at the actual HTTP boundary (not merely by reading controller source),
both layers:
`CanteenAdminUiTest::orders_index_never_leaks_money_fields_while_show_does_include_them`
and `CanteenOrderApiTest::the_list_endpoint_never_includes_money_fields`
/ `::the_detail_endpoint_includes_every_money_field`.

Audit metadata (10 call sites across `CanteenOutletService`,
`CanteenItemService`, `CanteenBillingConfigurationService`,
`CanteenOrderService`, `CanteenRecipeService`) was spot-checked in
full for this phase's security review: every single metadata array
carries only ids/codes/statuses/prices/quantities/counts/currency —
never a Student/Guardian name, never any free text. See §17.

## 15. Capabilities

Three independently gateable areas, extending Inventory's own
directory/stock split with a THIRD pair for financial configuration:

- `canteen.directory.view`/`.manage` — the Outlet/Item/recipe
  catalogue (reference/structural entities, the same "no capability
  per sub-entity" reasoning as Inventory's combined Item/Location
  pair).
- `canteen.orders.view`/`.manage` — the Order lifecycle
  (place/fulfill/cancel), deliberately a SEPARATE pair from
  `.directory.*`: running the front counter is a distinct day-to-day
  concern from curating the menu/recipe. `canteen.orders.manage`
  ALONE is sufficient to place/fulfill/cancel — `CanteenOrderService::
  fulfill()` calls `InventoryStockService::issueMany()`/
  `ChargeService::assess()` directly with no internal capability
  re-check, matching the established Fees→Finance/Payments→Fees
  orchestration-boundary precedent.
- `canteen.settings.view`/`.manage` — the billing configuration
  (which two `ledger_accounts` fulfillment posts against).
  Deliberately NOT granted to Principal by default, mirroring
  `finance.charges.*`'s own precedent exactly: financial account
  configuration is School-Admin-only by default, unlike the
  day-to-day directory/orders pairs.

**Default role grants** (`CapabilityAndRoleSeeder`):

| Role | `canteen.directory.*` | `canteen.orders.*` | `canteen.settings.*` |
|---|---|---|---|
| `school_admin` | view + manage | view + manage | view + manage |
| `principal` | view + manage | view + manage | *(neither)* |

No dedicated "Canteen Staff"/"Cashier" system role was created — a
School wanting narrower staff can already compose a custom role from
these six capabilities.

Proven: `tests/Feature/Authorization/CanteenCapabilityTest.php`
(catalog integrity, no speculative capabilities, default role grants,
area independence, tenant isolation, central-identity-alone denial,
existing grants unaffected) plus the anti-P1 helper-search tests in
`CanteenAdminUiTest`/`CanteenOrderApiTest` (§17).

## 16. Explicit non-scope (deferred, not forgotten)

- Wallets, prepaid balances, stored-value accounts — every Order is
  billed as a Charge; there is no Canteen-specific payment/balance
  concept anywhere in this module (confirmed by a repository-wide grep
  across the entire Canteen tree — zero matches for `wallet`/
  `prepaid`).
- Dietary restrictions, allergens, medical/health data — zero fields
  anywhere in the Canteen schema, services, or UI forms (confirmed by
  grep — zero matches for `dietary`/`allerg`/`medical`/`health`).
- Free-text notes on an Outlet, Item, or Order — no such field exists
  anywhere in the schema or forms (confirmed by grep — zero matches
  for a `notes` field).
- Order-line/price mutation after placement, refunds, financial
  reversal of a fulfilled Order's Charge — Fees owns any future
  reversal/adjustment concept; this module never implements one
  itself (§11).
- Guardian/Student-facing self-service ordering (kiosk, mobile,
  parent portal) — only an administrative/staff-facing UI exists.
- Multi-currency (currency is pinned to INR by CHECK constraint
  throughout, matching every other Phase 0G+ monetary column in this
  repository).
- Procurement/supplier integration for canteen ingredients — Inventory
  itself has no procurement concept yet either (`docs/modules/
  INVENTORY.md` §25).
- Recipe versioning/history — a recipe requirement row is mutated
  in-place (`updateOrCreate`); no historical snapshot of "what the
  recipe used to require" is kept (this is why fulfillment always uses
  the CURRENT recipe, §8, rather than a versioned one).
- Barcode/POS hardware integration, offline mode.
- Analytics dashboards (popular Items, revenue trends), AI-assisted
  menu/pricing suggestions.
- Documents/Communications integration — no receipt PDF, no
  order-confirmation notification.

## 17. Security review

Every checklist item this phase's own review required, checked and
either fixed-with-regression-test or confirmed non-issue with pointed
evidence:

- **Cross-School IDOR**: every Outlet/Item/recipe/billing-config/Order
  lookup 404s (or is rejected) on a cross-School id —
  `CanteenAdminUiTest::a_wrong_school_item_id_is_not_found_via_the_ui`,
  `CanteenOrderApiTest`'s dedicated "Cross-School rejection (IDOR)"
  section, and every `Canteen*RlsIsolationTest` file's own composite-FK
  cross-School-reference tests.
- **RLS escape**: proven at the raw-SQL level for all seven tables —
  §6, one dedicated test file per table.
- **Helper-search leakage — THE confirmed real bug, fixed this
  phase**: `Outlets/Create.vue`'s InventoryLocation picker and
  `Items/Show.vue`'s recipe InventoryItem picker previously called
  Inventory's `inventory.stock.manage`-gated search endpoints. Fixed
  by two new, narrow, read-only, same-School Canteen-side endpoints
  (`GET /app/canteen-outlets/search/inventory-locations`, `GET
  /app/canteen-items/search/inventory-items`), gated by
  `canteen.directory.manage` — the same capability that already gates
  the write actions these pickers support. Proven: `CanteenAdminUiTest::
  a_member_without_directory_manage_cannot_use_the_canteen_inventory_search_endpoints`
  and `::a_directory_manage_member_without_inventory_stock_manage_can_use_the_canteen_inventory_search_endpoints`
  (before-query-execution capability check, matching the established
  anti-P1 pattern). The pre-existing Order-placement Student/Item
  search endpoints were re-verified unaffected and still
  capability-gated correctly (`CanteenAdminUiTest::
  a_member_without_orders_manage_cannot_use_the_order_search_endpoints`,
  `CanteenOrderApiTest`'s "Helper search anti-P1" section). Inventory's
  own `/app/inventory-stock/search/*` endpoints are confirmed
  unmodified and their own tests (`InventoryAdminUiTest`) still pass.
- **Highly Sensitive broad-list leakage**: §14 — re-verified across
  BOTH transport layers, not just one.
- **Billing settings leakage**: §9 — capability-gated, cross-School
  ledger-account leakage re-verified with a new dedicated test
  (`CanteenOrderApiTest::the_ledger_account_lookup_never_returns_another_schools_accounts`).
- **Arbitrary ledger-account selection / wrong account type**: §9 —
  both save-time and fulfillment-time validation, each proven with a
  wrong-type and a wrong-School account test.
- **Recipe torn-read race / recipe lock deadlock**: §13 scenario D.
- **Multi-ingredient Inventory deadlock**: §5,
  `InventoryStockConcurrencyTest::
  opposing_concurrent_issue_many_calls_for_the_same_two_items_in_opposite_order_do_not_deadlock`.
- **Double fulfillment**: §13 scenario A.
- **Duplicate Charge**: `canteen_orders_charge_id_unique` partial
  index (§6) — proven with a direct raw-SQL race:
  `CanteenOrdersRlsIsolationTest::
  the_database_rejects_a_second_order_referencing_the_same_charge`.
- **Duplicate stock consumption**: `canteen_order_stock_consumptions_
  movement_unique` (§6) — proven:
  `CanteenOrderStockConsumptionsRlsIsolationTest::
  the_database_rejects_a_second_consumption_row_for_the_same_movement`.
- **Cancel/fulfill race**: §13 scenario C.
- **Location remapping after placement**: §8 — structurally impossible
  (no route accepts the field at all), plus the snapshot as
  defense-in-depth.
- **Inventory single-writer bypass**: zero matches for a direct write
  to `inventory_stock_balances`/`stock_movements` anywhere in
  `app/Domain/Canteen`/`app/Http/Controllers/App/Canteen` (repository
  grep).
- **Fees direct-write bypass**: zero matches for a direct
  `Charge::query()->create`/`JournalEntry::query()->create`/
  `JournalLine::query()->create` anywhere in the Canteen tree — the
  only Fees call is `ChargeService::assess()` (§10 step 7).
- **Payment/wallet scope creep**: §16 — zero matches.
- **Price mutation/history corruption / order-line mutation**: proven
  absent by `CanteenArchitectureGuardTest` — no update/delete route
  for `canteen_order_lines` exists anywhere, `CanteenOrderLine.php`
  defines no `update`/`delete`/`SoftDeletes`, and `CanteenOrderService`
  never calls `->update(`/`->delete(`/`->save(` on a previously-created
  line.
- **Consumption-link caller control**: §12 — proven absent by
  `CanteenArchitectureGuardTest::no_controller_accepts_a_caller_supplied_stock_movement_id`.
- **Audit leakage**: §14 — all 10 audit call sites spot-checked, zero
  PII/free text.
- **Unrestricted notes / dietary/Health data creep**: §16 — zero
  matches by repository grep.

No P0/P1 findings remain open. The one confirmed real bug (helper-
search capability coupling) is fixed with regression tests; every
other checklist item is a confirmed non-issue with a pointed test/file
reference, not a bare assertion.

## 18. Test evidence

- Postgres/RLS: 7 files, one per table (`tests/Feature/Postgres/
  Canteen{Outlets,Items,BillingConfigurations,Orders,OrderLines,
  OrderStockConsumptions,ItemInventoryRequirements}RlsIsolationTest.php`),
  plus `CanteenHistoricalNonDeletionTest.php`.
- Real two-process concurrency: 4 scenarios
  (`CanteenOrderConcurrencyTest`) — §13.
- Atomicity (mid-transaction rollback proof):
  `CanteenOrderFulfillmentAtomicityTest`.
- Catalogue/directory: `CanteenCatalogueTest`.
- Placement: `CanteenOrderPlacementTest`.
- Fulfillment: `CanteenOrderFulfillmentTest`.
- Cancellation: `CanteenOrderCancellationTest`.
- Billing configuration: `CanteenBillingConfigurationTest`.
- Architecture guards (append-only/no-caller-supplied-id proofs):
  `CanteenArchitectureGuardTest`.
- Authorization: `tests/Feature/Authorization/CanteenCapabilityTest.php`.
- Full `/api/v1` surface + IDOR + idempotency + Highly Sensitive
  projection + anti-P1 helper search: `CanteenOrderApiTest`.
- Admin Inertia UI + anti-P1 search-endpoint regressions (including
  this phase's two new capability-boundary tests):
  `tests/Feature/App/CanteenAdminUiTest.php`.

Total: **171 tests, 570 assertions**, all passing, zero skipped, in
the Canteen-filtered run (this includes the 3 new tests this phase
added: 2 authorization tests for the fixed search endpoints, 1
cross-School ledger-account-lookup test). Inventory's own suite
(re-run for regression, unmodified by this phase except the additive
`issueMany()` extension): **126 tests, 357 assertions**, zero
failures. See the Phase 10F closure report for the full cross-domain
and full-platform regression counts.

## 19. Events / Documents / Communications decision

**Events**: zero Canteen domain events in this checkpoint — the same
"no speculative event" discipline every prior Phase 10 module applied.
No downstream consumer contract currently needs one.

**Documents**: no changes — no receipt/invoice attachments in scope.

**Communications**: no changes — no order-confirmation notification,
no low-balance alert (there is no balance concept, §16).

## 20. API surface

`/api/v1/schools/{schoolId}/canteen-outlets[...]`,
`/canteen-items[...]` (including nested `/recipe[...]`),
`/canteen-billing-configuration[...]`, and `/canteen-orders[...]`
(including `/fulfill`, `/cancel`). GET endpoints authorize inside the
controller; mutating routes carry `capability:`/
`throttle:school-api-mutations`; `POST .../canteen-orders` and `POST
.../canteen-orders/{id}/fulfill` additionally carry `idempotent` (§21).

The live-search picker endpoints (`.../canteen-orders/search/students`,
`.../canteen-orders/search/items`, `.../canteen-billing-configuration/
ledger-accounts`, and this phase's new `.../canteen-outlets/search/
inventory-locations`, `.../canteen-items/search/inventory-items`) are
deliberately NOT part of the documented OpenAPI contract — they are
UI-support lookups for the admin picker widgets, the same undocumented
status every other module's equivalent picker endpoint already has
across this repository's contract (no module documents its own
`search/*` helper as a public API operation).

OpenAPI (`packages/contracts/openapi/school-os-api.yaml`) now
documents the full core CRUD/lifecycle surface: 11 paths, 20
operations, 15 new schemas, 5 new path parameters — added by this
phase (the backend/UI phases had built the routes but never the
contract entries). `packages/shared-types` was regenerated via `npm
run generate`, verified deterministic (identical output on a second
run).

## 21. Administrative UI

Session-authenticated Inertia pages under `/app/canteen-outlets`,
`/app/canteen-items`, `/app/canteen-settings`, `/app/canteen-orders`:
Outlet/Item directories (list/search/create/update/activate-
deactivate), Item detail with recipe composition, a billing-settings
page, and an Order placement/list/detail/fulfill/cancel flow. Reuses
the existing `EmptyState`/`Pagination`/`StatusBadge` components and
layout conventions verbatim. `Outlets/Index.vue` now also displays
each Outlet's Campus name and backing Inventory Location code/name
(a phase-10F-fix additive read-query projection — the underlying App
controller's `index()` previously returned only `id`/`code`/`name`/
`status`).

## 22. Audit

Every significant state change is recorded via the existing
`App\Support\Audit\AuditRecorder::school()`: `canteen.outlet.created`,
`canteen.outlet.updated`, `canteen.item.created`, `canteen.item.
updated`, `canteen.recipe.requirement_set`, `canteen.recipe.
requirement_removed`, `canteen.billing_configuration.saved`,
`canteen.order.placed`, `canteen.order.fulfilled`, `canteen.order.
cancelled`. Metadata carries ids/codes/statuses/prices/quantities/
counts/currency only — never a Student/Guardian name, never any free
text (§14, §17).

## 23. Idempotency decisions

**Place / fulfill**: both carry the `idempotent` middleware — placing
creates a new immutable Order+lines; fulfilling issues stock and
assesses a Charge, exactly the "consequential retryable mutation"
shape rule 29 targets. Proven:
`CanteenOrderApiTest`'s idempotency tests for both operations
(identical replay never double-creates an Order, never double-issues
stock, never double-assesses a Charge — the replayed fulfillment
response returns the SAME `chargeId`).

**Cancel** deliberately does NOT carry `idempotent` — cancelling an
already-cancelled Order is rejected outright
(`CANTEEN_ORDER_ALREADY_CANCELLED`, 409), which is itself a safe,
side-effect-free response to a retry (no double-cancellation semantics
exist to protect against), matching the same reasoning `docs/modules/
INVENTORY.md`/other modules apply to state-check-only mutations.

No new idempotency machinery — `App\Support\Idempotency\
IdempotencyGuard`/`App\Http\Middleware\EnsureIdempotent` are reused
exactly as-is.

## 24. Finance / Inventory boundary respected

Canteen never writes `inventory_stock_balances`/`stock_movements`
directly (§5, §17) and never writes `charges`/`journal_entries`/
`journal_lines` directly (§10, §17) — every effect on either sibling
module goes through that module's own Application-layer service
(`InventoryStockService::issueMany()`, `ChargeService::assess()`),
exactly matching this module's own architectural precedent (`CanteenBillingConfigurationService`
reading `LedgerAccount` directly, read-only, for display) and the
repository-wide "no bidirectional coupling" rule. Neither Inventory
nor Fees gained any dependency on Canteen — a repository grep confirms
neither module's file tree references `App\Domain\Canteen` in any
direction.

## 25. Tests & security review summary

See §18 for the full test-file inventory and §17 for the itemized
security-review checklist. The Phase 10F closure report (delivered
alongside this document, not committed to the repository) additionally
records: exact quality-gate results (Pint/PHPStan/ESLint/Prettier/
vue-tsc/vite build — all clean), full cross-domain regression counts
(Students/Finance/Fees/Payments/Tenancy/Authorization/RLS/Audit/
Idempotency/Library/Transport/Visitor/Hostel — 1569 tests, 4153
assertions, zero failures), and full-platform default/random-order
regression counts with independent re-verification that the
pre-existing Communications/Events/Webhooks/Announcement-UI failure
cluster is unrelated to Canteen (confirmed to fail identically with
zero Canteen files touched, in an isolated Webhooks/Events-only run).
