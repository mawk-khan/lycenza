<?php

namespace Tests\Feature\Analytics\Fixtures;

use App\Domain\Analytics\Application\AnalyticsReadModel;
use App\Domain\Analytics\Application\ClassificationTier;
use App\Domain\Analytics\Application\ReadModelDeclaration;
use App\Models\School;
use App\Support\Tenancy\TenantContext;

/**
 * Test-only read model with a configurable declaration. compute()
 * records that it ran and the tenant context it ran under, so a test
 * can prove a refused read never touched source data. It never lives in
 * app/ and is never in AnalyticsReadModelRegistry::READ_MODELS.
 */
class RecordingReadModel implements AnalyticsReadModel
{
    public int $computed = 0;

    public ?string $contextSchoolId = null;

    public function __construct(
        private readonly bool $countsPeople = false,
        private readonly ClassificationTier $tier = ClassificationTier::Confidential,
    ) {}

    public function declaration(): ReadModelDeclaration
    {
        return new ReadModelDeclaration(
            key: 'test.recording',
            tier: $this->tier,
            countsPeople: $this->countsPeople,
            sourceModules: ['TestFixture'],
            filters: ['academic_year_id'],
        );
    }

    public function compute(School $school, array $filters): array
    {
        $this->computed++;
        $this->contextSchoolId = app(TenantContext::class)->schoolId();

        return ['value' => 42, 'filters' => $filters];
    }
}
