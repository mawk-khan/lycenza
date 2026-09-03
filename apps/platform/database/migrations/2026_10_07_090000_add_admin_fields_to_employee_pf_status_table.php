<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Checkpoint 9.6I -- the administrative PF-status surface
     * (Section 1's "Employee PF status" requirements) needs two facts
     * `employee_pf_status` didn't carry yet: WHEN a higher-wage
     * approval took effect (previously only a free-text reference),
     * and higher-pension status (EPS higher-pension option) -- a
     * FIFTH independent fact, never inferred from
     * `has_approved_higher_wage_contribution` or any other column on
     * this table (same "never derive one from another" discipline
     * Checkpoint 9.6C's own migration docblock already established
     * for the original four).
     *
     * "Previous membership" (Section 1's requirement) is NOT a new
     * column -- `has_existing_pf_membership`'s existing docblock
     * ("already holds PF membership from this or a prior employment")
     * already covers it; adding a second column for the same fact
     * would let the two disagree with no way to know which is
     * authoritative. The admin API/UI exposes it under a clearer
     * label instead (see StatutoryEmployeePfStatusController).
     */
    public function up(): void
    {
        Schema::table('employee_pf_status', function (Blueprint $table) {
            $table->date('higher_wage_approval_effective_from')->nullable()->after('higher_wage_approval_reference');
            $table->boolean('has_higher_pension_status')->default(false)->after('is_eps_eligible');
        });
    }

    public function down(): void
    {
        Schema::table('employee_pf_status', function (Blueprint $table) {
            $table->dropColumn(['higher_wage_approval_effective_from', 'has_higher_pension_status']);
        });
    }
};
