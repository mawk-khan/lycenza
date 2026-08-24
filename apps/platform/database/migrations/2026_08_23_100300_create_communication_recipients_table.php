<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5A.1 §2.5: the logical "this Message is meant for this
     * person" record -- distinct from delivery (a recipient may
     * eventually have many channel deliveries, see
     * communication_deliveries). `unique(message_id, recipient_user_id)`
     * guarantees one logical recipient row per person per message.
     */
    public function up(): void
    {
        Schema::create('communication_recipients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('message_id');
            $table->foreignUuid('recipient_user_id')->constrained('users');
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique(['message_id', 'recipient_user_id']);
            $table->index('school_id');
            $table->index('recipient_user_id');

            $table->foreign(['message_id', 'school_id'])
                ->references(['id', 'school_id'])->on('communication_messages')
                ->cascadeOnDelete();
        });

        TenantRls::enable('communication_recipients');
    }

    public function down(): void
    {
        TenantRls::disable('communication_recipients');
        Schema::dropIfExists('communication_recipients');
    }
};
