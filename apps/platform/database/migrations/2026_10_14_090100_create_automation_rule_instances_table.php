<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0L.6 (ADR 0043 §1): one School's enablement of a code-registered
     * rule type (App\Domain\Automation\Application\AutomationRuleCatalog).
     * One instance per (School, rule type). The accountable owner is the
     * manager who enabled it or took ownership; `enabled_at` bounds which
     * events it may react to (never retroactively). No DELETE path:
     * instances are disabled, not removed. Holds identifiers, codes and
     * timestamps only.
     */
    public function up(): void
    {
        Schema::create('automation_rule_instances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('rule_type', 100);
            $table->string('status', 20); // enabled|disabled|suspended -- see CHECK below
            $table->foreignUuid('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason', 64)->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'rule_type']);
            $table->unique(['id', 'school_id']);
        });

        DB::statement(
            'ALTER TABLE automation_rule_instances ADD CONSTRAINT automation_rule_instances_status_check '.
            "CHECK (status IN ('enabled', 'disabled', 'suspended'))"
        );
        DB::statement(
            'ALTER TABLE automation_rule_instances ADD CONSTRAINT automation_rule_instances_suspension_check '.
            "CHECK ((status = 'suspended') = (suspension_reason IS NOT NULL AND suspended_at IS NOT NULL))"
        );
        DB::statement(
            'ALTER TABLE automation_rule_instances ADD CONSTRAINT automation_rule_instances_enabled_check '.
            "CHECK (status <> 'enabled' OR (enabled_at IS NOT NULL AND owner_user_id IS NOT NULL))"
        );

        TenantRls::enable('automation_rule_instances');
    }

    public function down(): void
    {
        TenantRls::disable('automation_rule_instances');
        Schema::dropIfExists('automation_rule_instances');
    }
};
