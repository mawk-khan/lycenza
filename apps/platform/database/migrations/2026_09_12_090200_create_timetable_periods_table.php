<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0H (Timetable foundation): a School-owned, reusable named
     * time slot ("Period 1", 09:00-09:45) that `timetable_entries` (next
     * migration in this set) schedules against, day-of-week by
     * day-of-week. A Period is reference data with its own active/
     * inactive lifecycle (CLAUDE.md rule 73) -- no DELETE route is ever
     * built for it; `App\Domain\Timetable\Application\TimetablePeriodService`
     * is the sole write path.
     *
     * `CHECK(start_time < end_time)` rejects a zero-width or inverted
     * interval at the database level -- the structural backstop behind
     * `App\Domain\Timetable\Application\Exceptions\InvalidTimetablePeriodException`.
     * Overlap between two ACTIVE Periods for the same School is NOT a
     * constraint PostgreSQL's plain btree unique index can express
     * (that needs a range/exclusion constraint this checkpoint does not
     * introduce) -- it is instead enforced by
     * `TimetablePeriodService` under an `App\Support\Concurrency\TenantLock`,
     * proven under real two-process concurrency
     * (`Tests\Feature\Timetable\TimetablePeriodConcurrencyTest`).
     *
     * Code uniqueness mirrors `ledger_accounts_school_id_code_ci_unique`'s
     * exact expression-index pattern (`create_ledger_accounts_table`
     * migration's own docblock) rather than a plain
     * `unique(['school_id', 'code'])` -- `App\Support\NormalizesCode`
     * uppercases `code` at the Eloquent mutator layer, but a raw SQL
     * insert bypassing that mutator would not collide with a
     * plain-string unique index, so a true case-insensitive expression
     * index is used instead, immune to any caller bypassing the model
     * layer.
     */
    public function up(): void
    {
        Schema::create('timetable_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedInteger('sort_order')->nullable();
            $table->string('status')->default('active'); // active|inactive -- see CHECK below
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index('school_id');
        });

        DB::statement(
            'ALTER TABLE timetable_periods ADD CONSTRAINT timetable_periods_status_check '.
            "CHECK (status IN ('active', 'inactive'))"
        );

        DB::statement(
            'ALTER TABLE timetable_periods ADD CONSTRAINT timetable_periods_start_before_end_check '.
            'CHECK (start_time < end_time)'
        );

        // Case-insensitive Period-code uniqueness, enforced by the
        // database itself -- see this migration's docblock.
        DB::statement(
            'CREATE UNIQUE INDEX timetable_periods_school_id_code_ci_unique '.
            'ON timetable_periods (school_id, upper(code))'
        );

        TenantRls::enable('timetable_periods');
    }

    public function down(): void
    {
        TenantRls::disable('timetable_periods');
        Schema::dropIfExists('timetable_periods');
    }
};
