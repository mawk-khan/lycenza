<?php

namespace Tests\Feature\Automation;

use App\Domain\Automation\Application\AutomationReadService;
use App\Domain\Automation\Infrastructure\AutomationExecution;
use App\Domain\Automation\Infrastructure\AutomationReviewItem;
use App\Domain\Automation\Infrastructure\AutomationRuleInstance;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * LC-3 (M3): the Automation page names each item's record ("Academic year
 * 2027-28"), resolved for the viewer's School only -- never the internal
 * id, and never another School's name.
 */
class AutomationSubjectLabelsTest extends TestCase
{
    use CreatesTenancyFixtures;

    private const UUID = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i';

    private function reviewItemFor(School $school, string $subjectId): void
    {
        app(TenantContext::class)->withSchool($school, function () use ($school, $subjectId): void {
            $instance = AutomationRuleInstance::query()->firstOrCreate(
                ['school_id' => $school->id, 'rule_type' => 'academic_year.setup_review'],
                ['status' => 'enabled', 'owner_user_id' => $this->createUser()->id, 'enabled_at' => now()],
            );
            $execution = AutomationExecution::query()->create([
                'school_id' => $school->id, 'rule_instance_id' => $instance->id, 'trigger_key' => (string) Str::uuid7(),
                'trigger_event_type' => 'academic_year.activated.v1', 'subject_type' => 'academic_year',
                'subject_id' => $subjectId, 'status' => 'succeeded', 'attempts' => 1,
            ]);
            AutomationReviewItem::query()->create([
                'school_id' => $school->id, 'rule_instance_id' => $instance->id, 'execution_id' => $execution->id,
                'item_type' => 'academic_year_setup_review', 'subject_type' => 'academic_year', 'subject_id' => $subjectId,
            ]);
        });
    }

    #[Test]
    public function items_name_their_academic_year_and_an_unresolvable_one_reads_neutrally(): void
    {
        $school = $this->createSchool();
        $other = $this->createSchool();
        $year = $this->createAcademicYear($school, ['code' => 'AY2027', 'name' => '2027-28']);
        $foreignYear = $this->createAcademicYear($other, ['code' => 'AYX', 'name' => 'Other School Year']);

        $this->reviewItemFor($school, $year->id);
        $this->reviewItemFor($school, $foreignYear->id); // crafted: another School's year
        $this->reviewItemFor($school, (string) Str::uuid7()); // gone

        $viewer = $this->createUserWithCapabilities($school, [AutomationReadService::VIEW_CAPABILITY]);
        $this->actingAs($viewer)->post("/app/schools/{$school->id}/activate");
        $overview = $this->get('/app/automation')->assertOk()->viewData('page')['props']['automation'];

        $labels = array_column($overview['reviewItems'], 'subjectLabel');
        sort($labels);
        $this->assertSame([
            'Academic year (no longer available)',
            'Academic year (no longer available)',
            'Academic year 2027-28',
        ], $labels);
        $this->assertSame($labels, array_values(array_filter($labels, fn (string $l) => preg_match(self::UUID, $l) === 0)));
        $this->assertNotContains('Academic year Other School Year', array_column($overview['executions'], 'subjectLabel'));
        $this->assertContains('Academic year 2027-28', array_column($overview['executions'], 'subjectLabel'));
    }

    #[Test]
    public function the_page_still_needs_the_view_capability(): void
    {
        $school = $this->createSchool();
        $this->reviewItemFor($school, $this->createAcademicYear($school)->id);
        $user = $this->createUserWithCapabilities($school, ['academics.years.view']);
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        $this->get('/app/automation')->assertForbidden();
    }
}
