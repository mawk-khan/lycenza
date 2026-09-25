<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    // Deliberately NOT using WithoutModelEvents: GeneratesUuidV7 and
    // BelongsToSchool both rely on Eloquent's `creating` model event to
    // assign primary keys / school_id (ADR 0019). Disabling model
    // events here would silently break seeded UUIDs.
    public function run(): void
    {
        // Production-safe reference catalogs (Phase 0O.1 release contract:
        // docs/architecture/PRODUCTION-RELEASE.md) -- idempotent, no accounts,
        // no credentials, no School data.
        $this->call(CapabilityAndRoleSeeder::class);
        $this->call(EducationBoardSeeder::class);
        $this->call(StatutoryRuleVersionSeeder::class);

        // Local development / test conveniences only -- never in a real
        // deployment: the development AI Gateway identity (from the public
        // dev token) and a test user.
        if (app()->environment(['local', 'testing'])) {
            $this->call(ServiceIdentitySeeder::class);

            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        }
    }
}
