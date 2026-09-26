<?php

namespace App\Support\Observability\Alerts;

use App\Support\Observability\Metrics\Series;

/**
 * Phase 0O.5A (ADR 0051 §14): the metric values an alert condition is
 * evaluated against -- instant gauge/counter values plus precomputed
 * windowed increases (what the backend's `increase()`/`rate()` would
 * return), at a fixed "now". Built from plain arrays, so every alert
 * condition is testable without any monitoring backend.
 */
final class MetricSnapshot
{
    /**
     * @param  array<string, float>  $values  series key (Series::key) => value
     * @param  array<string, float>  $increases  "{window}|{series key}" => increase over that window
     */
    public function __construct(
        public readonly int $now,
        private readonly array $values = [],
        private readonly array $increases = [],
        public readonly bool $scrapeUp = true,
    ) {}

    /**
     * @param  array<string, string>  $labels
     */
    public function value(string $name, array $labels = []): ?float
    {
        return $this->values[Series::key($name, $labels)] ?? null;
    }

    /**
     * Every series of a metric whose labels include $match.
     *
     * @param  array<string, string>  $match
     * @return list<array{labels: array<string, string>, value: float}>
     */
    public function all(string $name, array $match = []): array
    {
        $found = [];
        foreach ($this->values as $key => $value) {
            [$series, $labels] = Series::parse($key);
            if ($series === $name && array_intersect_assoc($match, $labels) === $match) {
                $found[] = ['labels' => $labels, 'value' => $value];
            }
        }

        return $found;
    }

    /**
     * Sum of increases over $window for every series of $name matching $match.
     *
     * @param  array<string, string>  $match
     */
    public function increase(string $name, string $window, array $match = []): float
    {
        $total = 0.0;
        foreach ($this->increases as $key => $value) {
            [$w, $series] = explode('|', $key, 2);
            [$seriesName, $labels] = Series::parse($series);
            if ($w === $window && $seriesName === $name && array_intersect_assoc($match, $labels) === $match) {
                $total += $value;
            }
        }

        return $total;
    }

    /** Age in seconds of a timestamp gauge, or null when absent. */
    public function age(string $name, array $labels = []): ?float
    {
        $at = $this->value($name, $labels);

        return $at === null ? null : max(0.0, $this->now - $at);
    }
}
