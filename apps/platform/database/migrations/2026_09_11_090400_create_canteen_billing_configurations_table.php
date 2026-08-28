<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10F -- a School-wide singleton naming the two `ledger_accounts`
     * rows (`type = 'asset'` receivable, `type = 'income'` revenue --
     * validated at the Application layer, `App\Domain\Canteen\Application\CanteenOrderService::fulfill()`,
     * NOT by a database CHECK: `ledger_accounts.type` is a plain string
     * column with its own repository-wide CHECK, this table has no
     * business inventing a second opinion on account-type semantics)
     * `ChargeService::assess()` posts against at Order fulfillment.
     * `unique(school_id)` -- exactly one row per School, mirroring the
     * "singleton configuration row" shape already established elsewhere
     * in this repository (e.g. `school_settings`).
     *
     * Composite foreign keys pin `currency` too, matching
     * `charges_receivable_account_fk`/`charges_revenue_account_fk`'s
     * exact precedent -- `ledger_accounts` only offers a
     * `(id, school_id, currency)` unique key, not a plain
     * `(id, school_id)` one, so this is the only way to structurally
     * prove same-School AND same-currency ownership.
     * `canteen_billing_configurations_distinct_accounts_check` mirrors
     * `charges_distinct_accounts_check` exactly -- a configuration
     * naming the SAME account for both sides would post a
     * self-cancelling, structurally nonsensical journal entry.
     */
    public function up(): void
    {
        Schema::create('canteen_billing_configurations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('receivable_ledger_account_id');
            $table->uuid('revenue_ledger_account_id');
            $table->char('currency', 3);
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique('school_id');

            $table->foreign(['receivable_ledger_account_id', 'school_id', 'currency'], 'canteen_billing_config_receivable_fk')
                ->references(['id', 'school_id', 'currency'])->on('ledger_accounts')
                ->restrictOnDelete();

            $table->foreign(['revenue_ledger_account_id', 'school_id', 'currency'], 'canteen_billing_config_revenue_fk')
                ->references(['id', 'school_id', 'currency'])->on('ledger_accounts')
                ->restrictOnDelete();
        });

        DB::statement("ALTER TABLE canteen_billing_configurations ADD CONSTRAINT canteen_billing_config_currency_inr_only_check CHECK (currency = 'INR')");
        DB::statement('ALTER TABLE canteen_billing_configurations ADD CONSTRAINT canteen_billing_config_distinct_accounts_check CHECK (receivable_ledger_account_id <> revenue_ledger_account_id)');

        TenantRls::enable('canteen_billing_configurations');
    }

    public function down(): void
    {
        TenantRls::disable('canteen_billing_configurations');
        Schema::dropIfExists('canteen_billing_configurations');
    }
};
