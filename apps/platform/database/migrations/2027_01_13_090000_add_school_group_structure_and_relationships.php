<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ORG.12D (ADR 0080 §9): Network governance structure on the EXISTING School
 * Group model (OD-12.8: a School Group is the Network; no second entity).
 *
 * - `school_group_regions` and `school_group_zones`: optional, Group-owned
 *   governance classifications (OD-12.10). A Zone may sit under a Region of
 *   the same Group. Never a tenant, never an authorization scope.
 * - `school_group_members` (the existing many-to-many Group <-> School pair,
 *   ADR 0045 §7) gains the relationship classification (OD-12.9: owned,
 *   franchise, managed, affiliate), an optional Region and Zone and an
 *   optional Group-specific network reference. Classification describes the
 *   pair, so it lives on the pair, never on the School.
 *
 * Like `school_groups`, these are PLATFORM tables: no `school_id`, no RLS,
 * platform-governed (ADR 0045 §5). Group boundaries are structural:
 * composite (id, school_group_id) keys keep every Region and Zone inside its
 * own Group, and a trigger keeps a member's Region equal to its Zone's.
 *
 * Legacy memberships stay valid and UNCLASSIFIED (`relationship_type` NULL):
 * nothing is assumed owned or franchised. No row is read or moved, so the
 * ORG.12C RLS-backfill hazard does not arise (and these tables have no RLS).
 * Regions and Zones are inactivated, never deleted: the runtime role has no
 * DELETE on them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_group_regions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_group_id')->constrained('school_groups')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name', 150);
            $table->string('description', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['school_group_id', 'code']);
            $table->unique(['id', 'school_group_id']);
        });

        Schema::create('school_group_zones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_group_id')->constrained('school_groups')->restrictOnDelete();
            $table->uuid('region_id')->nullable();
            $table->string('code', 32);
            $table->string('name', 150);
            $table->string('description', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['school_group_id', 'code']);
            $table->unique(['id', 'school_group_id']);
            $table->index('region_id');
            $table->foreign(['region_id', 'school_group_id'])->references(['id', 'school_group_id'])->on('school_group_regions')->restrictOnDelete();
        });

        foreach (['school_group_regions', 'school_group_zones'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_status_check CHECK (status IN ('active', 'inactive'))");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_code_check CHECK (code ~ '^[A-Z0-9][A-Z0-9_-]{0,31}$')");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_name_check CHECK (char_length(btrim(name)) > 0)");
            DB::statement("REVOKE DELETE ON {$table} FROM school_os_app");
        }

        Schema::table('school_group_members', function (Blueprint $table) {
            $table->string('relationship_type', 16)->nullable();
            $table->uuid('region_id')->nullable();
            $table->uuid('zone_id')->nullable();
            $table->string('network_reference', 40)->nullable();

            $table->index('region_id');
            $table->index('zone_id');
            $table->foreign(['region_id', 'school_group_id'])->references(['id', 'school_group_id'])->on('school_group_regions')->restrictOnDelete();
            $table->foreign(['zone_id', 'school_group_id'])->references(['id', 'school_group_id'])->on('school_group_zones')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE school_group_members ADD CONSTRAINT school_group_members_relationship_check CHECK (relationship_type IS NULL OR relationship_type IN ('owned', 'franchise', 'managed', 'affiliate'))");
        DB::statement("ALTER TABLE school_group_members ADD CONSTRAINT school_group_members_network_reference_check CHECK (network_reference IS NULL OR network_reference ~ '^[A-Z0-9][A-Z0-9._/-]{0,39}$')");
        DB::statement('CREATE UNIQUE INDEX school_group_members_network_reference_unique ON school_group_members (school_group_id, network_reference) WHERE network_reference IS NOT NULL');

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION assert_school_group_member_placement() RETURNS trigger AS $$
            DECLARE
                zone_region uuid;
            BEGIN
                IF NEW.zone_id IS NOT NULL THEN
                    SELECT region_id INTO zone_region FROM school_group_zones
                        WHERE id = NEW.zone_id AND school_group_id = NEW.school_group_id;
                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'school_group_members: the Zone belongs to another School Group';
                    END IF;
                    IF NEW.region_id IS DISTINCT FROM zone_region THEN
                        RAISE EXCEPTION 'school_group_members: the Region must be the Zone''s Region';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_school_group_members_placement
                BEFORE INSERT OR UPDATE OF school_group_id, region_id, zone_id ON school_group_members
                FOR EACH ROW EXECUTE FUNCTION assert_school_group_member_placement();

            CREATE FUNCTION assert_school_group_zone_region_move() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM school_group_members
                           WHERE zone_id = NEW.id AND region_id IS DISTINCT FROM NEW.region_id) THEN
                    RAISE EXCEPTION 'school_group_zones: move the Zone''s Schools before changing its Region';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_school_group_zones_region_move
                BEFORE UPDATE OF region_id ON school_group_zones
                FOR EACH ROW WHEN (NEW.region_id IS DISTINCT FROM OLD.region_id)
                EXECUTE FUNCTION assert_school_group_zone_region_move();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_school_group_zones_region_move ON school_group_zones;
            DROP FUNCTION IF EXISTS assert_school_group_zone_region_move();
            DROP TRIGGER IF EXISTS trg_school_group_members_placement ON school_group_members;
            DROP FUNCTION IF EXISTS assert_school_group_member_placement();
            SQL);
        DB::statement('DROP INDEX IF EXISTS school_group_members_network_reference_unique');

        Schema::table('school_group_members', function (Blueprint $table) {
            $table->dropForeign(['zone_id', 'school_group_id']);
            $table->dropForeign(['region_id', 'school_group_id']);
            $table->dropIndex(['zone_id']);
            $table->dropIndex(['region_id']);
        });
        DB::statement('ALTER TABLE school_group_members DROP CONSTRAINT IF EXISTS school_group_members_network_reference_check');
        DB::statement('ALTER TABLE school_group_members DROP CONSTRAINT IF EXISTS school_group_members_relationship_check');
        Schema::table('school_group_members', function (Blueprint $table) {
            $table->dropColumn(['relationship_type', 'region_id', 'zone_id', 'network_reference']);
        });

        Schema::dropIfExists('school_group_zones');
        Schema::dropIfExists('school_group_regions');
    }
};
