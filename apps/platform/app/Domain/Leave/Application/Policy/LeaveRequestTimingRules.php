<?php

namespace App\Domain\Leave\Application\Policy;

use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Infrastructure\LeavePolicy;
use Carbon\CarbonImmutable;

/**
 * HR.9 (ADR 0065 §28.2): the request-timing rules of ONE policy version, in
 * their stated units. NULL = the version has no such rule.
 *
 * - `minNoticeDays` (calendar days): a request starting today or later must
 *   start at least this many days after the School-local submission date.
 * - `maxBackdateDays` (calendar days): a request starting before the
 *   submission date may start at most this many days earlier (0 = never).
 * - `maxUnitsPerRequest` (half-day units): the request's chargeable units,
 *   computed from the staff working calendar, never exceed it.
 *
 * A request is judged against EVERY version assigned to the employment on
 * any of its dates (all must pass -- never "the latest" or "the most
 * lenient"). There is no override and no free-text exception.
 */
final readonly class LeaveRequestTimingRules
{
    public function __construct(
        public ?int $minNoticeDays,
        public ?int $maxBackdateDays,
        public ?int $maxUnitsPerRequest,
    ) {}

    public static function of(LeavePolicy $policy): self
    {
        return new self($policy->min_notice_days, $policy->max_backdate_days, $policy->max_units_per_request);
    }

    public function isEmpty(): bool
    {
        return $this->minNoticeDays === null && $this->maxBackdateDays === null && $this->maxUnitsPerRequest === null;
    }

    /**
     * @param  string  $startsOn  School-local Y-m-d, the first requested date
     * @param  string  $submittedOn  School-local Y-m-d, today
     *
     * @throws LeaveException
     */
    public function assertSatisfiedBy(string $startsOn, string $submittedOn, int $units): void
    {
        $days = (int) CarbonImmutable::parse($submittedOn)->diffInDays(CarbonImmutable::parse($startsOn), false);
        if ($days >= 0 && $this->minNoticeDays !== null && $days < $this->minNoticeDays) {
            throw LeaveException::invalid('LEAVE_NOTICE_TOO_SHORT', "This leave policy needs at least {$this->minNoticeDays} calendar day(s) of notice.");
        }
        if ($days < 0 && $this->maxBackdateDays !== null && -$days > $this->maxBackdateDays) {
            throw LeaveException::invalid('LEAVE_BACKDATE_TOO_FAR', $this->maxBackdateDays === 0
                ? 'This leave policy does not accept requests for past dates.'
                : "This leave policy accepts past dates up to {$this->maxBackdateDays} calendar day(s) back.");
        }
        if ($this->maxUnitsPerRequest !== null && $units > $this->maxUnitsPerRequest) {
            throw LeaveException::invalid('LEAVE_REQUEST_TOO_LONG', 'This leave policy allows at most '.self::days($this->maxUnitsPerRequest).' per request.');
        }
    }

    private static function days(int $units): string
    {
        return $units % 2 === 0 ? ($units / 2).' day(s)' : number_format($units / 2, 1).' day(s)';
    }
}
