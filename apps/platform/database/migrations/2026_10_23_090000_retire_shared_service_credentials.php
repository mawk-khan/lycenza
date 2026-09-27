<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0053 (Phase 0O.7A): retire the shared service credential.
 *
 * Service identities are now a closed CODE catalog (`platform`,
 * `ai-gateway`) authenticated by per-request Ed25519 assertions against
 * configured public keys (App\Support\ServiceAuth). Nothing reads
 * `service_identities` / `service_identity_capabilities` any more: they only
 * held a SHA-256 of the shared bearer secret, so keeping them would keep a
 * dead authentication surface (and a credential hash) in the database.
 * Audited, every use was removed in the same change; the platform audit
 * history of past issuance (`platform_audit_events`) is untouched.
 *
 * The service scopes leave the HUMAN capability catalog, together with the
 * two capabilities that only administered the retired identities
 * (`platform.service_identities.view/.manage`, held by no route). A CHECK
 * then keeps every ADR 0053 service scope out of `capabilities` for good,
 * so no role, membership or User can ever be granted one.
 *
 * down() restores the schema and those catalog rows (and the super admin's
 * two administration grants); issued credential hashes are deliberately NOT
 * restorable -- a retired shared secret must never come back.
 */
return new class extends Migration
{
    private const SERVICE_SCOPES = [
        'ai.tools.invoke', 'ai.completions.authorize', 'ai.audit.write',
        'gateway.tools.invoke', 'gateway.complete',
    ];

    private const RETIRED_CAPABILITIES = [
        ['key' => 'ai.tools.invoke', 'label' => 'AI Gateway may invoke internal AI tool contracts', 'namespace' => 'platform'],
        ['key' => 'ai.audit.write', 'label' => 'AI Gateway may write durable audit entries to Laravel', 'namespace' => 'platform'],
        ['key' => 'platform.service_identities.view', 'label' => 'View service identities (platform)', 'namespace' => 'platform'],
        ['key' => 'platform.service_identities.manage', 'label' => 'Manage service identities (platform)', 'namespace' => 'platform'],
    ];

    public function up(): void
    {
        Schema::dropIfExists('service_identity_capabilities');
        Schema::dropIfExists('service_identities');

        $keys = array_column(self::RETIRED_CAPABILITIES, 'key');
        DB::table('role_capabilities')->whereIn('capability_key', $keys)->delete();
        DB::table('capabilities')->whereIn('key', $keys)->delete();

        $list = implode(', ', array_map(fn (string $scope) => "'{$scope}'", self::SERVICE_SCOPES));
        DB::statement("ALTER TABLE capabilities ADD CONSTRAINT capabilities_not_service_scope CHECK (key NOT IN ({$list}))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE capabilities DROP CONSTRAINT IF EXISTS capabilities_not_service_scope');

        $now = now();
        foreach (self::RETIRED_CAPABILITIES as $capability) {
            DB::table('capabilities')->insertOrIgnore([...$capability, 'created_at' => $now, 'updated_at' => $now]);
        }
        $superAdmin = DB::table('roles')->where('key', 'platform_super_admin')->value('id');
        if ($superAdmin !== null) {
            foreach (['platform.service_identities.view', 'platform.service_identities.manage'] as $key) {
                DB::table('role_capabilities')->insertOrIgnore(['role_id' => $superAdmin, 'capability_key' => $key]);
            }
        }

        Schema::create('service_identities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('credential_hash');
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('service_identity_capabilities', function (Blueprint $table) {
            $table->foreignUuid('service_identity_id')->constrained('service_identities')->cascadeOnDelete();
            $table->string('capability_key', 150);
            $table->foreign('capability_key')->references('key')->on('capabilities')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['service_identity_id', 'capability_key']);
        });
    }
};
