<?php

namespace Tests\Unit\Observability;

use App\Support\Observability\OperationalStatus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0C.4 section 51: the fixed severity ordering
 * (Unhealthy > Degraded > Unknown > Healthy) that both readiness and
 * the internal diagnostics endpoint rely on to aggregate many
 * component checks into one overall status.
 */
class OperationalStatusTest extends TestCase
{
    #[Test]
    public function the_worst_status_wins_regardless_of_input_order(): void
    {
        $this->assertSame(
            OperationalStatus::Unhealthy,
            OperationalStatus::worstOf([OperationalStatus::Healthy, OperationalStatus::Unhealthy, OperationalStatus::Degraded]),
        );

        $this->assertSame(
            OperationalStatus::Degraded,
            OperationalStatus::worstOf([OperationalStatus::Healthy, OperationalStatus::Degraded, OperationalStatus::Unknown]),
        );

        $this->assertSame(
            OperationalStatus::Unknown,
            OperationalStatus::worstOf([OperationalStatus::Healthy, OperationalStatus::Unknown]),
        );
    }

    #[Test]
    public function an_empty_list_is_healthy_by_default(): void
    {
        $this->assertSame(OperationalStatus::Healthy, OperationalStatus::worstOf([]));
    }

    #[Test]
    public function all_healthy_is_healthy(): void
    {
        $this->assertSame(
            OperationalStatus::Healthy,
            OperationalStatus::worstOf([OperationalStatus::Healthy, OperationalStatus::Healthy]),
        );
    }
}
