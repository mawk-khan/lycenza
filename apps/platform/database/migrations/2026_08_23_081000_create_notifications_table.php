<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant-owned data (RLS-protected). Channel-agnostic notification
     * intent -- no provider-specific fields on this core model (section
     * 17); provider adapters (App\Support\Notifications\Providers\*)
     * receive what they need at send time, not stored here. `payload`
     * carries template variables/references, never full sensitive
     * records (section 55).
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel'); // in_app|email|sms|whatsapp|push
            $table->string('template_key');
            $table->jsonb('payload')->default('{}');
            $table->string('status')->default('pending'); // pending|sent|delivered|failed
            $table->uuid('triggering_event_id')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('school_id');
            $table->index(['status']);
        });

        DB::statement(
            'ALTER TABLE notifications ADD CONSTRAINT notifications_status_check '.
            "CHECK (status IN ('pending', 'sent', 'delivered', 'failed'))"
        );
        DB::statement(
            'ALTER TABLE notifications ADD CONSTRAINT notifications_channel_check '.
            "CHECK (channel IN ('in_app', 'email', 'sms', 'whatsapp', 'push'))"
        );

        TenantRls::enable('notifications');
    }

    public function down(): void
    {
        TenantRls::disable('notifications');
        Schema::dropIfExists('notifications');
    }
};
