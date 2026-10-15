<?php

namespace App\Http\Controllers\App\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * UAT-C2 (UAT-YR-01b): after a decision is saved -- one Student or many --
 * the operator returns to the same view of the plan they were working in:
 * the same Items page, page size and filters. The page sends that view as
 * `context`; only these known keys and values are carried back, anything
 * else is dropped (never an open redirect).
 */
trait ReturnsToRolloverPlanContext
{
    private function rolloverPlanUrl(Request $request, string $planId): string
    {
        $context = $request->input('context');
        if (! is_array($context)) {
            return "/app/enrollment-rollovers/{$planId}";
        }

        $valid = Validator::make($context, [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', Rule::in(['20', '50', '100', 20, 50, 100])],
            'validation_result' => ['sometimes', Rule::in(['ready', 'excluded', 'already_enrolled', 'review', 'blocked'])],
            'execution_status' => ['sometimes', Rule::in(['succeeded', 'reconciled', 'skipped', 'failed'])],
            'decision' => ['sometimes', Rule::in(['undecided', 'promote', 'repeat', 'exclude', 'manual_review'])],
        ]);
        $query = $valid->fails() ? [] : array_filter($valid->validated(), fn ($v) => $v !== null && $v !== '');

        return "/app/enrollment-rollovers/{$planId}".($query === [] ? '' : '?'.http_build_query($query));
    }
}
