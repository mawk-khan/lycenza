<?php

namespace Tests\Feature\Platform\Modules;

use App\Domain\Platform\Application\Modules\ModuleCatalogue;
use App\Domain\Platform\Application\Modules\SchoolModuleEntitlements;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\Demo\DemoDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ORG.5 (ADR 0076 §22.3, §31.3; ORG-D13), release-blocking: a pre-ORG.5
 * School (the demo world: real roles, Guardians, teachers, domain data)
 * keeps exactly the effective capabilities it had before entitlements
 * existed -- for every member, in both demo Schools.
 */
class ExistingSchoolCompatibilityTest extends TestCase
{
    #[Test]
    public function every_demo_member_keeps_exactly_their_pre_org5_capabilities(): void
    {
        $world = app(DemoDataBuilder::class)->build();
        $context = app(TenantContext::class);
        $resolver = app(CapabilityResolver::class);

        // The resolver as it was before ORG.5: no module intersection.
        $unfiltered = new CapabilityResolver($context, new class($context) extends SchoolModuleEntitlements
        {
            public function entitledModules(School $school): array
            {
                return ModuleCatalogue::keys();
            }
        });

        $checked = 0;
        foreach ([$world->school, $world->secondSchool] as $school) {
            $this->assertEqualsCanonicalizing(ModuleCatalogue::keys(), app(SchoolModuleEntitlements::class)->entitledModules($school));

            $userIds = $context->withSchool($school, fn () => SchoolMembership::query()->where('school_id', $school->id)->where('status', 'active')->pluck('user_id')->all());
            foreach (User::query()->whereIn('id', $userIds)->get() as $user) {
                $before = $unfiltered->schoolCapabilities($user, $school);
                $this->assertSame($before, $resolver->schoolCapabilities($user, $school), "{$user->email} in {$school->name}");
                $checked += $before === [] ? 0 : 1;
            }
        }

        $this->assertGreaterThan(10, $checked, 'The demo world has many members with capabilities.');
    }
}
