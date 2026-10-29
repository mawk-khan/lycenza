<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ORG.12F (ADR 0080 §11, OD-12.16–OD-12.19): presentation profiles --
 * navigation overrides, School branding and Network branding. PRESENTATION
 * ONLY: nothing here is read by authorization (CapabilityResolver, route
 * middleware, Gates) and no row grants, revokes or scopes anything.
 *
 * - `school_navigation_preferences` (School-owned, forced RLS): overrides
 *   only -- hidden, an order within the item's code-owned group, a
 *   plain-text label alias -- for a code-owned navigation key of one
 *   implemented audience. Absence = the code-owned default. No role, User or
 *   Campus column (presentation is per School and audience). Keys are
 *   validated against StaffNavigation by the one writer; unknown keys are
 *   ignored by the resolver.
 * - `school_brand_profiles` (School-owned, forced RLS): at most one per
 *   School -- an optional label and primary accent of the School's own, and
 *   an optional EXPLICIT Network brand source. The source is a composite key
 *   to the School's own membership (`school_group_members(school_group_id,
 *   school_id)`), so a School can only ever name a Group it belongs to, and
 *   leaving that Group clears the source atomically (ON DELETE SET NULL of
 *   that one column). Relationship type never decides branding.
 * - `school_group_brand_profiles` (Group-owned platform table, like every
 *   School Group table: no `school_id`, no RLS): a Network's label and
 *   primary accent, with a version for stale-update protection.
 *
 * Colours are bounded tokens (`#RRGGBB`, upper case); labels are plain text
 * (no angle brackets, bounded length). No CSS, HTML, script, URL or file.
 * No row is backfilled: existing Schools render exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_navigation_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->restrictOnDelete();
            $table->string('audience', 32);
            $table->string('navigation_key', 64);
            $table->boolean('hidden')->default(false);
            $table->unsignedSmallInteger('sort_order')->nullable();
            $table->string('label_override', 40)->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'audience', 'navigation_key']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE school_navigation_preferences
                ADD CONSTRAINT school_navigation_preferences_audience_check CHECK (audience IN ('staff_web', 'guardian_portal')),
                ADD CONSTRAINT school_navigation_preferences_key_check CHECK (navigation_key ~ '^[a-z0-9][a-z0-9-]{0,63}$'),
                ADD CONSTRAINT school_navigation_preferences_order_check CHECK (sort_order IS NULL OR sort_order <= 999),
                ADD CONSTRAINT school_navigation_preferences_label_check CHECK (
                    label_override IS NULL OR (btrim(label_override) <> '' AND label_override !~ '[<>]' AND label_override !~ '[[:cntrl:]]')),
                ADD CONSTRAINT school_navigation_preferences_override_check CHECK (hidden OR sort_order IS NOT NULL OR label_override IS NOT NULL)
            SQL);
        TenantRls::enable('school_navigation_preferences');

        Schema::create('school_group_brand_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_group_id')->unique()->constrained('school_groups')->restrictOnDelete();
            $table->string('display_label', 60)->nullable();
            $table->char('primary_accent', 7)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        DB::statement(<<<'SQL'
            ALTER TABLE school_group_brand_profiles
                ADD CONSTRAINT school_group_brand_profiles_accent_check CHECK (primary_accent IS NULL OR primary_accent ~ '^#[0-9A-F]{6}$'),
                ADD CONSTRAINT school_group_brand_profiles_label_check CHECK (
                    display_label IS NULL OR (btrim(display_label) <> '' AND display_label !~ '[<>]' AND display_label !~ '[[:cntrl:]]'))
            SQL);
        TenantRls::revokeDelete('school_group_brand_profiles');

        Schema::create('school_brand_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->unique()->constrained('schools')->restrictOnDelete();
            $table->uuid('brand_source_group_id')->nullable();
            $table->string('display_label', 60)->nullable();
            $table->char('primary_accent', 7)->nullable();
            $table->timestamps();
            $table->index('brand_source_group_id');
        });
        DB::statement(<<<'SQL'
            ALTER TABLE school_brand_profiles
                ADD CONSTRAINT school_brand_profiles_source_membership_fk FOREIGN KEY (brand_source_group_id, school_id)
                    REFERENCES school_group_members (school_group_id, school_id) ON DELETE SET NULL (brand_source_group_id),
                ADD CONSTRAINT school_brand_profiles_accent_check CHECK (primary_accent IS NULL OR primary_accent ~ '^#[0-9A-F]{6}$'),
                ADD CONSTRAINT school_brand_profiles_label_check CHECK (
                    display_label IS NULL OR (btrim(display_label) <> '' AND display_label !~ '[<>]' AND display_label !~ '[[:cntrl:]]'))
            SQL);
        TenantRls::enable('school_brand_profiles');
    }

    public function down(): void
    {
        TenantRls::disable('school_brand_profiles');
        Schema::dropIfExists('school_brand_profiles');
        Schema::dropIfExists('school_group_brand_profiles');
        TenantRls::disable('school_navigation_preferences');
        Schema::dropIfExists('school_navigation_preferences');
    }
};
