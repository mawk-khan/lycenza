<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5A.5 §15/§16 -- a School's own override of the default
     * per-channel policy. This table stores EXPLICIT OVERRIDES ONLY --
     * a School that has never touched channel settings has ZERO rows
     * here, and
     * App\Domain\Communications\Application\Policy\CommunicationChannelPolicyService::defaultPolicy()
     * supplies the system default (brief §16: "do not require every
     * existing school to have rows inserted for every channel merely
     * for Communications to keep working"). This is also what
     * preserves brief §14's invariant -- deploying this migration
     * changes zero existing behavior, since every School starts with
     * no override rows.
     *
     * `channel` is CHECK-restricted to the two channels a policy is
     * meaningful for today (`in_app`/`email`) -- widened additively
     * once SMS/WhatsApp/Push actually have registered drivers, same
     * pattern as communication_announcement_channels/
     * communication_templates.template_type.
     *
     * This is deliberately a NEW dedicated table, not an entry in the
     * existing generic App\Models\SchoolSetting/App\Support\Settings\SettingRegistry
     * key-value store (which already has an unused
     * `communications.digest_frequency` example). That store fits a
     * single scalar value per key; a channel policy is a small
     * multi-field struct per channel, and every other real domain
     * concept in this module (communication_announcement_channels,
     * communication_templates, ...) already gets its own typed,
     * CHECK-constrained, RLS-protected table rather than an untyped
     * JSONB blob -- this follows that established precedent instead of
     * introducing a second modeling style. See the phase doc.
     */
    public function up(): void
    {
        Schema::create('communication_channel_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('channel');
            $table->boolean('optional_allowed');
            $table->boolean('required_allowed');
            $table->boolean('recipient_can_opt_out');
            $table->timestamps();

            $table->unique(['school_id', 'channel']);
            $table->index('school_id');
        });

        DB::statement(
            'ALTER TABLE communication_channel_policies ADD CONSTRAINT communication_channel_policies_channel_check '.
            "CHECK (channel IN ('in_app', 'email'))"
        );

        TenantRls::enable('communication_channel_policies');
    }

    public function down(): void
    {
        TenantRls::disable('communication_channel_policies');
        Schema::dropIfExists('communication_channel_policies');
    }
};
