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
        $this->call(CapabilityAndRoleSeeder::class);
        $this->call(ServiceIdentitySeeder::class);
        $this->call(EducationBoardSeeder::class);
        $this->call(StatutoryRuleVersionSeeder::class);

        // Local development convenience only -- not real school data.
        if (app()->environment(['local', 'testing'])) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        }
    }
}
