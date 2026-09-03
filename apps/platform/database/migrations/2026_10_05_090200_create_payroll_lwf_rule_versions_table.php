<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Checkpoint 9.6C (ADR 0035) -- state (jurisdiction) reference data -- still platform-level (not School-owned): every School in the same state shares the identical LWF rule. */
    public function up(): void
    {
        Schema::create('payroll_lwf_rule_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('jurisdiction'); // e.g. "Telangana"
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status')->default('active');
            $table->string('legal_reference');
            $table->decimal('employee_amount', 12, 2);
            $table->decimal('employer_amount', 12, 2);
            $table->timestamps();

            $table->unique(['jurisdiction', 'effective_from']);
        });

        DB::statement("ALTER TABLE payroll_lwf_rule_versions ADD CONSTRAINT payroll_lwf_rule_versions_status_check CHECK (status IN ('active', 'superseded'))");
        DB::statement('ALTER TABLE payroll_lwf_rule_versions ADD CONSTRAINT payroll_lwf_rule_versions_date_order_check CHECK (effective_to IS NULL OR effective_to > effective_from)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_lwf_rule_versions');
    }
};
