<?php

namespace App\Domain\Analytics\Application;

use App\Domain\Analytics\Application\ReadModels\CurriculumCoverageReadModel;

/**
 * The closed catalog of Analytics read models this deployment serves,
 * in the same spirit as App\Support\Webhooks\WebhookEventRegistry: a
 * read model that is not listed here is refused by AnalyticsReadGate,
 * and Tests\Feature\Analytics\AnalyticsArchitectureGuardTest fails if a
 * class implementing AnalyticsReadModel exists without being listed, or
 * if a listed one counts people.
 *
 * Adding an entry is a reviewed decision: its declaration's tier,
 * countsPeople flag and filters are part of that review (ADR 0040 §6).
 */
final class AnalyticsReadModelRegistry
{
    /** @var list<class-string<AnalyticsReadModel>> */
    public const READ_MODELS = [
        CurriculumCoverageReadModel::class,
    ];

    /** @var list<class-string<AnalyticsReadModel>> */
    private readonly array $readModels;

    /**
     * @param  list<class-string<AnalyticsReadModel>>|null  $readModels  tests only; production always uses READ_MODELS
     */
    public function __construct(?array $readModels = null)
    {
        $this->readModels = $readModels ?? self::READ_MODELS;
    }

    public function isRegistered(AnalyticsReadModel $readModel): bool
    {
        return in_array($readModel::class, $this->readModels, true);
    }
}
