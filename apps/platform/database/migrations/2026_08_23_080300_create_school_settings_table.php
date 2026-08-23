<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant-owned data (RLS-protected). Key-value storage with JSONB
     * values, NOT PHP serialize() blobs -- values are always validated/
     * cast against a registered SettingDefinition
     * (App\Support\Settings\SettingRegistry) before being written or
     * read back, so "typed schema" is an application-layer contract
     * on top of a flexible storage column, not "anything goes."
     * Secrets/credentials must NEVER be stored here -- see
     * docs/architecture/adr/0028-feature-flags-and-settings.md
     * ("Secrets are not settings").
     */
    public function up(): void
    {
        Schema::create('school_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('key');
            $table->jsonb('value');
            $table->foreignUuid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['school_id', 'key']);
        });

        TenantRls::enable('school_settings');
    }

    public function down(): void
    {
        TenantRls::disable('school_settings');
        Schema::dropIfExists('school_settings');
    }
};
