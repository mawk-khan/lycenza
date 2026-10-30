<?php

namespace App\Domain\Students\Application\Import;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Guardians\Application\GuardianContactService;
use App\Domain\Guardians\Application\GuardianService;
use App\Domain\Guardians\Application\StudentGuardianRelationshipService;
use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Guardians\Infrastructure\RelationshipType;
use App\Domain\Students\Application\StudentDuplicateCandidates;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Application\StudentService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentImportBatch;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Privacy\ContactLookupHasher;
use App\Support\Privacy\EmailNormalizer;
use App\Support\Privacy\PhoneNormalizer;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use App\Support\Validation\DateOfBirth;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * SIS.EXP-5 (OD-SIS-3): the add-only bulk Student & Guardian import -- ONE
 * code-owned CSV contract (`student_guardian_import_v1`) and an ADAPTER over
 * the canonical write paths (StudentService::create, StudentEnrollmentService
 * ::enroll, GuardianService::create, GuardianContactService::create,
 * StudentGuardianRelationshipService::link/setPrimary). Never an update, a
 * merge, a mapping engine, a history import or a Portal-account provisioner
 * (rule 107's pattern, after TransportRiderImport).
 *
 * parse()   the file into normalized rows, bounded; nothing is stored -- the
 *           upload lives only in its request.
 * preview() every row's result (ready / warning / error) from set-based
 *           reads, read-only, with a signature binding the School, actor,
 *           format, issue time, the rows and the result digest.
 * commit()  ALL OR NOTHING in one transaction: re-read every authority fresh,
 *           re-evaluate every row (refused when the result changed since the
 *           preview, or any row is an error), claim the content digest (one
 *           committed batch per content and School: a retry -- concurrent or
 *           later -- replays it), then create through the canonical services.
 *           Any refusal rolls the whole batch back.
 *
 * Identity: every Student and Guardian is NEW. A Student matching an existing
 * one (StudentDuplicateCandidates' exact rule) and a Guardian contact equal to
 * an existing one are WARNINGS the importer must acknowledge -- never a link,
 * update or merge. A batch-local `guardianN_key` says "these rows share one
 * NEW Guardian"; it is never persisted.
 */
final class StudentGuardianImport
{
    public const FORMAT = 'student_guardian_import_v1';

    public const CAPABILITY = 'students.import';

    /** The import never exceeds the single paths' authority: every one is required, whole School. */
    public const AUTHORITY = ['students.import', 'students.manage', 'guardians.manage', 'enrollments.manage'];

    /**
     * Measured (SIS.EXP-5): the canonical services open their own nested
     * transactions per write (~63 queries a row), and the commit time grows
     * faster than linearly past ~100 rows (100 rows ~4 s, 200 rows ~15 s on
     * the development stack). 100 keeps a commit well inside one request.
     */
    public const MAX_ROWS = 100;

    public const MAX_BYTES = 256 * 1024;

    public const MAX_CELL = 120;

    public const PREVIEW_TTL_MINUTES = 30;

    public const STUDENT_COLUMNS = ['first_name', 'middle_name', 'last_name', 'date_of_birth', 'academic_year_code', 'campus_code', 'grade_code', 'section_code', 'roll_number', 'start_date'];

    public const GUARDIAN_FIELDS = ['key', 'first_name', 'last_name', 'relationship', 'email', 'mobile', 'legal_guardian', 'emergency_contact', 'authorized_pickup'];

    public const READY = 'ready';

    public const WARNING = 'warning';

    public const ERROR = 'error';

    public function __construct(
        private readonly StudentService $students,
        private readonly StudentEnrollmentService $placements,
        private readonly GuardianService $guardians,
        private readonly GuardianContactService $contacts,
        private readonly StudentGuardianRelationshipService $relationships,
        private readonly StudentDuplicateCandidates $duplicates,
        private readonly CapabilityResolver $capabilities,
        private readonly SchoolOperationalGuard $operational,
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
        private readonly EmailNormalizer $emails,
        private readonly PhoneNormalizer $phones,
        private readonly ContactLookupHasher $hasher,
    ) {}

    /** @return list<string> every v1 column, in template order */
    public static function columns(): array
    {
        $columns = self::STUDENT_COLUMNS;
        foreach ([1, 2] as $slot) {
            foreach (self::GUARDIAN_FIELDS as $field) {
                $columns[] = "guardian{$slot}_{$field}";
            }
        }

        return $columns;
    }

    /**
     * @return list<array{line: int, cells: array<string, string>}>
     */
    public function parse(string $contents): array
    {
        if (strlen($contents) > self::MAX_BYTES) {
            throw new StudentImportFileException('The file is larger than '.(self::MAX_BYTES / 1024).' KB.');
        }
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? '';
        if (! mb_check_encoding($contents, 'UTF-8')) {
            throw new StudentImportFileException('The file must be UTF-8 text (CSV). Save it as "CSV UTF-8" and upload it again.');
        }
        $lines = preg_split('/\r\n|\n|\r/', $contents) ?: [];
        while ($lines !== [] && trim((string) end($lines)) === '') {
            array_pop($lines);
        }
        if ($lines === []) {
            throw new StudentImportFileException('The file is empty.');
        }

        $header = array_map(fn ($h) => strtolower(self::trim((string) $h)), str_getcsv((string) array_shift($lines), ',', '"', ''));
        if (count($header) !== count(array_unique($header))) {
            throw new StudentImportFileException('A column appears twice in the header.');
        }
        $unknown = array_diff($header, self::columns());
        if ($unknown !== []) {
            throw new StudentImportFileException('Unknown column(s): '.implode(', ', array_map(fn ($c) => mb_substr($c, 0, 40), array_slice($unknown, 0, 5))).'. Use the '.self::FORMAT.' template.');
        }
        $missing = array_diff(self::columns(), $header);
        if ($missing !== []) {
            throw new StudentImportFileException('Missing column(s): '.implode(', ', array_slice($missing, 0, 5)).'. Use the '.self::FORMAT.' template.');
        }
        if ($lines === []) {
            throw new StudentImportFileException('The file has no rows.');
        }
        if (count($lines) > self::MAX_ROWS) {
            throw new StudentImportFileException('The file has more than '.self::MAX_ROWS.' rows. Split it into smaller files.');
        }

        $rows = [];
        foreach ($lines as $i => $line) {
            $cells = str_getcsv((string) $line, ',', '"', '');
            if (count($cells) !== count($header)) {
                throw new StudentImportFileException('Row '.($i + 2).' has '.count($cells).' cells; the header has '.count($header).'.');
            }
            $named = [];
            foreach (self::columns() as $column) {
                $named[$column] = self::trim((string) $cells[array_search($column, $header, true)]);
            }
            $rows[] = ['line' => $i + 2, 'cells' => $named];
        }

        return $rows;
    }

    /**
     * @param  list<array{line: int, cells: array<string, string>}>  $rows
     * @return array{rows: list<array<string, mixed>>, summary: array<string, int>, committable: bool, alreadyImported: ?string, issuedAt: ?string, signature: ?string}
     */
    public function preview(School $school, User $actor, array $rows): array
    {
        $evaluation = $this->context->withSchool($school, fn () => $this->evaluate($school, $rows));
        $already = $this->context->withSchool($school, fn () => StudentImportBatch::query()->where('school_id', $school->id)->where('content_digest', self::digest($rows))->first());
        $committable = $already === null && self::committable($evaluation['rows']);
        $issuedAt = CarbonImmutable::now()->toIso8601String();

        return [
            'rows' => $evaluation['rows'],
            'summary' => $evaluation['summary'],
            'committable' => $committable,
            'alreadyImported' => $already?->committed_at->toIso8601String(),
            'issuedAt' => $committable ? $issuedAt : null,
            'signature' => $committable ? $this->sign($school, $actor, $issuedAt, $rows, self::resultDigest($evaluation['rows'])) : null,
        ];
    }

    /**
     * The signature and the expiry, checked BEFORE a fresh MFA code is
     * consumed (and again inside commit()).
     *
     * @param  list<array{line: int, cells: array<string, string>}>  $rows
     */
    public function assertSigned(School $school, User $actor, array $rows, string $issuedAt, string $resultDigest, string $signature): void
    {
        if (! hash_equals($this->sign($school, $actor, $issuedAt, $rows, $resultDigest), $signature)) {
            throw new StudentImportFileException('This preview does not match the rows submitted. Upload the file again.');
        }
        try {
            $issued = CarbonImmutable::parse($issuedAt);
        } catch (Throwable) {
            throw new StudentImportFileException('This preview has expired. Upload the file again.');
        }
        if ($issued->subMinute()->isFuture() || $issued->addMinutes(self::PREVIEW_TTL_MINUTES)->isPast()) {
            throw new StudentImportFileException('This preview has expired. Upload the file again.');
        }
    }

    /**
     * @param  list<array{line: int, cells: array<string, string>}>  $rows
     */
    public function commit(School $school, User $actor, array $rows, string $issuedAt, string $resultDigest, string $signature, bool $warningsAcknowledged, FreshMfaProof $proof): StudentImportBatch
    {
        $this->assertSigned($school, $actor, $rows, $issuedAt, $resultDigest, $signature);
        if (! $proof->isFor($actor->id)) {
            throw new LogicException('A fresh MFA proof belongs to the acting user only.');
        }
        $digest = self::digest($rows);

        return $this->context->withSchool($school, function () use ($school, $actor, $rows, $resultDigest, $warningsAcknowledged, $proof, $digest): StudentImportBatch {
            try {
                return DB::transaction(function () use ($school, $actor, $rows, $resultDigest, $warningsAcknowledged, $proof, $digest): StudentImportBatch {
                    // Authority NOW, never the cache: the School operating and every key, whole School.
                    $this->operational->requireOperational($school->id);
                    foreach (self::AUTHORITY as $capability) {
                        if (! $this->capabilities->holdsInSchoolNow($actor, $capability, $school)) {
                            throw new AuthorizationException('This action is unauthorized.');
                        }
                    }
                    // A retry of committed content replays it -- only after authority (rule 32).
                    if (($existing = $this->committed($school, $digest)) !== null) {
                        return $existing;
                    }

                    $evaluation = $this->evaluate($school, $rows);
                    if (! self::committable($evaluation['rows'])) {
                        throw new StudentImportNotCommittableException($evaluation['rows'], $evaluation['summary'], 'Some rows can no longer be imported. Nothing was imported. Review the new preview.');
                    }
                    if (! hash_equals(self::resultDigest($evaluation['rows']), $resultDigest)) {
                        throw new StudentImportNotCommittableException($evaluation['rows'], $evaluation['summary'], 'The School\'s records changed after the preview was shown. Nothing was imported. Review the new preview.');
                    }
                    if ($evaluation['summary']['warning'] > 0 && ! $warningsAcknowledged) {
                        throw new StudentImportNotCommittableException($evaluation['rows'], $evaluation['summary'], 'Review and acknowledge the warnings before importing.');
                    }

                    // The content is claimed FIRST: a concurrent commit of the same
                    // content waits here and then replays (unique key, never a pre-check).
                    $batch = new StudentImportBatch;
                    $batch->forceFill([
                        'school_id' => $school->id, 'format_version' => self::FORMAT, 'content_digest' => $digest,
                        'row_count' => count($rows), 'students_created' => $evaluation['summary']['students'],
                        'guardians_created' => $evaluation['summary']['guardians'], 'relationships_created' => $evaluation['summary']['relationships'],
                        'placements_created' => $evaluation['summary']['placements'], 'campus_count' => $evaluation['summary']['campuses'],
                        'committed_by_user_id' => $actor->id,
                    ])->save();

                    $this->create($school, $actor, $rows, $evaluation['resolved']);

                    $this->audit->school($school, 'student_import.committed', actor: $actor, subject: $batch, metadata: [
                        'batchId' => $batch->id,
                        'formatVersion' => self::FORMAT,
                        'rows' => count($rows),
                        'students' => $evaluation['summary']['students'],
                        'guardians' => $evaluation['summary']['guardians'],
                        'relationships' => $evaluation['summary']['relationships'],
                        'placements' => $evaluation['summary']['placements'],
                        'campuses' => $evaluation['summary']['campuses'],
                        'warningsAcknowledged' => $evaluation['summary']['warning'],
                        'mfaVerifiedAt' => $proof->verifiedAt->toIso8601String(),
                    ]);

                    return $batch;
                });
            } catch (UniqueConstraintViolationException $e) {
                // The concurrent twin committed first: replay it. Anything else stays a refusal.
                return $this->committed($school, $digest) ?? throw $e;
            }
        });
    }

    /**
     * The canonical creations, in file order, inside the caller's transaction.
     *
     * @param  list<array{line: int, cells: array<string, string>}>  $rows
     * @param  array<int, array<string, mixed>>  $resolved  per line: the Section and the parsed Guardians
     */
    private function create(School $school, User $actor, array $rows, array $resolved): void
    {
        /** @var array<string, Guardian> $shared  batch-local key => the NEW Guardian */
        $shared = [];
        foreach ($rows as $row) {
            $r = $resolved[$row['line']];
            $identity = $r['identity'];
            try {
                // Exactly the candidates the importer saw: if the set changed since,
                // StudentService::create() refuses and the batch rolls back.
                $acknowledgement = $r['candidates']->isEmpty() ? null : $this->duplicates->acknowledgement($school, $identity, $r['candidates']);
                $student = $this->students->create($school, $identity, $actor, $acknowledgement);
                $this->placements->enroll($student, $r['section'], $r['roll'], $r['startsOn'], $actor);

                foreach ($r['guardians'] as $slot => $g) {
                    $guardian = $g['key'] !== null ? ($shared[$g['key']] ?? null) : null;
                    if ($guardian === null) {
                        $guardian = $this->guardians->create($school, ['first_name' => $g['first_name'], 'last_name' => $g['last_name']], $actor);
                        if ($g['email'] !== null) {
                            $this->contacts->create($guardian, ContactType::Email, $g['email'], ['is_primary' => true], $actor);
                        }
                        if ($g['mobile'] !== null) {
                            $this->contacts->create($guardian, ContactType::Mobile, $g['mobile'], ['is_primary' => true], $actor);
                        }
                        if ($g['key'] !== null) {
                            $shared[$g['key']] = $guardian;
                        }
                    }
                    $link = $this->relationships->link($student, $guardian, $g['relationship'], [
                        'is_legal_guardian' => $g['legal_guardian'], 'is_emergency_contact' => $g['emergency_contact'], 'is_authorized_pickup' => $g['authorized_pickup'],
                    ], $actor);
                    if ($slot === 1) {
                        $this->relationships->setPrimary($link, $actor);
                    }
                }
            } catch (AuthorizationException $e) {
                throw $e;
            } catch (Throwable $e) {
                // A canonical path refused under its own locks: nothing of the batch stays.
                throw new StudentImportRowFailedException($row['line'], $e);
            }
        }
    }

    /**
     * Every row's result from set-based reads (one query per kind, never per row).
     *
     * @param  list<array{line: int, cells: array<string, string>}>  $rows
     * @return array{rows: list<array<string, mixed>>, summary: array<string, int>, resolved: array<int, array<string, mixed>>}
     */
    private function evaluate(School $school, array $rows): array
    {
        $col = fn (string $name) => array_values(array_unique(array_filter(array_map(fn ($r) => mb_strtoupper($r['cells'][$name]), $rows))));
        $years = AcademicYear::query()->where('school_id', $school->id)->whereIn('code', $col('academic_year_code'))->get()->keyBy('code');
        $campuses = Campus::query()->where('school_id', $school->id)->whereIn('code', $col('campus_code'))->get()->keyBy('code');
        $grades = GradeLevel::query()->where('school_id', $school->id)->whereIn('code', $col('grade_code'))->get()->keyBy('code');
        $sections = Section::query()->where('school_id', $school->id)->whereIn('academic_year_id', $years->pluck('id'))->whereIn('code', $col('section_code'))->get()
            ->keyBy(fn (Section $s) => $s->academic_year_id.'|'.$s->campus_id.'|'.$s->grade_level_id.'|'.$s->code);
        $takenRolls = DB::table('student_enrollments')->where('school_id', $school->id)->whereIn('section_id', $sections->pluck('id'))
            ->get(['academic_year_id', 'section_id', 'roll_number'])->map(fn ($e) => $e->academic_year_id.'|'.$e->section_id.'|'.$e->roll_number)->flip();
        $birthdays = array_values(array_unique(array_filter(array_map(fn ($r) => self::date($r['cells']['date_of_birth']), $rows))));
        $existing = Student::query()->where('school_id', $school->id)->whereIn('date_of_birth', $birthdays)->get(['id', 'student_number', 'first_name', 'middle_name', 'last_name', 'date_of_birth']);

        $out = [];
        $resolved = [];
        $keyIdentity = [];
        $fileStudents = [];
        $fileRolls = [];
        $contactHashes = [];
        foreach ($rows as $row) {
            $c = $row['cells'];
            $issues = [];
            $error = function (string $field, string $message) use (&$issues): void {
                $issues[] = ['field' => $field, 'level' => self::ERROR, 'message' => $message];
            };
            $warn = function (string $field, string $message) use (&$issues): void {
                $issues[] = ['field' => $field, 'level' => self::WARNING, 'message' => $message];
            };

            foreach ($c as $field => $value) {
                if (preg_match('/[\p{Cc}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $value) === 1) {
                    $error($field, 'Contains a control or text-direction override character.');
                } elseif (mb_strlen($value) > self::MAX_CELL) {
                    $error($field, 'Longer than '.self::MAX_CELL.' characters.');
                }
            }

            // The Student.
            foreach (['first_name', 'date_of_birth', 'academic_year_code', 'campus_code', 'grade_code', 'section_code', 'roll_number', 'start_date'] as $required) {
                if ($c[$required] === '') {
                    $error($required, 'Required.');
                }
            }
            $dob = self::date($c['date_of_birth']);
            if ($c['date_of_birth'] !== '' && $dob === null) {
                $error('date_of_birth', 'Use the date format YYYY-MM-DD.');
            } elseif ($dob !== null && DateOfBirth::isFuture($school, $dob)) {
                $error('date_of_birth', DateOfBirth::FUTURE_MESSAGE);
            }
            $startsOn = self::date($c['start_date']);
            if ($c['start_date'] !== '' && $startsOn === null) {
                $error('start_date', 'Use the date format YYYY-MM-DD.');
            }

            // The placement: codes only, each resolved within THIS School.
            $year = $years->get(mb_strtoupper($c['academic_year_code']));
            $campus = $campuses->get(mb_strtoupper($c['campus_code']));
            $grade = $grades->get(mb_strtoupper($c['grade_code']));
            $section = null;
            if ($c['academic_year_code'] !== '' && $year === null) {
                $error('academic_year_code', 'No academic year has this code.');
            }
            if ($c['campus_code'] !== '' && $campus === null) {
                $error('campus_code', 'No Campus has this code.');
            }
            if ($c['grade_code'] !== '' && $grade === null) {
                $error('grade_code', 'No Grade has this code.');
            }
            if ($year !== null && $campus !== null && $grade !== null && $c['section_code'] !== '') {
                $section = $sections->get($year->id.'|'.$campus->id.'|'.$grade->id.'|'.mb_strtoupper($c['section_code']));
                if ($section === null) {
                    $error('section_code', 'No Section has this code for this year, Campus and Grade.');
                }
            }
            if ($year !== null && $year->status === 'closed') {
                $error('academic_year_code', 'This academic year is closed.');
            }
            if ($grade !== null && $grade->status !== 'active') {
                $error('grade_code', 'This Grade is not active.');
            }
            if ($campus !== null && $campus->status !== 'active') {
                $error('campus_code', 'This Campus is not active.');
            }
            if ($section !== null && $section->status !== 'active') {
                $error('section_code', 'This Section is not active.');
            }
            if ($year !== null && $startsOn !== null && ($startsOn < $year->starts_on->toDateString() || $startsOn > $year->ends_on->toDateString())) {
                $error('start_date', 'The start date must fall within the academic year ('.$year->starts_on->toDateString().' to '.$year->ends_on->toDateString().').');
            }
            $roll = $c['roll_number'];
            if ($section !== null && $roll !== '') {
                $rollKey = $section->academic_year_id.'|'.$section->id.'|'.$roll;
                if (isset($takenRolls[$rollKey])) {
                    $error('roll_number', 'This roll number is already used in this Section.');
                } elseif (isset($fileRolls[$rollKey])) {
                    $error('roll_number', 'This roll number appears earlier in the file for the same Section (row '.$fileRolls[$rollKey].').');
                } else {
                    $fileRolls[$rollKey] = $row['line'];
                }
            }

            // Duplicates: exact rule, never a link (a later identical row is an error).
            $candidates = new Collection;
            $identity = ['first_name' => $c['first_name'], 'middle_name' => $c['middle_name'] === '' ? null : $c['middle_name'], 'last_name' => $c['last_name'] === '' ? null : $c['last_name'], 'date_of_birth' => (string) $dob];
            if ($dob !== null && $c['first_name'] !== '') {
                foreach ($fileStudents as $line => $other) {
                    if ($other['date_of_birth'] === $dob && StudentDuplicateCandidates::matches($identity, $other)) {
                        $error('first_name', "The same Student appears earlier in the file (row {$line}).");
                        break;
                    }
                }
                $fileStudents[$row['line']] = $identity;
                $matches = $existing->filter(fn (Student $s) => $s->date_of_birth->toDateString() === $dob
                    && StudentDuplicateCandidates::matches($identity, ['first_name' => $s->first_name, 'middle_name' => $s->middle_name, 'last_name' => $s->last_name]));
                if ($matches->isNotEmpty()) {
                    $candidates = $matches->values();
                    $warn('first_name', 'Possible existing Student (number '.$matches->pluck('student_number')->implode(', ').') -- review required. Importing creates a NEW Student.');
                }
            }

            // The Guardians: always NEW; a batch-local key shares one across rows.
            $guardians = [];
            foreach ([1, 2] as $slot) {
                $g = [];
                foreach (self::GUARDIAN_FIELDS as $field) {
                    $g[$field] = $c["guardian{$slot}_{$field}"];
                }
                if (implode('', $g) === '') {
                    continue;
                }
                $p = "guardian{$slot}_";
                if ($g['first_name'] === '') {
                    $error($p.'first_name', 'Required for a Guardian.');
                }
                $relationship = RelationshipType::tryFrom($g['relationship']);
                if ($relationship === null) {
                    $error($p.'relationship', 'Use one of: '.implode(', ', array_map(fn (RelationshipType $t) => $t->value, RelationshipType::cases())).'.');
                }
                $flags = [];
                foreach (['legal_guardian', 'emergency_contact', 'authorized_pickup'] as $flag) {
                    $flags[$flag] = match (strtolower($g[$flag])) {
                        'yes' => true, '', 'no' => false, default => null,
                    };
                    if ($flags[$flag] === null) {
                        $error($p.$flag, 'Use yes or no.');
                    }
                }
                $email = null;
                $mobile = null;
                if ($g['email'] !== '') {
                    try {
                        $email = $this->emails->normalize($g['email']);
                    } catch (InvalidArgumentException) {
                        $error($p.'email', 'Not a valid email address.');
                    }
                }
                if ($g['mobile'] !== '') {
                    try {
                        $mobile = $this->phones->normalize($g['mobile']);
                    } catch (InvalidArgumentException) {
                        $error($p.'mobile', 'Not a valid mobile number.');
                    }
                }
                if ($email === null && $mobile === null && $g['email'] === '' && $g['mobile'] === '') {
                    $warn($p.'email', 'This Guardian has no email or mobile.');
                }
                $key = $g['key'] === '' ? null : mb_strtoupper($g['key']);
                if ($key !== null) {
                    $sig = json_encode([$g['first_name'], $g['last_name'], $email, $mobile]);
                    if (isset($keyIdentity[$key]) && $keyIdentity[$key]['sig'] !== $sig) {
                        $error($p.'key', "Guardian key {$g['key']} is used with different details (row {$keyIdentity[$key]['line']}). Repeat exactly the same Guardian details.");
                    }
                    if (isset($guardians[1]) && $guardians[1]['key'] === $key) {
                        $error($p.'key', 'The same Guardian cannot be both Guardians of one Student.');
                    }
                    $keyIdentity[$key] ??= ['sig' => $sig, 'line' => $row['line']];
                }
                foreach (['email' => $email, 'mobile' => $mobile] as $type => $value) {
                    if ($value !== null && ($key === null || $keyIdentity[$key]['line'] === $row['line'])) {
                        $contactHashes[$this->hasher->hash($school->id, $type, $value)][] = [$row['line'], $p.$type];
                    }
                }
                $guardians[$slot] = ['key' => $key, 'first_name' => $g['first_name'], 'last_name' => $g['last_name'] === '' ? null : $g['last_name'], 'relationship' => $relationship,
                    'email' => $email, 'mobile' => $mobile, ...$flags];
            }
            if ($guardians === []) {
                $warn('guardian1_first_name', 'This Student has no Guardian.');
            }

            $out[$row['line']] = ['line' => $row['line'], 'issues' => $issues,
                'student' => trim(implode(' ', array_filter([$c['first_name'], $c['middle_name'], $c['last_name']]))),
                'placement' => trim(implode(' · ', array_filter([$c['academic_year_code'], $c['campus_code'], $c['grade_code'], $c['section_code']]))).($roll !== '' ? " · roll {$roll}" : ''),
                'guardians' => array_values(array_map(fn (array $g) => trim($g['first_name'].' '.$g['last_name']).($g['relationship'] !== null ? " ({$g['relationship']->value})" : '').($g['key'] !== null ? " [{$g['key']}]" : ''), $guardians)),
                'campusId' => $campus?->id];
            $resolved[$row['line']] = ['identity' => $identity, 'section' => $section, 'roll' => $roll, 'startsOn' => (string) $startsOn, 'guardians' => $guardians, 'candidates' => $candidates];
        }

        // An existing Guardian with the same email or mobile: a warning, never a link.
        if ($contactHashes !== []) {
            $known = DB::table('guardian_contacts')->where('school_id', $school->id)->whereIn('lookup_hash', array_keys($contactHashes))->pluck('lookup_hash')->flip();
            foreach ($contactHashes as $hash => $uses) {
                foreach ($uses as [$line, $field]) {
                    if (isset($known[$hash])) {
                        $out[$line]['issues'][] = ['field' => $field, 'level' => self::WARNING, 'message' => 'An existing Guardian already has this contact. Importing creates a NEW Guardian; link the existing one by hand instead if it is the same person.'];
                    } elseif (count($uses) > 1) {
                        $out[$line]['issues'][] = ['field' => $field, 'level' => self::WARNING, 'message' => 'The same contact is given to two new Guardians; use one guardian key if they are the same person.'];
                    }
                }
            }
        }

        $rowsOut = [];
        $summary = ['rows' => count($rows), self::READY => 0, self::WARNING => 0, self::ERROR => 0, 'students' => 0, 'guardians' => 0, 'relationships' => 0, 'placements' => 0, 'campuses' => 0];
        $keys = [];
        $campusIds = [];
        foreach ($out as $line => $o) {
            $levels = array_column($o['issues'], 'level');
            $o['result'] = in_array(self::ERROR, $levels, true) ? self::ERROR : (in_array(self::WARNING, $levels, true) ? self::WARNING : self::READY);
            $summary[$o['result']]++;
            $summary['students']++;
            $summary['placements']++;
            foreach ($resolved[$line]['guardians'] as $g) {
                $summary['relationships']++;
                if ($g['key'] === null || ! isset($keys[$g['key']])) {
                    $summary['guardians']++;
                }
                if ($g['key'] !== null) {
                    $keys[$g['key']] = true;
                }
            }
            if ($o['campusId'] !== null) {
                $campusIds[$o['campusId']] = true;
            }
            unset($o['campusId']);
            $rowsOut[] = $o;
        }
        $summary['campuses'] = count($campusIds);

        return ['rows' => $rowsOut, 'summary' => $summary, 'resolved' => $resolved];
    }

    /** @param  list<array<string, mixed>>  $rows */
    private static function committable(array $rows): bool
    {
        return $rows !== [] && ! in_array(self::ERROR, array_column($rows, 'result'), true);
    }

    /**
     * What the importer saw: every row's result and issues.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function resultDigest(array $rows): string
    {
        return hash('sha256', json_encode(array_map(fn (array $r) => [$r['line'], $r['result'], $r['issues']], $rows), JSON_THROW_ON_ERROR));
    }

    /**
     * The content identity of a batch (format + normalized rows): the same
     * content is committed at most once per School.
     *
     * @param  list<array{line: int, cells: array<string, string>}>  $rows
     */
    public static function digest(array $rows): string
    {
        // Keyed: the stored digest can never confirm a guessed row.
        return hash_hmac('sha256', self::FORMAT.'|'.json_encode(array_map(fn (array $r) => $r['cells'], $rows), JSON_THROW_ON_ERROR), hash_hmac('sha256', 'student-import-digest', (string) config('app.key')));
    }

    /** @phpstan-impure */
    private function committed(School $school, string $digest): ?StudentImportBatch
    {
        return StudentImportBatch::query()->where('school_id', $school->id)->where('content_digest', $digest)->first();
    }

    /** @param  list<array{line: int, cells: array<string, string>}>  $rows */
    private function sign(School $school, User $actor, string $issuedAt, array $rows, string $resultDigest): string
    {
        $key = hash_hmac('sha256', 'student-guardian-import', (string) config('app.key'));

        return hash_hmac('sha256', json_encode([$school->id, $actor->id, self::FORMAT, $issuedAt, $rows, $resultDigest], JSON_THROW_ON_ERROR), $key);
    }

    /** A strict calendar date (YYYY-MM-DD), else null. */
    private static function date(string $value): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return $value;
    }

    /** Laravel's own TrimStrings rule (Str::trim), so a cell survives the commit round trip unchanged. */
    private static function trim(string $value): string
    {
        return Str::trim($value);
    }
}
