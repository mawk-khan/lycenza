<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ORG.12C (ADR 0080 §8): two optional, jurisdiction-neutral institutional
 * references on the School profile, beside the existing `legal_name` and
 * `education_board_id`:
 *
 * - `registration_reference`: the School's institutional registration
 *   reference (e.g. a society/trust/school registration number). Never a
 *   tax identifier -- no GST, PAN, VAT, EIN or statutory semantics (OD-12.2,
 *   reference family RF-22 stays LEGAL_DECISION_REQUIRED).
 * - `affiliation_reference`: the affiliation/accreditation number with its
 *   education board (the board itself stays `education_board_id`).
 *
 * Both, like `legal_name`, are written only by SchoolIdentityService (fresh
 * MFA, closed reason, stale-form check, audited) and are not mass
 * assignable. Nullable: no backfill, no existing School becomes invalid.
 * `schools` is a platform table (no school_id, no RLS) -- unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('registration_reference', 100)->nullable()->after('code');
            $table->string('affiliation_reference', 100)->nullable()->after('education_board_id');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['registration_reference', 'affiliation_reference']);
        });
    }
};
