<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\CommunicationTemplateService;
use App\Domain\Communications\Domain\CommunicationPriority;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.4 §41: Template domain lifecycle -- create, update,
 * activate/deactivate. Tenant isolation for this table is proven
 * separately at the raw-SQL/RLS layer
 * (Tests\Feature\Postgres\CommunicationTemplatesRlsIsolationTest);
 * "editing a template does not mutate an existing announcement" is
 * proven end-to-end in AnnouncementSchedulingTest, since it is
 * inherently a cross-aggregate invariant.
 */
class CommunicationTemplateServiceTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function service(): CommunicationTemplateService
    {
        return app(CommunicationTemplateService::class);
    }

    #[Test]
    public function creating_a_template_persists_it_as_active(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');

        $template = $this->service()->create(
            $school, $creator, 'School Closure Notice', 'School closes at 1 PM today.',
            description: 'For weather/emergency closures.', subject: 'School Closure',
            priority: CommunicationPriority::Urgent,
        );

        $this->assertSame('School Closure Notice', $template->name);
        $this->assertSame('active', $template->status);
        $this->assertSame('announcement', $template->template_type);
        $this->assertSame('urgent', $template->priority);
        $this->assertSame($school->id, $template->school_id);
    }

    #[Test]
    public function updating_a_template_changes_its_content(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $template = $this->service()->create($school, $creator, 'Old Name', 'Old body');

        $updated = $this->service()->update($template, $creator, name: 'New Name');

        $this->assertSame('New Name', $updated->name);
        $this->assertSame('Old body', $updated->body);
    }

    #[Test]
    public function deactivating_and_reactivating_a_template_toggles_its_status(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $template = $this->service()->create($school, $creator, 'Name', 'Body');

        $deactivated = $this->service()->setActive($template, $creator, false);
        $this->assertSame('inactive', $deactivated->status);
        $this->assertFalse($deactivated->isActive());

        $reactivated = $this->service()->setActive($deactivated, $creator, true);
        $this->assertSame('active', $reactivated->status);
        $this->assertTrue($reactivated->isActive());
    }
}
