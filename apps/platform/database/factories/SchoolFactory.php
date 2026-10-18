<?php

namespace Database\Factories;

use App\Domain\Platform\Application\Modules\ModuleCatalogue;
use App\Domain\Platform\Application\Modules\SchoolModuleEntitlements;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @extends Factory<School>
 */
class SchoolFactory extends Factory
{
    protected $model = School::class;

    public function definition(): array
    {
        $name = fake()->company().' School';

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 999999),
            'status' => 'active',
            'timezone' => 'Asia/Kolkata',
            'default_locale' => 'en',
        ];
    }

    /**
     * ORG.5 (ADR 0076 §31): a factory School stands for an existing,
     * pre-ORG.5 School -- every optional module enabled, exactly what the
     * entitlement backfill gave every real School. A School created through
     * onboarding (SchoolLifecycleService) gets core modules only; use
     * withCoreModulesOnly() for that state.
     */
    public function configure(): static
    {
        return $this->afterCreating(fn (School $school) => self::setOptionalModules($school, true));
    }

    public function withCoreModulesOnly(): static
    {
        return $this->afterCreating(fn (School $school) => self::setOptionalModules($school, false));
    }

    /** Test/seed fixture only: the runtime path is SchoolModuleEntitlementService. */
    public static function setOptionalModules(School $school, bool $enabled, ?array $modules = null): void
    {
        $rows = array_map(fn (string $module): array => [
            'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'module_key' => $module, 'enabled' => $enabled,
            'reason_code' => 'pre_org5_backfill', 'changed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], $modules ?? ModuleCatalogue::optionalKeys());

        app(TenantContext::class)->withSchool($school, fn () => DB::table('school_module_entitlements')
            ->upsert($rows, ['school_id', 'module_key'], ['enabled', 'changed_at', 'updated_at']));
        app(SchoolModuleEntitlements::class)->forget($school->id);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => 'suspended']);
    }

    /**
     * Phase 0N.9 (ADR 0047): created, never activated. The definition
     * above stays explicitly `active` -- ordinary tests need an
     * operational School and must not rely on the column default, which
     * is now `provisioning`.
     */
    public function provisioning(): static
    {
        return $this->state(fn () => ['status' => 'provisioning']);
    }
}
