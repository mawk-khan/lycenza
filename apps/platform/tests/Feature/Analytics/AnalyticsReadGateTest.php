<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Application\AnalyticsReadGate;
use App\Domain\Analytics\Application\AnalyticsReadModelRegistry;
use App\Domain\Analytics\Application\ClassificationTier;
use App\Domain\Analytics\Application\Exceptions\AnalyticsReportUnavailableException;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Analytics\Fixtures\RecordingReadModel;
use Tests\TestCase;

/**
 * Phase 0L.2-1 -- AnalyticsReadGate, the single execution path for
 * every Analytics read model: authorization first, then the fail-closed
 * cohort policy, then the registry and filter restriction -- all
 * BEFORE compute() touches any source data -- then School-scoped
 * execution and tier-driven audit.
 */
class AnalyticsReadGateTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function registerFixture(): void
    {
        $this->app->instance(AnalyticsReadModelRegistry::class, new AnalyticsReadModelRegistry([RecordingReadModel::class]));
    }

    private function gate(): AnalyticsReadGate
    {
        return app(AnalyticsReadGate::class);
    }

    private function auditCount(object $school): int
    {
        return app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()
            ->where('event_type', 'analytics.report_viewed')->count());
    }

    #[Test]
    public function an_actor_without_analytics_view_is_refused_before_compute(): void
    {
        $this->registerFixture();
        $school = $this->createSchool();
        $model = new RecordingReadModel;

        foreach ([[], ['curriculum.delivery.view', 'curriculum.delivery.manage', 'syllabus.view'], ['analytics.export']] as $capabilities) {
            $actor = $this->createUserWithCapabilities($school, $capabilities);

            try {
                $this->gate()->read($model, $school, $actor);
                $this->fail('Expected 403 for capabilities: '.implode(',', $capabilities));
            } catch (AuthorizationException) {
                // Source-record access and analytics.export never imply analytics.view (ADR 0040 §5).
            }
        }

        $this->assertSame(0, $model->computed);
    }

    #[Test]
    public function analytics_view_in_another_school_or_a_suspended_membership_is_refused(): void
    {
        $this->registerFixture();
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $model = new RecordingReadModel;

        $viewerOfB = $this->createUserWithCapabilities($schoolB, ['analytics.view']);
        $suspended = $this->createUserWithCapabilities($schoolA, ['analytics.view'], 'suspended');

        foreach ([$viewerOfB, $suspended] as $actor) {
            try {
                $this->gate()->read($model, $schoolA, $actor);
                $this->fail('Expected 403.');
            } catch (AuthorizationException) {
            }
        }

        $this->assertSame(0, $model->computed);
    }

    #[Test]
    public function a_person_counting_read_model_fails_closed_before_compute_while_the_policy_is_unset(): void
    {
        $this->registerFixture();
        $school = $this->createSchool();
        $actor = $this->createUserWithCapabilities($school, ['analytics.view']);
        $model = new RecordingReadModel(countsPeople: true);

        try {
            $this->gate()->read($model, $school, $actor);
            $this->fail('A person-counting read model must not execute while no minimum cohort size is approved.');
        } catch (AnalyticsReportUnavailableException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }

        $this->assertSame(0, $model->computed, 'The real aggregate must never be computed and then hidden later.');
        $this->assertSame(0, $this->auditCount($school));
    }

    #[Test]
    public function a_person_counting_read_model_fails_closed_even_when_unregistered(): void
    {
        // Default registry: the fixture is NOT registered -- the cohort
        // refusal still comes first, from the declaration alone.
        $school = $this->createSchool();
        $actor = $this->createUserWithCapabilities($school, ['analytics.view']);

        $this->expectExceptionMessage('counts people');
        $this->gate()->read(new RecordingReadModel(countsPeople: true), $school, $actor);
    }

    #[Test]
    public function an_unregistered_read_model_is_refused(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUserWithCapabilities($school, ['analytics.view']);
        $model = new RecordingReadModel;

        try {
            $this->gate()->read($model, $school, $actor);
            $this->fail('Expected an unregistered read model to be refused.');
        } catch (AnalyticsReportUnavailableException $e) {
            $this->assertStringContainsString('not registered', $e->getMessage());
        }

        $this->assertSame(0, $model->computed);
    }

    #[Test]
    public function a_non_person_read_model_runs_inside_the_requested_school_and_restores_context(): void
    {
        $this->registerFixture();
        $school = $this->createSchool();
        $other = $this->createSchool();
        $actor = $this->createUserWithCapabilities($school, ['analytics.view']);
        $model = new RecordingReadModel;
        $context = app(TenantContext::class);

        $result = $context->withSchool($other, fn () => $this->gate()->read($model, $school, $actor, ['academic_year_id' => 'x']));

        $this->assertSame(['value' => 42, 'filters' => ['academic_year_id' => 'x']], $result);
        $this->assertSame(1, $model->computed);
        $this->assertSame($school->id, $model->contextSchoolId);
        $this->assertNull($context->schoolId());
    }

    #[Test]
    public function undeclared_filters_are_refused_before_compute(): void
    {
        $this->registerFixture();
        $school = $this->createSchool();
        $actor = $this->createUserWithCapabilities($school, ['analytics.view']);
        $model = new RecordingReadModel;

        try {
            $this->gate()->read($model, $school, $actor, ['school_id' => $school->id]);
            $this->fail('Expected an undeclared filter to be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('school_id', $e->getMessage());
        }

        $this->assertSame(0, $model->computed);
    }

    #[Test]
    public function sensitive_and_highly_sensitive_reads_are_audited_confidential_reads_are_not(): void
    {
        $this->registerFixture();
        $school = $this->createSchool();
        $actor = $this->createUserWithCapabilities($school, ['analytics.view']);

        $this->gate()->read(new RecordingReadModel, $school, $actor);
        $this->assertSame(0, $this->auditCount($school));

        $this->gate()->read(new RecordingReadModel(tier: ClassificationTier::Sensitive), $school, $actor, ['academic_year_id' => 'y1']);
        $this->gate()->read(new RecordingReadModel(tier: ClassificationTier::HighlySensitive), $school, $actor);
        $this->assertSame(2, $this->auditCount($school));

        $event = app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()
            ->where('event_type', 'analytics.report_viewed')->orderBy('occurred_at')->orderBy('id')->firstOrFail());
        $this->assertSame($actor->id, $event->actor_user_id);
        // jsonb does not preserve key order.
        $this->assertEquals(['readModel' => 'test.recording', 'filters' => ['academic_year_id' => 'y1']], $event->metadata);
    }
}
