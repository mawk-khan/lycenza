<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Minimum person-cohort size
    |--------------------------------------------------------------------------
    |
    | The smallest number of PEOPLE an Analytics cell (or denominator) may
    | count before it must be suppressed (ADR 0040 §6). Deliberately NO
    | default: no value has been approved -- it is an open
    | [LEGAL/PRODUCT/SECURITY REVIEW REQUIRED] decision
    | (docs/security/ANALYTICS-SMALL-COHORT-POLICY-GATE.md). While it is
    | unset, every Analytics read model that counts people fails closed
    | (App\Domain\Analytics\Application\CohortSuppressionPolicy). Read
    | models that count no people (e.g. syllabus-unit coverage) are not
    | affected. Do not set this until the decision is recorded.
    |
    */

    // Raw value; validated (positive whole number) by CohortSuppressionPolicy.
    'minimum_person_cohort_size' => env('ANALYTICS_MINIMUM_PERSON_COHORT_SIZE'),

];
