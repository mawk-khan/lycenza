<?php

namespace App\Domain\Students\Application\Certificates;

use App\Domain\Documents\Application\DocumentService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentCertificate;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\SchoolScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * SIS.EXP-4: a Student's certificate history -- safe metadata only (type,
 * number, issue date, state, reason codes and the replacement links), one
 * query whatever the count. Never the snapshot's personal values beyond the
 * number and dates, never Finance.
 *
 * Visible to holders of `students.documents.view` or
 * `students.certificates.issue` (whole School); the download link (the
 * Student document download, behind current MFA) only to
 * `students.documents.view`.
 */
final class StudentCertificateReader
{
    public function __construct(private readonly TenantContext $context) {}

    public static function canSee(User $actor, School $school): bool
    {
        $gate = Gate::forUser($actor);

        return $gate->allows('capability', [DocumentService::STUDENT_DOCUMENTS_VIEW, $school])
            || $gate->allows('capability', [DocumentService::STUDENT_CERTIFICATES_ISSUE, $school]);
    }

    /** @return list<array<string, mixed>>|null null when the viewer may not see certificates */
    public function historyFor(School $school, User $actor, Student $student): ?array
    {
        if (! self::canSee($actor, $school)) {
            return null;
        }
        $canDownload = Gate::forUser($actor)->allows('capability', [DocumentService::STUDENT_DOCUMENTS_VIEW, $school]);

        // Aliased self-joins: the School is filtered explicitly on every alias
        // (and forced RLS applies), so the unaliased SchoolScope is replaced.
        $rows = $this->context->withSchool($school, fn () => StudentCertificate::query()->withoutGlobalScope(SchoolScope::class)
            ->from('student_certificates as c')
            ->leftJoin('student_documents as d', fn ($j) => $j->on('d.document_id', '=', 'c.document_id')->where('d.school_id', '=', $school->id))
            ->leftJoin('student_certificates as r', fn ($j) => $j->on('r.supersedes_certificate_id', '=', 'c.id')->where('r.school_id', '=', $school->id))
            ->where('c.school_id', $school->id)->where('c.student_id', $student->id)
            ->orderByDesc('c.issued_at')->orderByDesc('c.id')
            ->get(['c.*', 'd.id as record_id', 'r.id as replaced_by_id', 'r.display_number as replaced_by_number', DB::raw('(SELECT p.display_number FROM student_certificates p WHERE p.id = c.supersedes_certificate_id AND p.school_id = c.school_id) AS supersedes_number')]));

        return $rows->map(fn (StudentCertificate $c): array => [
            'id' => $c->id,
            'type' => $c->certificate_type->value,
            'typeLabel' => $c->certificate_type->label(),
            'number' => $c->display_number,
            'issuedOn' => $c->issued_on->toDateString(),
            'leftOn' => $c->left_on->toDateString(),
            'status' => $c->status,
            'voidReason' => $c->void_reason?->value,
            'voidReasonLabel' => $c->void_reason?->label(),
            'voidedOn' => $c->voided_at?->toIso8601String(),
            'supersedesNumber' => $c->getAttribute('supersedes_number'),
            'replacedByNumber' => $c->getAttribute('replaced_by_number'),
            'sourceEnrollmentId' => $c->source_enrollment_id,
            'downloadUrl' => $canDownload && $c->getAttribute('record_id') !== null
                ? "/app/students/{$student->id}/documents/{$c->getAttribute('record_id')}/download"
                : null,
        ])->values()->all();
    }
}
