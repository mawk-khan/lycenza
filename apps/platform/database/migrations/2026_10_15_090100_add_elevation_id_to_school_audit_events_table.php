<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0N.3 (ADR 0044 section 13): a School audit row written while the
 * request runs under a platform elevation carries that elevation's id --
 * an envelope column (filled by AuditRecorder::school(), never by module
 * code), not a metadata key. NULL for every ordinary School action. Not
 * shown by the School audit-log review (ADR 0042): whether a School sees
 * it is an open decision (D16).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_audit_events', function (Blueprint $table) {
            $table->foreignUuid('elevation_id')->nullable()->after('request_id')->constrained('school_elevations')->restrictOnDelete();
            $table->index('elevation_id');
        });
    }

    public function down(): void
    {
        Schema::table('school_audit_events', function (Blueprint $table) {
            $table->dropForeign(['elevation_id']);
            $table->dropIndex(['elevation_id']);
            $table->dropColumn('elevation_id');
        });
    }
};
