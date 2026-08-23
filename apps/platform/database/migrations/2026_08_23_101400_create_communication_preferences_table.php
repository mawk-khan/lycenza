<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5A.5 §11/§12/§14 -- a recipient's own explicit preference,
     * scoped to `school_membership_id` (composite FK against
     * school_memberships(id, school_id), the same pattern every other
     * Communications child table uses -- root CLAUDE.md rule 70) --
     * deliberately NOT scoped to `user_id`/App\Models\User directly.
     * The same person's preference in School X must never be readable
     * or writable through School Y's context; a plain `user_id` column
     * here would make that structurally possible even under RLS
     * (RLS scopes by `school_id` on THIS row, but a `user_id`-keyed
     * uniqueness would let School Y's request target School X's row by
     * guessing/enumerating a user id). Scoping the natural key to
     * `school_membership_id` instead removes that entire class of
     * mistake.
     *
     * `channel` CHECK-restricted to `'email'` ONLY -- not `in_app`
     * (brief §10/§13: canonical Communication Hub visibility is never
     * user-suppressible, so a stored in_app preference row could never
     * mean anything other than "always effectively enabled," which is
     * exactly what row-ABSENCE already means; a structural CHECK
     * constraint is what actually prevents a future caller from ever
     * writing a meaningless in_app row, not just application-layer
     * discipline).
     *
     * No `INHERIT` value is stored (brief §12 asked this to be
     * evaluated, not assumed) -- row ABSENCE is what "inherit the
     * default" means; `preference` is a plain closed CHECK
     * (`enabled`/`disabled`) for the one state a row actually
     * represents: an explicit choice. See the phase doc's "Preference
     * ownership" section for the full reasoning.
     */
    public function up(): void
    {
        Schema::create('communication_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('school_membership_id');
            $table->string('channel');
            $table->string('preference');
            $table->timestamps();

            $table->unique(['school_membership_id', 'channel'], 'cp_membership_channel_unique');
            $table->index('school_id');

            $table->foreign(['school_membership_id', 'school_id'], 'cp_membership_school_foreign')
                ->references(['id', 'school_id'])->on('school_memberships')
                ->cascadeOnDelete();
        });

        DB::statement(
            'ALTER TABLE communication_preferences ADD CONSTRAINT communication_preferences_channel_check '.
            "CHECK (channel IN ('email'))"
        );
        DB::statement(
            'ALTER TABLE communication_preferences ADD CONSTRAINT communication_preferences_preference_check '.
            "CHECK (preference IN ('enabled', 'disabled'))"
        );

        TenantRls::enable('communication_preferences');
    }

    public function down(): void
    {
        TenantRls::disable('communication_preferences');
        Schema::dropIfExists('communication_preferences');
    }
};
