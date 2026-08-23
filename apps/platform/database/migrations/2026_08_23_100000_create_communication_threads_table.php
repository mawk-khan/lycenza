<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5A.1: a Thread is the conversational context a Message
     * belongs to (docs/communication-hub/PHASE-5A-1-FOUNDATION.md §2.2).
     * `campus_id` is optional -- School-wide threads have none -- and,
     * when present, composite-FK-protected against campuses(id,
     * school_id) exactly like Section/Room already do, so a Thread can
     * never reference another School's Campus.
     */
    public function up(): void
    {
        Schema::create('communication_threads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('campus_id')->nullable();
            $table->string('thread_type')->default('direct'); // direct|group
            $table->string('subject')->nullable();
            $table->string('status')->default('open'); // open|archived|closed
            $table->foreignUuid('created_by_user_id')->constrained('users');
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index('school_id');
            $table->index(['school_id', 'last_activity_at']);

            $table->foreign(['campus_id', 'school_id'])
                ->references(['id', 'school_id'])->on('campuses')
                ->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE communication_threads ADD CONSTRAINT communication_threads_thread_type_check '.
            "CHECK (thread_type IN ('direct', 'group'))"
        );
        DB::statement(
            'ALTER TABLE communication_threads ADD CONSTRAINT communication_threads_status_check '.
            "CHECK (status IN ('open', 'archived', 'closed'))"
        );

        TenantRls::enable('communication_threads');
    }

    public function down(): void
    {
        TenantRls::disable('communication_threads');
        Schema::dropIfExists('communication_threads');
    }
};
