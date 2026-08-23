<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 8A.1 -- the concurrency-safe backing store for
     * App\Domain\HR\Application\EmployeeNumberAllocator (docs/modules/HR.md
     * "Employee identifier strategy", ADR 0028). Exactly one row per
     * School (`unique(school_id)`), locked with `SELECT ... FOR UPDATE`
     * inside the same transaction that inserts the Employee row --
     * never a `SELECT MAX(...) + 1`, never an application-level
     * check-then-insert (CLAUDE.md rule 30's idempotency principle
     * applied here).
     *
     * Kept a normal UUIDv7 `id` primary key (ADR 0019's default) even
     * though `school_id` alone would functionally identify the row --
     * introducing a second silent exception to ADR 0019 alongside
     * `capabilities.key` was judged not worth it for one counter table
     * that isn't otherwise catalog/enum-like.
     */
    public function up(): void
    {
        Schema::create('hr_employee_number_counters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();

            $table->unique('school_id');
        });

        TenantRls::enable('hr_employee_number_counters');
    }

    public function down(): void
    {
        TenantRls::disable('hr_employee_number_counters');
        Schema::dropIfExists('hr_employee_number_counters');
    }
};
