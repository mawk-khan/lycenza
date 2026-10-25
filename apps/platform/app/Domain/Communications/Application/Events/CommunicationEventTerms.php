<?php

namespace App\Domain\Communications\Application\Events;

use App\Domain\Communications\Application\Exceptions\InvalidCommunicationEventException;
use App\Domain\Communications\Infrastructure\CommunicationEvent;
use App\Models\School;
use App\Support\Tenancy\SchoolTimezone;
use Carbon\CarbonImmutable;

/**
 * ORG.9D-3 (ADR 0076 §75): the material terms of one Event, normalized.
 *
 * - All-day: School-local calendar dates (`Y-m-d`), never shifted through
 *   UTC; single- or multi-day, end on or after start.
 * - Timed: local date-times entered in the SCHOOL's timezone (never the
 *   browser's), stored as instants (UTC); end strictly after start. A local
 *   time that does not exist (a daylight-saving gap) is refused rather than
 *   silently moved; an ambiguous one (the repeated hour) is the first
 *   occurrence.
 * - Bounded to 2000-01-01 .. 2100-12-31 so a malformed or extreme date is a
 *   clean refusal, never a database error.
 * - Audience: an existing Communications audience type (School-wide, Staff,
 *   or the Guardians of a Grade or Section) -- no other segment.
 *
 * Shape only; the School-ownership of a Grade or Section and "not already
 * ended" are checked by CommunicationEventService.
 */
final readonly class CommunicationEventTerms
{
    private const MIN = '2000-01-01';

    private const MAX = '2100-12-31';

    private function __construct(
        public string $title,
        public string $body,
        public ?string $location,
        public string $dateMode,
        public ?string $startsOn,
        public ?string $endsOn,
        public ?CarbonImmutable $startsAt,
        public ?CarbonImmutable $endsAt,
        public string $audienceType,
        public ?string $gradeLevelId,
        public ?string $sectionId,
    ) {}

    /**
     * @param  array{title?: mixed, body?: mixed, location?: mixed, date_mode?: mixed, starts_on?: mixed, ends_on?: mixed, starts_at?: mixed, ends_at?: mixed, audience_type?: mixed, grade_level_id?: mixed, section_id?: mixed}  $input
     *
     * @throws InvalidCommunicationEventException
     */
    public static function fromInput(School $school, array $input): self
    {
        $title = trim((string) ($input['title'] ?? ''));
        $body = trim((string) ($input['body'] ?? ''));
        $location = trim((string) ($input['location'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) {
            throw new InvalidCommunicationEventException('Give the event a title of up to 200 characters.', 'title');
        }
        if ($body === '' || mb_strlen($body) > 10000) {
            throw new InvalidCommunicationEventException('Describe the event in up to 10,000 characters.', 'body');
        }
        if (mb_strlen($location) > 200) {
            throw new InvalidCommunicationEventException('Keep the location to 200 characters.', 'location');
        }

        $mode = (string) ($input['date_mode'] ?? '');
        $startsOn = $endsOn = null;
        $startsAt = $endsAt = null;
        if ($mode === CommunicationEvent::ALL_DAY) {
            $startsOn = self::date((string) ($input['starts_on'] ?? ''), 'starts_on');
            $endsOn = self::date((string) ($input['ends_on'] ?? '') ?: $startsOn, 'ends_on');
            if ($endsOn < $startsOn) {
                throw new InvalidCommunicationEventException('The last day cannot be before the first day.', 'ends_on');
            }
        } elseif ($mode === CommunicationEvent::TIMED) {
            $tz = SchoolTimezone::resolve($school);
            $startsAt = self::localInstant((string) ($input['starts_at'] ?? ''), $tz, 'starts_at');
            $endsAt = self::localInstant((string) ($input['ends_at'] ?? ''), $tz, 'ends_at');
            if ($endsAt->lessThanOrEqualTo($startsAt)) {
                throw new InvalidCommunicationEventException('The event must end after it starts.', 'ends_at');
            }
            // The stored instants are bounded too (cev_date_range_check), whatever the zone offset.
            if ($startsAt->utc()->format('Y-m-d') < self::MIN || $endsAt->utc()->format('Y-m-d') > self::MAX) {
                throw new InvalidCommunicationEventException('Enter a date and time between 2000 and 2100.', 'starts_at');
            }
        } else {
            throw new InvalidCommunicationEventException('Choose an all-day or a timed event.', 'date_mode');
        }

        $audience = (string) ($input['audience_type'] ?? '');
        if (! in_array($audience, CommunicationEvent::AUDIENCES, true)) {
            throw new InvalidCommunicationEventException('Choose who can see the event.', 'audience_type');
        }
        $grade = $audience === 'grade' ? (string) ($input['grade_level_id'] ?? '') : null;
        $section = $audience === 'section' ? (string) ($input['section_id'] ?? '') : null;
        if (($audience === 'grade' && $grade === '') || ($audience === 'section' && $section === '')) {
            throw new InvalidCommunicationEventException('Choose the Grade or Section whose guardians can see the event.', 'audience_type');
        }

        return new self($title, $body, $location === '' ? null : $location, $mode, $startsOn, $endsOn, $startsAt?->utc(), $endsAt?->utc(), $audience, $grade, $section);
    }

    /** The attributes CommunicationEventService writes. @return array<string, mixed> */
    public function attributes(): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'location' => $this->location,
            'date_mode' => $this->dateMode,
            'starts_on' => $this->startsOn,
            'ends_on' => $this->endsOn,
            'starts_at' => $this->startsAt?->format('Y-m-d H:i:sP'),
            'ends_at' => $this->endsAt?->format('Y-m-d H:i:sP'),
            'audience_type' => $this->audienceType,
            'grade_level_id' => $this->gradeLevelId,
            'section_id' => $this->sectionId,
        ];
    }

    private static function date(string $value, string $field): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            || $value < self::MIN || $value > self::MAX) {
            throw new InvalidCommunicationEventException('Enter a valid date between 2000 and 2100.', $field);
        }

        return $value;
    }

    private static function localInstant(string $value, \DateTimeZone $tz, string $field): CarbonImmutable
    {
        $value = str_replace(' ', 'T', trim($value));
        $value = strlen($value) === 19 ? substr($value, 0, 16) : $value; // seconds are not part of the term
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/', $value, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            || (int) $m[4] > 23 || (int) $m[5] > 59) {
            throw new InvalidCommunicationEventException('Enter a valid date and time.', $field);
        }
        if (substr($value, 0, 10) < self::MIN || substr($value, 0, 10) > self::MAX) {
            throw new InvalidCommunicationEventException('Enter a date and time between 2000 and 2100.', $field);
        }
        $local = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $value, $tz);
        if (! $local instanceof CarbonImmutable || $local->format('Y-m-d\TH:i') !== $value) {
            // A real wall-clock time that does not exist in the School's
            // timezone (a daylight-saving gap) round-trips to another time.
            throw new InvalidCommunicationEventException('That time does not exist in the School\'s timezone (a daylight-saving change); choose another.', $field);
        }

        return $local;
    }
}
