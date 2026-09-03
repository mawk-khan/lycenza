<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Checkpoint 9.6C (ADR 0036 correction addendum §1.8) -- one row
     * per EmploymentRecord per contribution period (Apr-Sep / Oct-
     * Mar), storing the coverage decision made ONCE at period start
     * -- never re-evaluated mid-period, matching
     * `EsiCoverageDeterminationService`'s pure-engine contract
     * exactly. `entry_wage` is retained for audit/traceability of WHY
     * `is_covered` was decided the way it was.
     */
    public function up(): void
    {
        Schema::create('employee_esi_coverage', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('employment_record_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('entry_wage', 12, 2);
            $table->boolean('is_covered');
            $table->timestamps();

            $table->unique(['school_id', 'employment_record_id', 'period_start']);
            $table->foreign(['employment_record_id', 'school_id'])
                ->references(['id', 'school_id'])->on('employment_records')
                ->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE employee_esi_coverage ADD CONSTRAINT employee_esi_coverage_period_order_check CHECK (period_end > period_start)');

        TenantRls::enable('employee_esi_coverage');
    }

    public function down(): void
    {
        TenantRls::disable('employee_esi_coverage');
        Schema::dropIfExists('employee_esi_coverage');
    }
};
