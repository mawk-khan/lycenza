<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ORG.12C (ADR 0080 §8): the Campus profile gets the SAME structured address
 * the School profile already has (address_line1/2, city, state_region,
 * postal_code, country_code -- one address shape, no second framework, no
 * street/area/locality master) and optional coordinates.
 *
 * - The free-text `campuses.address` is replaced: each existing value moves
 *   to `address_line1` unchanged. A value longer than 255 characters cannot
 *   move losslessly, so the migration refuses rather than truncate.
 * - `country_code` is nullable (NULL = the School's country) and, when set,
 *   two upper-case letters (ISO 3166-1 alpha-2 shape).
 * - Coordinates: numeric(7,5) / numeric(8,5) -- five decimals, about 1 m,
 *   deliberately not survey precision. Both or neither, within range.
 *   Location metadata only: no geocoder, no tracking, no map provider.
 * - No Campus timezone or currency (OD-12.3, OD-12.4): the School timezone
 *   and Finance's INR-only scope stay canonical.
 *
 * The backfill reads and writes every School's Campuses, and `campuses` is
 * FORCE RLS: on a role without RLS bypass it would silently see no row and
 * then drop the column. Both directions therefore refuse unless the
 * migration role bypasses RLS (ADR 0021; the ORG.7G-1A precedent).
 *
 * down() restores `address` from the structured parts (joined with ", ").
 * The text is kept; the split into parts is not -- acceptable for a
 * development-only rollback, and stated here per rule 10.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->assertBypassesRls();
        $tooLong = DB::table('campuses')->whereRaw('char_length(address) > 255')->count();
        if ($tooLong > 0) {
            throw new RuntimeException("{$tooLong} Campus address(es) exceed 255 characters; shorten them before migrating (no truncation).");
        }

        Schema::table('campuses', function (Blueprint $table) {
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city', 120)->nullable();
            $table->string('state_region', 120)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->decimal('latitude', 7, 5)->nullable();
            $table->decimal('longitude', 8, 5)->nullable();
        });

        DB::statement('UPDATE campuses SET address_line1 = address WHERE address IS NOT NULL');

        Schema::table('campuses', function (Blueprint $table) {
            $table->dropColumn('address');
        });

        DB::statement("ALTER TABLE campuses ADD CONSTRAINT campuses_country_code_check CHECK (country_code IS NULL OR country_code ~ '^[A-Z]{2}$')");
        DB::statement('ALTER TABLE campuses ADD CONSTRAINT campuses_coordinates_pair_check CHECK ((latitude IS NULL) = (longitude IS NULL))');
        DB::statement('ALTER TABLE campuses ADD CONSTRAINT campuses_coordinates_range_check CHECK ((latitude IS NULL OR latitude BETWEEN -90 AND 90) AND (longitude IS NULL OR longitude BETWEEN -180 AND 180))');
    }

    public function down(): void
    {
        $this->assertBypassesRls();
        DB::statement('ALTER TABLE campuses DROP CONSTRAINT IF EXISTS campuses_coordinates_range_check');
        DB::statement('ALTER TABLE campuses DROP CONSTRAINT IF EXISTS campuses_coordinates_pair_check');
        DB::statement('ALTER TABLE campuses DROP CONSTRAINT IF EXISTS campuses_country_code_check');

        Schema::table('campuses', function (Blueprint $table) {
            $table->text('address')->nullable();
        });

        DB::statement("UPDATE campuses SET address = NULLIF(concat_ws(', ', address_line1, address_line2, city, state_region, postal_code, country_code), '')");

        Schema::table('campuses', function (Blueprint $table) {
            $table->dropColumn(['address_line1', 'address_line2', 'city', 'state_region', 'postal_code', 'country_code', 'latitude', 'longitude']);
        });
    }

    private function assertBypassesRls(): void
    {
        $role = DB::selectOne('SELECT rolsuper OR rolbypassrls AS bypass FROM pg_roles WHERE rolname = current_user');
        if ($role === null || ! $role->bypass) {
            throw new RuntimeException('ORG.12C: this migration must run on the admin connection with RLS bypass (ADR 0021); nothing was changed.');
        }
    }
};
