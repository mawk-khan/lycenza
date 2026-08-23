<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Central/platform data (deliberate choice, see
     * docs/architecture/adr/0025-transactional-outbox.md "Tenant
     * safety of the outbox"): the outbox dispatcher is inherently a
     * cross-School background process -- it must see pending events
     * for every School to dispatch them in a timely, fair order. RLS
     * would either block it entirely or require granting the runtime
     * role a bypass, which ADR 0021/0004 both rule out. Individual rows
     * still carry `school_id` explicitly (nullable, for School-scoped
     * events) so every downstream consumer remains tenant-aware even
     * though this table itself is not RLS-protected. Payloads are
     * deliberately minimal (references, not full records -- section 55
     * of this checkpoint's brief / docs/security/DATA-CLASSIFICATION.md).
     */
    public function up(): void
    {
        Schema::create('domain_event_outbox', function (Blueprint $table) {
            $table->uuid('id')->primary(); // == event_id
            $table->string('event_type'); // e.g. "school.setting.changed.v1"
            $table->unsignedSmallInteger('event_version');
            $table->uuid('school_id')->nullable();
            $table->uuid('campus_id')->nullable();
            $table->uuid('actor_id')->nullable();
            $table->string('request_id')->nullable();
            $table->uuid('correlation_id');
            $table->uuid('causation_id')->nullable();
            $table->jsonb('payload');
            $table->jsonb('metadata')->default('{}');
            $table->timestamp('occurred_at');
            $table->timestamp('available_at');
            $table->string('status')->default('pending'); // pending|dispatched|failed
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();

            $table->index('school_id');
            $table->index('correlation_id');
            $table->index(['status', 'available_at']);
            $table->index('event_type');
        });

        DB::statement(
            'ALTER TABLE domain_event_outbox ADD CONSTRAINT domain_event_outbox_status_check '.
            "CHECK (status IN ('pending', 'dispatched', 'failed'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_event_outbox');
    }
};
