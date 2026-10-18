<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ORG.5 (ADR 0076 §12, §31): per-School state of the OPTIONAL modules.
 *
 * - One row per (School, optional module), `enabled` true or false. Core
 *   modules are never stored: the CHECK admits exactly the optional keys of
 *   App\Domain\Platform\Application\Modules\ModuleCatalogue (literal here,
 *   never imported; ModuleCatalogueArchitectureTest pins the two together),
 *   so an unknown or core key can never be written and never grants
 *   anything. A missing row means DISABLED (the new-School default, D4).
 * - Tenant-owned: FORCE RLS (rule 18); written only by
 *   SchoolModuleEntitlementService inside the target School's
 *   TenantContext. The runtime role may SELECT/INSERT/UPDATE but never
 *   DELETE: a state change is an update, history is the platform audit
 *   ledger (`platform.school_entitlement.*`), nothing is removed.
 * - BACKFILL (D1/D2, compatibility): every School that exists when this
 *   runs keeps every optional module it could use before ORG.5 -- one
 *   `enabled = true` row per optional module. Legal and environment gates
 *   stay where they are; an entitlement never opens them. This is not a
 *   claim about historical licensing.
 *
 * down() drops only this table (pre-ORG.5 code had no entitlements); it
 * touches no domain data, grant, membership or lifecycle state.
 */
return new class extends Migration
{
    /** Exactly ModuleCatalogue::optionalKeys() at ORG.5. */
    private const OPTIONAL_MODULES = [
        'admissions', 'timetable', 'attendance', 'curriculum', 'lms', 'examinations', 'finance', 'fees',
        'leave', 'staff_attendance', 'payroll', 'library', 'transport', 'hostel', 'inventory', 'canteen',
        'visitor', 'communications', 'guardian_portal', 'analytics', 'automation', 'integrations',
    ];

    public function up(): void
    {
        Schema::create('school_module_entitlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('module_key', 64);
            $table->boolean('enabled');
            $table->string('reason_code', 64);
            $table->timestampTz('changed_at');
            $table->timestamps();

            $table->unique(['school_id', 'module_key']);
        });

        $keys = implode(', ', array_map(fn (string $key) => "'{$key}'", self::OPTIONAL_MODULES));
        DB::statement("ALTER TABLE school_module_entitlements ADD CONSTRAINT school_module_entitlements_module_key_check CHECK (module_key IN ({$keys}))");
        DB::statement("ALTER TABLE school_module_entitlements ADD CONSTRAINT school_module_entitlements_reason_code_check CHECK (reason_code IN ('pre_org5_backfill', 'onboarding_configuration', 'school_request', 'platform_policy', 'operational_correction'))");

        TenantRls::enable('school_module_entitlements');
        TenantRls::revokeDelete('school_module_entitlements');

        DB::statement(<<<SQL
            INSERT INTO school_module_entitlements (id, school_id, module_key, enabled, reason_code, changed_at, created_at, updated_at)
            SELECT gen_random_uuid(), s.id, m.key, true, 'pre_org5_backfill', now(), now(), now()
            FROM schools s CROSS JOIN unnest(ARRAY[{$keys}]::text[]) AS m(key)
            SQL);
    }

    public function down(): void
    {
        TenantRls::disable('school_module_entitlements');
        Schema::dropIfExists('school_module_entitlements');
    }
};
