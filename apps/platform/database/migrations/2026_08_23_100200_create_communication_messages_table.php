<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5A.1 §2.4: one authored message inside a Thread. `priority`
     * includes `critical` now specifically so a later Phase 5H emergency
     * broadcast feature does not require a schema change (foundation doc
     * §2.4). `message_type` only accepts `text` in this checkpoint --
     * `system`/`ai` are reserved future values, not yet accepted by the
     * CHECK constraint, added when a real system/AI-generated message
     * sender exists. `reply_to_message_id` is a nullable self-reference
     * for future threaded replies; unused by the 5A.1 UI.
     */
    public function up(): void
    {
        Schema::create('communication_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('thread_id');
            $table->foreignUuid('sender_user_id')->constrained('users');
            $table->string('message_type')->default('text');
            $table->text('body');
            $table->string('priority')->default('normal'); // normal|important|urgent|critical
            $table->string('status')->default('sent'); // sent|failed
            $table->uuid('reply_to_message_id')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index('school_id');
            $table->index(['thread_id', 'created_at']);

            $table->foreign(['thread_id', 'school_id'])
                ->references(['id', 'school_id'])->on('communication_threads')
                ->cascadeOnDelete();

            $table->foreign(['reply_to_message_id', 'school_id'])
                ->references(['id', 'school_id'])->on('communication_messages')
                ->nullOnDelete();
        });

        DB::statement(
            'ALTER TABLE communication_messages ADD CONSTRAINT communication_messages_message_type_check '.
            "CHECK (message_type IN ('text'))"
        );
        DB::statement(
            'ALTER TABLE communication_messages ADD CONSTRAINT communication_messages_priority_check '.
            "CHECK (priority IN ('normal', 'important', 'urgent', 'critical'))"
        );
        DB::statement(
            'ALTER TABLE communication_messages ADD CONSTRAINT communication_messages_status_check '.
            "CHECK (status IN ('sent', 'failed'))"
        );

        TenantRls::enable('communication_messages');
    }

    public function down(): void
    {
        TenantRls::disable('communication_messages');
        Schema::dropIfExists('communication_messages');
    }
};
