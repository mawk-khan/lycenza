<?php

namespace App\Support\Observability\Metrics;

/**
 * Phase 0O.5A: the storage/exposition key of one time series --
 * `name|k=v,k=v` with labels in catalog order. Label values are from the
 * catalog's closed sets, so no escaping ambiguity arises.
 */
final class Series
{
    /**
     * @param  array<string, string>  $labels
     */
    public static function key(string $name, array $labels = []): string
    {
        $pairs = [];
        foreach ($labels as $k => $v) {
            $pairs[] = $k.'='.$v;
        }

        return $name.'|'.implode(',', $pairs);
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    public static function parse(string $key): array
    {
        [$name, $encoded] = array_pad(explode('|', $key, 2), 2, '');
        $labels = [];

        foreach (array_filter(explode(',', $encoded)) as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $labels[$k] = $v;
        }

        return [$name, $labels];
    }
}
