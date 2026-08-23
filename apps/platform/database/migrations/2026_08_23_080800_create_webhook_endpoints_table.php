<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant-owned data (RLS-protected): a School's registered webhook
     * destination. Event subscriptions live in their own
     * `webhook_subscriptions` table (Phase 0C.3 section 4/11) -- an
     * endpoint and what it's subscribed to are distinct concepts with
     * independent lifecycles (a subscription can be added/removed
     * without touching the endpoint or its secret).
     *
     * `unique(['id', 'school_id'])` exists solely so tenant-owned child
     * tables (webhook_subscriptions, webhook_deliveries) can declare a
     * composite foreign key pinning their own school_id to THIS row's
     * school_id -- see school_memberships' migration for the original
     * instance of this pattern.
     *
     * Secret rotation (section 9): `secret_encrypted` is the CURRENT
     * signing secret; `previous_secret_encrypted` is the one it
     * replaced, valid for the recorded overlap window
     * (`previous_secret_expires_at`) -- see
     * App\Support\Webhooks\WebhookSigner.
     *
     * Encrypted, not hashed (unlike ServiceIdentity's credential_hash):
     * Laravel must SIGN outbound deliveries with this secret, which
     * requires retrieving the raw value -- a one-way hash can only
     * verify a presented value, it cannot be used to compute a new
     * HMAC. Uses Laravel's own `encrypted` Eloquent cast (APP_KEY-based
     * AES-256-CBC via the framework's Encrypter) -- not a custom
     * cryptography primitive (section 8 forbids inventing one).
     */
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('name');
            $table->string('url');
            $table->text('secret_encrypted');
            $table->text('previous_secret_encrypted')->nullable();
            $table->timestamp('previous_secret_expires_at')->nullable();
            $table->string('status')->default('active'); // active|disabled
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index('school_id');
        });

        DB::statement(
            'ALTER TABLE webhook_endpoints ADD CONSTRAINT webhook_endpoints_status_check '.
            "CHECK (status IN ('active', 'disabled'))"
        );

        TenantRls::enable('webhook_endpoints');
    }

    public function down(): void
    {
        TenantRls::disable('webhook_endpoints');
        Schema::dropIfExists('webhook_endpoints');
    }
};
