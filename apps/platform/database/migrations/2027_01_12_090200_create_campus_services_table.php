<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ORG.12C (ADR 0080 §8, OD-12.5): a Campus's CURRENT service offerings --
 * one row per offered service, from the code-owned
 * App\Domain\Campuses\Application\CampusServiceType catalogue (the CHECK
 * below lists exactly its codes; CampusServiceArchitectureTest keeps them
 * equal).
 *
 * Descriptive only: a row never enables a module, grants a capability,
 * creates Transport/Hostel/Canteen records or charges anything, and no
 * module entitlement writes or reads it.
 *
 * - Tenant-owned: forced RLS (rule 18); the same-School Campus is a
 *   composite FK (rule 70); one row per (Campus, service).
 * - Current state, not history: removing a service deletes its row (the
 *   change is audited as `campus.services_changed`); a row is never
 *   updated, so the runtime role has no UPDATE.
 * - Cascades with its Campus/School, like `campuses` itself (Campuses are
 *   deactivated, never deleted; School deletion is test cleanup only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campus_services', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('campus_id');
            $table->string('service_code', 40);
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['campus_id', 'service_code']);
            $table->index('school_id');
            $table->foreign(['campus_id', 'school_id'])->references(['id', 'school_id'])->on('campuses')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE campus_services ADD CONSTRAINT campus_services_code_check CHECK (service_code IN ('day_school', 'early_years', 'boarding', 'meals', 'transport'))");

        TenantRls::enable('campus_services');
        DB::statement('REVOKE UPDATE ON campus_services FROM school_os_app');
    }

    public function down(): void
    {
        TenantRls::disable('campus_services');
        Schema::dropIfExists('campus_services');
    }
};
