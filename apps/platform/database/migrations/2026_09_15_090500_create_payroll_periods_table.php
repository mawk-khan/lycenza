<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 9.1 (ADR 0032 "Monthly period model") -- `period_month`
     * (normalized to the first of the month) is the true identity;
     * `starts_on`/`ends_on` are derived and CHECK-validated against
     * it, never independently arbitrary. `UNIQUE(school_id,
     * period_month)` makes overlap impossible BY CONSTRUCTION -- two
     * distinct calendar months can never overlap -- with no exclusion
     * constraint or Postgres extension of any kind (contrast with
     * `employee_compensation_assignments`, whose overlap genuinely
     * needs a range check because its ranges are arbitrary; a
     * calendar-month period does not).
     *
     * Monthly only for Phase 9 -- no configurable-frequency calendar
     * engine (rule 2). `payment_date` is independent of the covered
     * month (a School may pay on the 1st of the following month).
     *
     * Editing `period_month`/`starts_on`/`ends_on` once any
     * `payroll_runs` row references this period is forbidden at the
     * Application layer (Checkpoint 9.3) -- not database-enforced
     * here, since `payroll_runs` does not exist yet at this migration
     * and the "period is locked once used" rule is a soft workflow
     * invariant, not a financial-correctness one on the scale of the
     * ones given a trigger elsewhere in this checkpoint.
     */
    public function up(): void
    {
        Schema::create('payroll_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->date('period_month');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->date('payment_date')->nullable();
            $table->string('status')->default('draft'); // draft|open|closed
            $table->timestamps();

            $table->unique(['school_id', 'period_month']);
            $table->unique(['id', 'school_id']);
            $table->index('school_id');
        });

        DB::statement("ALTER TABLE payroll_periods ADD CONSTRAINT payroll_periods_status_check CHECK (status IN ('draft', 'open', 'closed'))");

        DB::statement(<<<'SQL'
            ALTER TABLE payroll_periods ADD CONSTRAINT payroll_periods_month_shape_check CHECK (
                EXTRACT(DAY FROM period_month) = 1
                AND starts_on = period_month
                AND ends_on = (period_month + INTERVAL '1 month - 1 day')::date
            )
        SQL);

        DB::statement('ALTER TABLE payroll_periods ADD CONSTRAINT payroll_periods_payment_date_check CHECK (payment_date IS NULL OR payment_date >= ends_on)');

        TenantRls::enable('payroll_periods');
    }

    public function down(): void
    {
        TenantRls::disable('payroll_periods');
        Schema::dropIfExists('payroll_periods');
    }
};
