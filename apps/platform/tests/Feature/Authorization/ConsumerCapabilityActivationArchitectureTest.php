<?php

namespace Tests\Feature\Authorization;

use App\Domain\Identity\Application\Staff\StaffRoleCatalog;
use App\Models\Role;
use App\Support\Authorization\Campus\CampusScopeCatalogue;
use App\Support\Authorization\CapabilityClasses;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Authorization\ConsumerCapabilities;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.10-R1 (ADR 0076 §79, OD-9.7): rule 101's one exception is exactly two
 * scope-independent consumer keys, activated School-wide by a Campus grant.
 * Adding a third key, a wildcard or a second reader fails here until it is
 * reviewed; no other capability of any Campus grant reaches a whole-School
 * check.
 */
class ConsumerCapabilityActivationArchitectureTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function the_exception_is_exactly_the_two_consumer_keys(): void
    {
        $this->assertSame(['communications.events.view', 'communications.inbox.view'], ConsumerCapabilities::SCOPE_INDEPENDENT);
        foreach (ConsumerCapabilities::SCOPE_INDEPENDENT as $key) {
            $this->assertFalse(CampusScopeCatalogue::isAdopted($key), "{$key} is never Campus-adopted (no Campus filtering of own content).");
            $this->assertSame(['operational'], CapabilityClasses::CLASSES[$key], "{$key} stays a consumption-only capability.");
        }
        $this->assertSame([], ConsumerCapabilities::scopeIndependentAmong(['communications.view', 'communications.announce', 'communications.approve', 'communications.manage', 'communications.reply', 'communications.*']));
    }

    #[Test]
    public function only_the_resolver_reads_the_classification(): void
    {
        $readers = [];
        foreach ((new Finder)->files()->in(base_path('app'))->name('*.php') as $file) {
            if (str_contains($file->getContents(), 'ConsumerCapabilities::')) {
                $readers[] = 'app/'.$file->getRelativePathname();
            }
        }
        $this->assertSame(['app/Support/Authorization/CapabilityResolver.php'], $readers);
        $resolver = (string) file_get_contents(base_path('app/Support/Authorization/CapabilityResolver.php'));
        $this->assertSame(1, substr_count($resolver, 'ConsumerCapabilities::scopeIndependentAmong($keys)'), 'One activation point, inside grants().');
        $holdsNow = substr($resolver, (int) strpos($resolver, 'private function holdsNow('));
        $this->assertStringNotContainsString('ConsumerCapabilities', substr($holdsNow, 0, (int) strpos($holdsNow, 'public function can(')), 'Approval-time authority (holdsInSchoolNow) is unchanged.');
    }

    #[Test]
    public function no_campus_grantable_role_escalates_beyond_the_consumer_keys(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school, ['status' => 'active']);
        $resolver = app(CapabilityResolver::class);
        $checked = 0;
        foreach (Role::query()->where('scope', 'school')->whereNull('retired_at')->with('capabilities')->get() as $role) {
            if (! StaffRoleCatalog::campusScopable($role)) {
                continue;
            }
            $user = $this->createUser();
            $this->assignSchoolRole($this->createMembership($user, $school), $role->key, $campus->id);
            $whole = $resolver->schoolCapabilities($user, $school);
            sort($whole);
            $expected = ConsumerCapabilities::scopeIndependentAmong($role->capabilities->pluck('key')->all());
            sort($expected);
            $this->assertSame($expected, $whole, "{$role->key}@Campus activates School-wide only the consumer keys it carries.");
            $checked++;
        }
        $this->assertGreaterThanOrEqual(10, $checked, 'The guard must keep covering the real Campus-grantable catalogue.');
    }
}
