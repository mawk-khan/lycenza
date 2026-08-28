<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0G.1 (ADR 0030, docs/modules/FINANCE.md "Account model"):
     * the chart of accounts every future journal_line posts against.
     * System-managed only in 0G.1 -- `is_system` marks a row as
     * non-deletable/non-renameable via any future School-facing UI;
     * real School-level chart-of-accounts customization is deferred
     * (ADR 0030 "Alternatives considered" #3).
     *
     * `type` uses ADR 0030's fixed five-category taxonomy (asset/
     * liability/equity/income/expense) -- deliberately "income", not
     * "revenue", matching FINANCE.md's exact vocabulary.
     *
     * `unique(['id', 'school_id', 'currency'])` exists solely so
     * journal_lines can declare a composite foreign key pinning BOTH
     * its School AND its currency to this row's -- the "strong
     * pattern" docs/modules/FINANCE.md's currency-consistency section
     * calls for: the database, not just application code, proves a
     * journal line can never reference an account from a different
     * School or a different currency than what the line itself
     * declares. Same structural idea as webhook_endpoints'
     * `unique(['id', 'school_id'])`, extended with currency.
     *
     * Currency scope (0G.1 correction): FINANCE.md's "Money" section
     * says "today: INR only" and its "Currency" section says "even
     * though INR is the only currency in scope today" -- this is a
     * stronger, more literal statement than mere
     * single-currency-per-School; it is Phase 0G is INR-only, globally,
     * for every School. `ledger_accounts_currency_inr_only_check`
     * enforces exactly that (`currency = 'INR'`), not merely an
     * ISO-4217-shaped format. The explicit `currency` column is still
     * retained (per FINANCE.md's own "never inferred... hardcoded
     * assumption" rule) -- this CHECK bounds its allowed VALUE for
     * Phase 0G, it does not remove the column. A future multi-currency
     * checkpoint loosens this CHECK via its own migration + ADR
     * (FINANCE.md "Multi-currency: out of scope for Phase 0G" already
     * anticipates this), it is not silently widened here.
     *
     * `status` (active/inactive, never deleted once it may have
     * postings against it) follows rule 73's reference-entity-
     * lifecycle pattern, same as GradeLevel/Subject/Documents.
     *
     * No `balance` column anywhere on this table -- ADR 0030 rejects a
     * canonical mutable balance outright; balances are always derived
     * from journal_lines postings.
     *
     * Account-code uniqueness (0G.1 correction): the plain
     * `unique(['school_id', 'code'])` Laravel would otherwise generate
     * only protects against a LITERAL duplicate string -- since
     * `App\Support\NormalizesCode` uppercases `code` at the Eloquent
     * mutator layer, a raw SQL insert bypassing that mutator (e.g.
     * lowercase 'cash' after 'CASH' already exists) would NOT collide
     * with it, unlike GradeLevel/Subject's identical-looking pattern
     * elsewhere in this repository (see `NormalizesCode`'s own
     * docblock, which explicitly relies on "every writer normalizes"
     * -- an assumption a financial reference invariant cannot safely
     * make). `ledger_accounts_school_id_code_ci_unique` is a
     * PostgreSQL expression unique index on `(school_id, upper(code))`
     * instead -- true case-insensitive uniqueness enforced by the
     * database itself, immune to any caller bypassing the model layer.
     */
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('type'); // asset|liability|equity|income|expense -- see CHECK below
            $table->char('currency', 3);
            $table->boolean('is_system')->default(false);
            $table->string('status')->default('active'); // active|inactive -- see CHECK below
            $table->timestamps();

            $table->unique(['id', 'school_id', 'currency']);
            $table->index('school_id');
        });

        DB::statement(
            'ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_type_check '.
            "CHECK (type IN ('asset', 'liability', 'equity', 'income', 'expense'))"
        );

        DB::statement(
            'ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_status_check '.
            "CHECK (status IN ('active', 'inactive'))"
        );

        DB::statement(
            'ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_currency_inr_only_check '.
            "CHECK (currency = 'INR')"
        );

        // Case-insensitive account-code uniqueness, enforced by the
        // database itself (not merely the NormalizesCode model
        // mutator) -- see this migration's docblock.
        DB::statement(
            'CREATE UNIQUE INDEX ledger_accounts_school_id_code_ci_unique '.
            'ON ledger_accounts (school_id, upper(code))'
        );

        TenantRls::enable('ledger_accounts');
    }

    public function down(): void
    {
        TenantRls::disable('ledger_accounts');
        Schema::dropIfExists('ledger_accounts');
    }
};
