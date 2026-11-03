<?php

namespace App\Domain\Leave\Http\Requests;

use App\Domain\HR\Application\Workforce\StaffType;
use App\Domain\Leave\Application\Accrual\LeaveAccrualTiming;
use App\Domain\Leave\Application\Accrual\LeaveEntitlementMethod;
use App\Domain\Leave\Application\LeavePolicyService;
use App\Domain\Leave\Application\Policy\LeaveEligibilityCriteria;
use Illuminate\Validation\Rule;

/**
 * HR.9 (ADR 0065 §28): the ONE input shape of a leave policy version, shared
 * by the API and the web form -- typed, bounded, never mass-assigned. The
 * services validate again (and the database after them).
 */
final class LeavePolicyInput
{
    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'leave_type_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:120'],
            'annual_allocation_units' => ['required', 'integer', 'min:0', 'max:1000'],
            'carry_forward_allowed' => ['required', 'boolean'],
            'carry_forward_cap_units' => ['nullable', 'required_if_accepted:carry_forward_allowed', 'integer', 'min:1', 'max:1000'],
            'carry_forward_expiry_days' => ['nullable', 'integer', 'min:1', 'max:366'],
            'supersedes_policy_id' => ['sometimes', 'nullable', 'uuid'],
            ...self::timingRules(),
            // HR.10 (ADR 0065 §29.2): the entitlement method.
            'entitlement_method' => ['sometimes', Rule::in(array_column(LeaveEntitlementMethod::options(), 'value'))],
            'monthly_accrual_units' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:62'],
            'accrual_timing' => ['sometimes', 'nullable', Rule::in(array_column(LeaveAccrualTiming::options(), 'value'))],
            ...self::eligibilityRules(),
        ];
    }

    /** @return array<string, list<mixed>> */
    public static function timingRules(): array
    {
        return [
            'min_notice_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:366'],
            'max_backdate_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:366'],
            'max_units_per_request' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:734'],
        ];
    }

    /** @return array<string, list<mixed>> */
    public static function eligibilityRules(): array
    {
        return [
            'eligibility_rules' => ['sometimes', 'array', 'max:'.LeavePolicyService::MAX_ELIGIBILITY_RULES],
            'eligibility_rules.*' => ['array:staff_type,employee_category_id,department_id'],
            'eligibility_rules.*.staff_type' => ['sometimes', 'nullable', Rule::in(StaffType::values())],
            'eligibility_rules.*.employee_category_id' => ['sometimes', 'nullable', 'uuid'],
            'eligibility_rules.*.department_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{name: string, annual_allocation_units: int, carry_forward_allowed: bool, carry_forward_cap_units: ?int, carry_forward_expiry_days: ?int, min_notice_days: ?int, max_backdate_days: ?int, max_units_per_request: ?int, entitlement_method: string, monthly_accrual_units: ?int, accrual_timing: ?string}
     */
    public static function terms(array $validated): array
    {
        $int = fn (string $key): ?int => isset($validated[$key]) ? (int) $validated[$key] : null;

        return [
            'name' => (string) $validated['name'],
            'annual_allocation_units' => (int) $validated['annual_allocation_units'],
            'carry_forward_allowed' => (bool) $validated['carry_forward_allowed'],
            'carry_forward_cap_units' => $int('carry_forward_cap_units'),
            'carry_forward_expiry_days' => $int('carry_forward_expiry_days'),
            'min_notice_days' => $int('min_notice_days'),
            'max_backdate_days' => $int('max_backdate_days'),
            'max_units_per_request' => $int('max_units_per_request'),
            'entitlement_method' => (string) ($validated['entitlement_method'] ?? LeaveEntitlementMethod::ANNUAL->value),
            'monthly_accrual_units' => $int('monthly_accrual_units'),
            'accrual_timing' => isset($validated['accrual_timing']) ? (string) $validated['accrual_timing'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<LeaveEligibilityCriteria>
     */
    public static function criteria(array $validated): array
    {
        $rules = [];
        foreach ((array) ($validated['eligibility_rules'] ?? []) as $rule) {
            $rule = (array) $rule;
            $staffType = isset($rule['staff_type']) && $rule['staff_type'] !== '' ? StaffType::from((string) $rule['staff_type']) : null;
            $category = isset($rule['employee_category_id']) && $rule['employee_category_id'] !== '' ? (string) $rule['employee_category_id'] : null;
            $department = isset($rule['department_id']) && $rule['department_id'] !== '' ? (string) $rule['department_id'] : null;
            $rules[] = new LeaveEligibilityCriteria($staffType, $category, $department);
        }

        return $rules;
    }
}
