<?php

namespace App\Domain\Transport\Application;

use App\Domain\Students\Infrastructure\Student;
use App\Domain\Transport\Application\Exceptions\TransportException;
use App\Domain\Transport\Application\Exceptions\TransportRiderImportFileException;
use App\Domain\Transport\Application\Exceptions\TransportRiderImportNotCommittableException;
use App\Domain\Transport\Infrastructure\TransportRoute;
use App\Domain\Transport\Infrastructure\TransportStop;
use App\Domain\Transport\Infrastructure\TransportStudentAssignment;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\Campus\CampusScope;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * ORG.9C-4 (ADR 0076 §72, PV1-09 / TRN-036): add-only bulk rider assignment
 * from a UTF-8 CSV -- an ADAPTER over the one assignment write path
 * (TransportStudentAssignmentService::assign()), never a second Transport
 * engine and never a generic importer.
 *
 * parse()   the file into normalized rows (bounded, header-checked; nothing kept).
 * preview() every row's outcome, set-based and read-only, plus a signature
 *           binding the normalized rows to this School, actor and time.
 * commit()  ALL OR NOTHING: verify the signature, then in ONE transaction
 *           re-read the actor's authority fresh, lock every named Student
 *           (ascending id, FOR NO KEY UPDATE -- the single path's own lock),
 *           re-evaluate every row and, only if every row is READY or
 *           ALREADY_ASSIGNED, call assign() for each READY row in Student-id
 *           order (with the Campus guard for a Campus-scoped actor). Any other
 *           outcome, or any refusal from assign(), rolls the whole batch back.
 *
 * A retried commit finds its rows ALREADY_ASSIGNED and changes nothing; an
 * existing different assignment is a CONFLICT (no replace, no end). A
 * Campus-scoped actor gets one generic NOT_ACCESSIBLE for anything outside
 * their Campuses, exactly like the single path's refusal.
 */
final class TransportRiderImport
{
    public const CAPABILITY = 'transport.assignments.manage';

    public const MAX_ROWS = 500;

    public const MAX_BYTES = 128 * 1024;

    public const COLUMNS = ['student_number', 'route_code', 'pickup_stop', 'dropoff_stop'];

    /** A signed preview stays committable this long (minutes). */
    public const PREVIEW_TTL_MINUTES = 30;

    public const READY = 'ready';

    public const ALREADY_ASSIGNED = 'already_assigned';

    public const DUPLICATE_ROW = 'duplicate_row';

    public const CONFLICT = 'conflict';

    public const INVALID = 'invalid';

    public const NOT_ACCESSIBLE = 'not_accessible';

    public function __construct(
        private readonly TransportRiderAccess $access,
        private readonly TransportStudentAssignmentService $assignments,
        private readonly CapabilityResolver $capabilities,
        private readonly SchoolOperationalGuard $operational,
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @return list<array{line: int, student_number: string, route_code: string, pickup_stop: ?string, dropoff_stop: ?string}>
     */
    public function parse(string $contents): array
    {
        if (strlen($contents) > self::MAX_BYTES) {
            throw new TransportRiderImportFileException('The file is larger than '.(self::MAX_BYTES / 1024).' KB.');
        }
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? '';
        if (! mb_check_encoding($contents, 'UTF-8')) {
            throw new TransportRiderImportFileException('The file must be UTF-8 text (CSV).');
        }
        $lines = preg_split('/\r\n|\n|\r/', $contents) ?: [];
        while ($lines !== [] && trim((string) end($lines)) === '') {
            array_pop($lines);
        }
        if ($lines === []) {
            throw new TransportRiderImportFileException('The file is empty.');
        }

        $header = array_map(fn ($h) => strtolower(trim((string) $h)), str_getcsv((string) array_shift($lines), ',', '"', ''));
        if (count($header) !== count(array_unique($header))) {
            throw new TransportRiderImportFileException('A column appears twice in the header.');
        }
        foreach (['student_number', 'route_code'] as $required) {
            if (! in_array($required, $header, true)) {
                throw new TransportRiderImportFileException("The header must include {$required}.");
            }
        }
        $unknown = array_diff($header, self::COLUMNS);
        if ($unknown !== []) {
            throw new TransportRiderImportFileException('Unknown column(s): '.implode(', ', array_map(fn ($c) => mb_substr($c, 0, 40), $unknown)).'.');
        }
        if (count($lines) > self::MAX_ROWS) {
            throw new TransportRiderImportFileException('The file has more than '.self::MAX_ROWS.' rows.');
        }
        if ($lines === []) {
            throw new TransportRiderImportFileException('The file has no rows.');
        }

        $rows = [];
        foreach ($lines as $i => $line) {
            $cells = str_getcsv((string) $line, ',', '"', '');
            if (count($cells) !== count($header)) {
                throw new TransportRiderImportFileException('Row '.($i + 2).' has '.count($cells).' cells; the header has '.count($header).'.');
            }
            $cell = fn (string $column): string => self::trim((string) ($cells[array_search($column, $header, true)] ?? ''));
            $rows[] = [
                'line' => $i + 2,
                'student_number' => self::trim(mb_substr($cell('student_number'), 0, 64)),
                'route_code' => mb_strtoupper(self::trim(mb_substr($cell('route_code'), 0, 64))),
                'pickup_stop' => in_array('pickup_stop', $header, true) && $cell('pickup_stop') !== '' ? self::trim(mb_substr($cell('pickup_stop'), 0, 10)) : null,
                'dropoff_stop' => in_array('dropoff_stop', $header, true) && $cell('dropoff_stop') !== '' ? self::trim(mb_substr($cell('dropoff_stop'), 0, 10)) : null,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{line: int, student_number: string, route_code: string, pickup_stop: ?string, dropoff_stop: ?string}>  $rows
     * @return array{rows: list<array<string, mixed>>, summary: array<string, int>, committable: bool, issuedAt: string, signature: string}
     */
    public function preview(School $school, CampusScope $scope, User $actor, array $rows): array
    {
        $outcomes = $this->context->withSchool($school, fn () => $this->evaluate($school, $scope, $rows));
        $committable = self::committable($outcomes);
        $issuedAt = CarbonImmutable::now()->toIso8601String();

        // Only a committable preview is signed: nothing else can reach commit() (security review L4).
        return [
            'rows' => self::present($outcomes),
            'summary' => self::summary($outcomes),
            'committable' => $committable,
            'issuedAt' => $committable ? $issuedAt : null,
            'signature' => $committable ? $this->sign($school, $actor, $issuedAt, $rows) : null,
        ];
    }

    /**
     * @param  list<array{line: int, student_number: string, route_code: string, pickup_stop: ?string, dropoff_stop: ?string}>  $rows
     * @return array{created: int, alreadyAssigned: int, rows: int}
     */
    public function commit(School $school, CampusScope $scope, User $actor, array $rows, string $issuedAt, string $signature): array
    {
        if (! hash_equals($this->sign($school, $actor, $issuedAt, $rows), $signature)) {
            throw new TransportRiderImportFileException('This preview does not match the rows submitted. Upload the file again.');
        }
        $issued = CarbonImmutable::parse($issuedAt);
        if ($issued->subMinute()->isFuture() || $issued->addMinutes(self::PREVIEW_TTL_MINUTES)->isPast()) {
            throw new TransportRiderImportFileException('This preview has expired. Upload the file again.');
        }

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $scope, $actor, $rows): array {
            // Authority NOW, never the cache, before any row is read (security review L1/L2):
            // the School operating, and the premise the scope was resolved on -- a
            // whole-School grant for a whole-School scope, a grant on one of its
            // Campuses otherwise (each READY route's Campus is re-checked below).
            $this->operational->requireOperational($school->id);
            $premise = $scope->isWholeSchool()
                ? $this->capabilities->holdsInCampusNow($actor, self::CAPABILITY, $school, null)
                : collect($scope->campusIds())->contains(fn (string $campusId) => $this->capabilities->holdsInCampusNow($actor, self::CAPABILITY, $school, $campusId));
            if (! $premise) {
                throw new AuthorizationException('This action is unauthorized.');
            }

            // The Students first, in ascending id order: the single path's own lock, so
            // two batches (or a batch and a single assignment) serialize without deadlock.
            $numbers = array_values(array_unique(array_column($rows, 'student_number')));
            Student::query()->where('school_id', $school->id)->whereIn('student_number', $numbers)
                ->orderBy('id')->lock(TransportStudentAssignmentService::STUDENT_LOCK)->pluck('id');

            $outcomes = $this->evaluate($school, $scope, $rows);
            if (! self::committable($outcomes)) {
                throw new TransportRiderImportNotCommittableException($outcomes);
            }

            if (! $scope->isWholeSchool()) {
                foreach (array_unique(array_map(fn (array $o) => (string) $o['routeCampusId'], array_filter($outcomes, fn ($o) => $o['outcome'] === self::READY))) as $campusId) {
                    if ($campusId === '' || ! $this->capabilities->holdsInCampusNow($actor, self::CAPABILITY, $school, $campusId)) {
                        throw new AuthorizationException('This action is unauthorized.');
                    }
                }
            }

            $ready = array_values(array_filter($outcomes, fn (array $o) => $o['outcome'] === self::READY));
            usort($ready, fn (array $a, array $b) => strcmp((string) $a['studentId'], (string) $b['studentId']));
            // The models in three reads (assign() re-locks and re-reads what it needs).
            $students = Student::query()->whereIn('id', array_column($ready, 'studentId'))->get()->keyBy('id');
            $routes = TransportRoute::query()->whereIn('id', array_unique(array_column($ready, 'routeId')))->get()->keyBy('id');
            $stops = TransportStop::query()->whereIn('id', array_filter([...array_column($ready, 'pickupStopId'), ...array_column($ready, 'dropoffStopId')]))->get()->keyBy('id');
            foreach ($ready as $row) {
                try {
                    $this->assignments->assign(
                        $students[$row['studentId']],
                        $routes[$row['routeId']],
                        $row['pickupStopId'] === null ? null : $stops[$row['pickupStopId']],
                        $row['dropoffStopId'] === null ? null : $stops[$row['dropoffStopId']],
                        $actor,
                        $this->access->writeGuard($school, $scope),
                    );
                } catch (TransportException) {
                    // The canonical path refused under its own locks: nothing of the batch stays.
                    throw new TransportRiderImportNotCommittableException(array_map(
                        fn (array $o) => $o['line'] === $row['line'] ? [...$o, 'outcome' => $scope->isWholeSchool() ? self::INVALID : self::NOT_ACCESSIBLE, 'message' => 'This row could not be assigned. Check it and try again.'] : $o,
                        $outcomes,
                    ));
                }
            }

            $summary = ['created' => count($ready), 'alreadyAssigned' => count(array_filter($outcomes, fn (array $o) => $o['outcome'] === self::ALREADY_ASSIGNED)), 'rows' => count($rows)];
            if ($summary['created'] > 0) {
                // Batch facts only: never a row, a name or the file.
                $this->audit->school($school, 'transport.rider_import.committed', actor: $actor, metadata: [...$summary, 'batch' => substr(hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)), 0, 16)]);
            }

            return $summary;
        }));
    }

    /**
     * Every row's outcome from set-based reads (one query per kind, never per
     * row). Route and stop checks come first and never depend on the Student,
     * so a Campus-scoped actor learns nothing about Students outside scope.
     *
     * @param  list<array{line: int, student_number: string, route_code: string, pickup_stop: ?string, dropoff_stop: ?string}>  $rows
     * @return list<array<string, mixed>>
     */
    private function evaluate(School $school, CampusScope $scope, array $rows): array
    {
        $routes = $this->access->routes($scope)->where('school_id', $school->id)
            ->whereIn('code', array_values(array_unique(array_column($rows, 'route_code'))))->get()->keyBy('code');
        $stops = TransportStop::query()->whereIn('route_id', $routes->pluck('id'))->where('status', 'active')->get()
            ->keyBy(fn (TransportStop $s) => $s->route_id.'#'.$s->sequence);
        $students = Student::query()->where('school_id', $school->id)
            ->whereIn('student_number', array_values(array_unique(array_column($rows, 'student_number'))))->get()->keyBy('student_number');
        $operational = $scope->isWholeSchool() ? [] : $this->access->operationalCampuses($school, $students->pluck('id')->map(fn ($id) => (string) $id)->all());
        $active = TransportStudentAssignment::query()->whereIn('student_id', $students->pluck('id'))->where('status', 'active')->get()->keyBy('student_id');
        $visible = $scope->isWholeSchool() ? null : $this->access->assignments($scope)->whereIn('id', $active->pluck('id'))->pluck('id')->flip();
        $campusScoped = ! $scope->isWholeSchool();

        // Two rows for one Student: identical terms are one row; different terms conflict.
        $terms = [];
        foreach ($rows as $row) {
            $terms[$row['student_number']][] = $row['route_code'].'|'.$row['pickup_stop'].'|'.$row['dropoff_stop'];
        }

        $seen = [];
        $outcomes = [];
        foreach ($rows as $row) {
            $base = ['line' => $row['line'], 'studentNumber' => $row['student_number'], 'routeCode' => $row['route_code'], 'pickupStop' => $row['pickup_stop'], 'dropoffStop' => $row['dropoff_stop'],
                'studentName' => null, 'studentId' => null, 'routeId' => null, 'pickupStopId' => null, 'dropoffStopId' => null, 'routeCampusId' => null];
            $out = fn (string $outcome, string $message) => [...$base, 'outcome' => $outcome, 'message' => $message];

            if ($row['student_number'] === '' || $row['route_code'] === '') {
                $outcomes[] = $out(self::INVALID, 'A student number and a route code are required.');

                continue;
            }
            foreach (['pickup_stop', 'dropoff_stop'] as $column) {
                if ($row[$column] !== null && preg_match('/^[1-9][0-9]{0,4}$/', $row[$column]) !== 1) {
                    $outcomes[] = $out(self::INVALID, 'A stop is its number on the route (1, 2, 3 …).');

                    continue 2;
                }
            }
            if (count(array_unique($terms[$row['student_number']])) > 1) {
                $outcomes[] = $out(self::CONFLICT, 'This student appears more than once with different routes or stops.');

                continue;
            }

            /** @var TransportRoute|null $route */
            $route = $routes->get($row['route_code']);
            if ($route === null) {
                $outcomes[] = $out(self::NOT_ACCESSIBLE, 'Route not found or not accessible.');

                continue;
            }
            if (! $route->isActive()) {
                $outcomes[] = $out(self::INVALID, 'This route is not active.');

                continue;
            }
            $pickup = $row['pickup_stop'] === null ? null : $stops->get($route->id.'#'.$row['pickup_stop']);
            $dropoff = $row['dropoff_stop'] === null ? null : $stops->get($route->id.'#'.$row['dropoff_stop']);
            if (($row['pickup_stop'] !== null && $pickup === null) || ($row['dropoff_stop'] !== null && $dropoff === null)) {
                $outcomes[] = $out(self::INVALID, 'A stop is not an active stop of this route.');

                continue;
            }
            $base = [...$base, 'routeId' => $route->id, 'routeCampusId' => $route->campus_id, 'pickupStopId' => $pickup?->id, 'dropoffStopId' => $dropoff?->id];
            $out = fn (string $outcome, string $message) => [...$base, 'outcome' => $outcome, 'message' => $message];

            /** @var Student|null $student */
            $student = $students->get($row['student_number']);
            if ($campusScoped && ($student === null || ! $student->isActive() || ($operational[(string) $student->id] ?? null) !== $route->campus_id)) {
                $outcomes[] = $out(self::NOT_ACCESSIBLE, 'Student not found or not accessible for this route.');

                continue;
            }
            if ($student === null) {
                $outcomes[] = $out(self::INVALID, 'No student has this number.');

                continue;
            }
            if (! $student->isActive()) {
                $outcomes[] = $out(self::INVALID, 'This student is not active.');

                continue;
            }
            $base = [...$base, 'studentId' => (string) $student->id];
            $out = fn (string $outcome, string $message) => [...$base, 'outcome' => $outcome, 'message' => $message];

            /** @var TransportStudentAssignment|null $current */
            $current = $active->get($student->id);
            if ($current !== null && $campusScoped && ! isset($visible[$current->id])) {
                $outcomes[] = $out(self::NOT_ACCESSIBLE, 'Student not found or not accessible for this route.');

                continue;
            }
            // Only now is the Student known to be within the actor's reach: their name may show.
            $base = [...$base, 'studentName' => trim($student->first_name.' '.$student->last_name)];
            $out = fn (string $outcome, string $message) => [...$base, 'outcome' => $outcome, 'message' => $message];

            // After every scope check, so a repeated row never answers differently (security review L3).
            if (isset($seen[$row['student_number']])) {
                $outcomes[] = $out(self::DUPLICATE_ROW, 'The same row appears earlier in the file; it counts once.');

                continue;
            }
            $seen[$row['student_number']] = true;

            if ($current !== null) {
                $same = $current->route_id === $route->id && $current->pickup_stop_id === $pickup?->id && $current->dropoff_stop_id === $dropoff?->id;
                $outcomes[] = $same
                    ? $out(self::ALREADY_ASSIGNED, 'Already assigned exactly like this; nothing changes.')
                    : $out(self::CONFLICT, 'Already assigned to a different route or stop. End that assignment first.');

                continue;
            }
            $outcomes[] = $out(self::READY, 'Ready to assign.');
        }

        return $outcomes;
    }

    /** Laravel's own TrimStrings rule, so a cell survives the commit round trip unchanged. */
    private static function trim(string $value): string
    {
        return (string) preg_replace('~^[\s\x{FEFF}\x{200B}]+|[\s\x{FEFF}\x{200B}]+$~u', '', $value);
    }

    /**
     * Row outcomes as the operator sees them: never an internal id or a Campus.
     *
     * @param  list<array<string, mixed>>  $outcomes
     * @return list<array<string, mixed>>
     */
    public static function present(array $outcomes): array
    {
        return array_map(fn (array $o) => array_diff_key($o, ['studentId' => 1, 'routeId' => 1, 'pickupStopId' => 1, 'dropoffStopId' => 1, 'routeCampusId' => 1]), $outcomes);
    }

    /** @param  list<array<string, mixed>>  $outcomes */
    private static function committable(array $outcomes): bool
    {
        foreach ($outcomes as $o) {
            if (! in_array($o['outcome'], [self::READY, self::ALREADY_ASSIGNED, self::DUPLICATE_ROW], true)) {
                return false;
            }
        }

        return in_array(self::READY, array_column($outcomes, 'outcome'), true) || in_array(self::ALREADY_ASSIGNED, array_column($outcomes, 'outcome'), true);
    }

    /**
     * @param  list<array<string, mixed>>  $outcomes
     * @return array<string, int>
     */
    public static function summary(array $outcomes): array
    {
        $summary = array_fill_keys([self::READY, self::ALREADY_ASSIGNED, self::DUPLICATE_ROW, self::CONFLICT, self::INVALID, self::NOT_ACCESSIBLE], 0);
        foreach ($outcomes as $o) {
            $summary[$o['outcome']]++;
        }

        return $summary;
    }

    /** @param  list<array{line: int, student_number: string, route_code: string, pickup_stop: ?string, dropoff_stop: ?string}>  $rows */
    private function sign(School $school, User $actor, string $issuedAt, array $rows): string
    {
        $key = hash_hmac('sha256', 'transport-rider-import', (string) config('app.key'));

        return hash_hmac('sha256', json_encode([$school->id, $actor->id, $issuedAt, $rows], JSON_THROW_ON_ERROR), $key);
    }
}
