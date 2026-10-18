<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ORG.1A (ADR 0076 §24, V-4): `school_audit_events.elevation_id` (Phase
 * 0N.3, ADR 0044) was a single-column FK to `school_elevations(id)`, so the
 * database did not prove that an audit row recorded under an elevation
 * belongs to the SAME School as that elevation -- AuditRecorder does, but no
 * application check substitutes for structural integrity (CLAUDE.md
 * rule 70).
 *
 * `school_elevations` gains `UNIQUE (id, school_id)` (trivially satisfied:
 * `id` is its primary key), and the audit reference becomes the composite
 * `(elevation_id, school_id) -> school_elevations(id, school_id)`, still
 * RESTRICT. A NULL `elevation_id` (an ordinary, non-elevated event) stays
 * valid (MATCH SIMPLE). No audit row is changed: the migration refuses, with
 * a count, if any existing row references another School's elevation, and
 * re-adding the key makes PostgreSQL itself validate every row (RI checks
 * are not subject to RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        $crossSchool = (int) DB::scalar(
            'SELECT count(*) FROM school_audit_events a JOIN school_elevations e ON e.id = a.elevation_id WHERE a.school_id <> e.school_id'
        );

        if ($crossSchool > 0) {
            throw new RuntimeException(
                "Cannot bind audit elevations to their School: {$crossSchool} school_audit_events row(s) reference another School's elevation. "
                .'Audit history is never rewritten by a migration; investigate those rows first.'
            );
        }

        DB::statement('ALTER TABLE school_elevations ADD CONSTRAINT school_elevations_id_school_unique UNIQUE (id, school_id)');
        DB::statement(
            'ALTER TABLE school_audit_events DROP CONSTRAINT school_audit_events_elevation_id_foreign, '
            .'ADD CONSTRAINT school_audit_events_elevation_school_foreign FOREIGN KEY (elevation_id, school_id) '
            .'REFERENCES school_elevations (id, school_id) ON DELETE RESTRICT'
        );
    }

    /** Restores the previous single-column reference exactly. */
    public function down(): void
    {
        DB::statement(
            'ALTER TABLE school_audit_events DROP CONSTRAINT school_audit_events_elevation_school_foreign, '
            .'ADD CONSTRAINT school_audit_events_elevation_id_foreign FOREIGN KEY (elevation_id) '
            .'REFERENCES school_elevations (id) ON DELETE RESTRICT'
        );
        DB::statement('ALTER TABLE school_elevations DROP CONSTRAINT school_elevations_id_school_unique');
    }
};
