<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5A.1 §2.3: a User's membership in a Thread. `user_id`
     * references the central `users` table (plain FK, not composite --
     * users are central/platform data, not tenant-owned) since no
     * Guardian/Student identity exists yet to be a participant instead
     * (see the foundation doc's §1.1). `left_at` is set, not deleted, so
     * a departed participant's message history is preserved;
     * `unique(thread_id, user_id)` means re-adding a user who left
     * reactivates the same row rather than creating a second one.
     */
    public function up(): void
    {
        Schema::create('communication_thread_participants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('thread_id');
            $table->foreignUuid('user_id')->constrained('users');
            $table->timestamp('joined_at');
            $table->timestamp('left_at')->nullable();
            $table->timestamp('last_read_at')->nullable();
            $table->boolean('muted')->default(false);
            $table->boolean('archived')->default(false);
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique(['thread_id', 'user_id']);
            $table->index('school_id');
            $table->index(['user_id', 'left_at']);

            $table->foreign(['thread_id', 'school_id'])
                ->references(['id', 'school_id'])->on('communication_threads')
                ->cascadeOnDelete();
        });

        TenantRls::enable('communication_thread_participants');
    }

    public function down(): void
    {
        TenantRls::disable('communication_thread_participants');
        Schema::dropIfExists('communication_thread_participants');
    }
};
