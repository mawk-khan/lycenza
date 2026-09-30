<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FEE.5 (ADR 0062 §16.1; owner decision H, 2026-09-30): Fees-owned
     * late-fee rules -- configuration only. Legal status: DEVELOPMENT
     * AUTHORISED — PROD LEGAL SIGN-OFF REQUIRED (ADR 0058 E31, E32).
     *
     * - Scope (§16.1, unchanged): one fee structure plus an optional fee
     *   head (NULL = every line of the structure; a named head must be a
     *   line of that structure). `late_fee_head_id` names the head whose
     *   receivable/revenue accounts carry the late fee.
     * - Calculation (H): `fixed` (amount > 0) or `percentage` of the
     *   source charge's current outstanding (0 < p <= 100); `grace_days`
     *   >= 0; optional `max_amount` cap > 0. No other kind exists.
     * - Rules start `inactive`, are editable only while inactive, and are
     *   never deleted; `configuration_version` increases on every change
     *   (a run's preview goes stale on any change). The structure (the
     *   rule's scope identity) never changes.
     */
    public function up(): void
    {
        Schema::create('fee_late_fee_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('name', 120);
            $table->uuid('fee_structure_id');
            $table->uuid('fee_head_id')->nullable();
            $table->uuid('late_fee_head_id');
            $table->unsignedSmallInteger('grace_days')->default(0);
            $table->string('kind');
            $table->decimal('fixed_amount', 14, 2)->nullable();
            $table->decimal('percentage', 5, 2)->nullable();
            $table->decimal('max_amount', 14, 2)->nullable();
            $table->char('currency', 3)->default('INR');
            $table->string('status')->default('inactive');
            $table->unsignedInteger('configuration_version')->default(1);
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index(['school_id', 'status']);
            $table->index('fee_structure_id');
            $table->index('fee_head_id');
            $table->index('late_fee_head_id');

            $table->foreign(['fee_structure_id', 'school_id'], 'fee_late_fee_rules_structure_fk')->references(['id', 'school_id'])->on('fee_structures')->restrictOnDelete();
            $table->foreign(['fee_head_id', 'school_id'], 'fee_late_fee_rules_fee_head_fk')->references(['id', 'school_id'])->on('fee_heads')->restrictOnDelete();
            $table->foreign(['late_fee_head_id', 'school_id'], 'fee_late_fee_rules_late_fee_head_fk')->references(['id', 'school_id'])->on('fee_heads')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE fee_late_fee_rules ADD CONSTRAINT fee_late_fee_rules_status_check CHECK (status IN ('active', 'inactive'))");
        DB::statement("ALTER TABLE fee_late_fee_rules ADD CONSTRAINT fee_late_fee_rules_kind_check CHECK (kind IN ('fixed', 'percentage'))");
        DB::statement("ALTER TABLE fee_late_fee_rules ADD CONSTRAINT fee_late_fee_rules_currency_inr_only_check CHECK (currency = 'INR')");
        DB::statement('ALTER TABLE fee_late_fee_rules ADD CONSTRAINT fee_late_fee_rules_grace_days_check CHECK (grace_days >= 0 AND grace_days <= 3650)');
        DB::statement('ALTER TABLE fee_late_fee_rules ADD CONSTRAINT fee_late_fee_rules_max_amount_check CHECK (max_amount IS NULL OR max_amount > 0)');
        DB::statement(
            'ALTER TABLE fee_late_fee_rules ADD CONSTRAINT fee_late_fee_rules_value_shape_check CHECK ('.
            "(kind = 'fixed' AND fixed_amount IS NOT NULL AND fixed_amount > 0 AND percentage IS NULL) OR ".
            "(kind = 'percentage' AND percentage IS NOT NULL AND percentage > 0 AND percentage <= 100 AND fixed_amount IS NULL))"
        );

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION fees_validate_late_fee_rule() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            AS $$
            BEGIN
                IF TG_OP = 'INSERT' AND NEW.status <> 'inactive' THEN
                    RAISE EXCEPTION 'a late-fee rule is always created inactive'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.fee_head_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM fee_structure_lines
                    WHERE fee_structure_id = NEW.fee_structure_id AND fee_head_id = NEW.fee_head_id
                ) THEN
                    RAISE EXCEPTION 'late-fee rule fee head % is not a line of fee structure %', NEW.fee_head_id, NEW.fee_structure_id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF TG_OP = 'INSERT' THEN
                    RETURN NEW;
                END IF;

                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.school_id IS DISTINCT FROM OLD.school_id
                    OR NEW.fee_structure_id IS DISTINCT FROM OLD.fee_structure_id
                    OR NEW.currency IS DISTINCT FROM OLD.currency
                    OR NEW.created_by_user_id IS DISTINCT FROM OLD.created_by_user_id
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                    OR NEW.configuration_version < OLD.configuration_version
                THEN
                    RAISE EXCEPTION 'late-fee rule % identity is immutable', OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF OLD.status = 'active' AND (
                    NEW.name IS DISTINCT FROM OLD.name
                    OR NEW.fee_head_id IS DISTINCT FROM OLD.fee_head_id
                    OR NEW.late_fee_head_id IS DISTINCT FROM OLD.late_fee_head_id
                    OR NEW.grace_days IS DISTINCT FROM OLD.grace_days
                    OR NEW.kind IS DISTINCT FROM OLD.kind
                    OR NEW.fixed_amount IS DISTINCT FROM OLD.fixed_amount
                    OR NEW.percentage IS DISTINCT FROM OLD.percentage
                    OR NEW.max_amount IS DISTINCT FROM OLD.max_amount
                ) THEN
                    RAISE EXCEPTION 'late-fee rule % is active; deactivate it before editing', OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::statement(
            'CREATE TRIGGER fee_late_fee_rules_guard_trigger '.
            'BEFORE INSERT OR UPDATE ON fee_late_fee_rules '.
            'FOR EACH ROW EXECUTE FUNCTION fees_validate_late_fee_rule()'
        );

        TenantRls::enable('fee_late_fee_rules');
        TenantRls::revokeDelete('fee_late_fee_rules');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS fee_late_fee_rules_guard_trigger ON fee_late_fee_rules');
        DB::statement('DROP FUNCTION IF EXISTS fees_validate_late_fee_rule()');
        TenantRls::disable('fee_late_fee_rules');
        Schema::dropIfExists('fee_late_fee_rules');
    }
};
