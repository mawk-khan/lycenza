<?php

namespace App\Support\Observability\Metrics;

use Illuminate\Support\Facades\Redis;

final class RedisMetricStore implements MetricStore
{
    public function __construct(private readonly string $connection, private readonly string $key) {}

    public function increment(string $series, float $by): void
    {
        Redis::connection($this->connection)->hincrbyfloat($this->key, $series, $by);
    }

    public function set(string $series, float $value): void
    {
        Redis::connection($this->connection)->hset($this->key, $series, (string) $value);
    }

    public function all(): array
    {
        $values = Redis::connection($this->connection)->hgetall($this->key);

        return array_map('floatval', is_array($values) ? $values : []);
    }

    public function flush(): void
    {
        Redis::connection($this->connection)->del($this->key);
    }
}
