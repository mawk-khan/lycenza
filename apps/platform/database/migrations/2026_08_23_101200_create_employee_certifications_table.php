<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 8A.6 -- an Employee's 1:N Restricted-tier professional
     * certifications/licences (docs/modules/HR.md entity model:
     * `EmployeeCertification (1:N -- Restricted tier)`) -- teaching
     * licences, first aid, child safeguarding, driver licences,
     * technical certifications, etc. `name`/`issuer` are plain
     * descriptive strings, not a hard-coded certification catalogue --
     * no approved catalogue contract exists for Phase 8A.6.
     *
     * `credential_number` (nullable) is deliberately NOT globally
     * unique, and not even scoped-unique (e.g. `unique(issuer,
     * credential_number)`) -- different issuers use overlapping
     * numbering formats, and no domain contract requires or justifies
     * inventing that constraint. Treated as Restricted-tier data like
     * every other field on this table, not given special column-level
     * protection beyond RLS.
     *
     * Expiry: `issued_on`/`expires_on` both nullable dates.
     * `expires_on = NULL` legitimately represents a non-expiring
     * certification. No persistent `is_expired`/`days_until_expiry`
     * column -- both are derived from `expires_on` at read time, never
     * stored. `(school_id, expires_on)` and `employee_id` are indexed
     * so a future expiry-monitoring feature (not built in 8A.6 -- no
     * scheduled jobs/notifications/AI here) has the access path it
     * needs without a later migration; no query scope/helper is added
     * yet since there is no real consumer in this checkpoint to test
     * it against (rule 2 -- avoid speculative API surface, not just
     * speculative infrastructure).
     *
     * Verification: identical shape and reasoning to
     * `employee_qualifications.verification_status`/`verified_at` --
     * see that migration's docblock. A verified certification whose
     * issuer/credential_number/dates/name are later edited must not
     * silently keep `verified` status --
     * App\Domain\HR\Application\EmployeeCertificationService::update()
     * resets it to 'unverified' as part of the same edit.
     */
    public function up(): void
    {
        Schema::create('employee_certifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('employee_id');
            $table->string('name');
            $table->string('issuer');
            $table->string('credential_number')->nullable();
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('verification_status')->default('unverified'); // unverified|verified|rejected
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index('school_id');
            $table->index('employee_id');
            $table->index(['school_id', 'expires_on']);

            $table->foreign(['employee_id', 'school_id'])
                ->references(['id', 'school_id'])->on('employees')
                ->cascadeOnDelete();
        });

        DB::statement(
            'ALTER TABLE employee_certifications ADD CONSTRAINT employee_certifications_date_range_check '.
            'CHECK (issued_on IS NULL OR expires_on IS NULL OR expires_on >= issued_on)'
        );

        TenantRls::enable('employee_certifications');
    }

    public function down(): void
    {
        TenantRls::disable('employee_certifications');
        Schema::dropIfExists('employee_certifications');
    }
};
