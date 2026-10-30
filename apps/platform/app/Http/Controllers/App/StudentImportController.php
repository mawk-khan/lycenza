<?php

namespace App\Http\Controllers\App;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\Guardians\Infrastructure\RelationshipType;
use App\Domain\Students\Application\Import\StudentGuardianImport;
use App\Domain\Students\Application\Import\StudentImportFileException;
use App\Domain\Students\Application\Import\StudentImportNotCommittableException;
use App\Domain\Students\Application\Import\StudentImportRowFailedException;
use App\Domain\Students\Infrastructure\StudentImportBatch;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Models\School;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SIS.EXP-5 (OD-SIS-3): the add-only bulk Student & Guardian import page.
 * Thin: the route floor is `students.import` (whole School); the commit
 * checks the signed preview and its expiry BEFORE a fresh MFA code is
 * consumed, and StudentGuardianImport re-reads every authority inside its
 * transaction. The upload is read in its request only and never stored.
 */
class StudentImportController extends Controller
{
    public function create(TenantContext $context, FreshMfaRequirement $mfa): Response
    {
        $this->authorizeImport($context);

        return $this->page($context, $mfa, null);
    }

    /** The blank v1 template: the header and two fictional example rows, no School data. */
    public function template(): HttpResponse
    {
        $example = [
            ['Asha', '', 'Example', '2016-04-12', 'AY2026', 'MAIN', 'G5', 'A', '31', '2026-06-01', 'FAM-1', 'Ravi', 'Example', 'father', 'ravi.example@example.test', '', 'yes', 'yes', 'yes', '', 'Meena', 'Example', 'mother', '', '+919810000001', 'yes', 'no', 'yes'],
            ['Arun', '', 'Example', '2019-09-30', 'AY2026', 'MAIN', 'G2', 'A', '12', '2026-06-01', 'FAM-1', 'Ravi', 'Example', 'father', 'ravi.example@example.test', '', 'yes', 'yes', 'yes', '', '', '', '', '', '', '', '', ''],
        ];
        $csv = implode(',', StudentGuardianImport::columns())."\n".implode("\n", array_map(fn (array $r) => implode(',', $r), $example))."\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.StudentGuardianImport::FORMAT.'-template.csv"',
        ]);
    }

    public function preview(Request $request, TenantContext $context, FreshMfaRequirement $mfa, StudentGuardianImport $import): Response
    {
        $school = $context->requireSchool();
        // The preview reveals possible existing Students and Guardian contacts:
        // it needs every key the import does, never students.import alone.
        $this->authorizeImport($context);
        $request->validate(['file' => ['required', 'file', 'max:'.(StudentGuardianImport::MAX_BYTES / 1024), 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel']]);
        // Read in this request only; PHP removes the upload when it ends.
        $contents = (string) file_get_contents((string) $request->file('file')?->getRealPath());

        try {
            $rows = $import->parse($contents);
        } catch (StudentImportFileException $e) {
            throw ValidationException::withMessages(['file' => [$e->getMessage()]]);
        }
        $preview = $import->preview($school, $context->actor(), $rows);

        return $this->page($context, $mfa, ['payload' => $rows, ...$preview, 'resultDigest' => StudentGuardianImport::resultDigest($preview['rows'])]);
    }

    public function commit(Request $request, TenantContext $context, FreshMfaRequirement $mfa, StudentGuardianImport $import): Response|RedirectResponse
    {
        $school = $context->requireSchool();
        $actor = $context->actor();
        $this->authorizeImport($context);
        $validated = $request->validate([
            'payload' => ['required', 'array', 'min:1', 'max:'.StudentGuardianImport::MAX_ROWS],
            'payload.*.line' => ['required', 'integer', 'min:2'],
            'payload.*.cells' => ['required', 'array'],
            'issued_at' => ['required', 'string', 'max:40'],
            'result_digest' => ['required', 'string', 'size:64'],
            'signature' => ['required', 'string', 'size:64'],
            'acknowledge_warnings' => ['nullable', 'boolean'],
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => 'Confirm the import before continuing.']);
        // The exact shape parse() produced (every v1 column, as a string); anything else fails the signature.
        $rows = array_map(fn (array $r) => [
            'line' => (int) $r['line'],
            'cells' => array_combine(StudentGuardianImport::columns(), array_map(fn (string $c) => is_string($r['cells'][$c] ?? null) ? $r['cells'][$c] : '', StudentGuardianImport::columns())),
        ], array_values($validated['payload']));

        // The preview first: a stale or forged one never consumes a code.
        try {
            $import->assertSigned($school, $actor, $rows, $validated['issued_at'], $validated['result_digest'], $validated['signature']);
        } catch (StudentImportFileException $e) {
            throw ValidationException::withMessages(['file' => [$e->getMessage()]]);
        }
        $proof = $mfa->requireForAction($request, $actor);

        try {
            $batch = $import->commit($school, $actor, $rows, $validated['issued_at'], $validated['result_digest'], $validated['signature'], (bool) ($validated['acknowledge_warnings'] ?? false), $proof);
        } catch (StudentImportFileException $e) {
            throw ValidationException::withMessages(['file' => [$e->getMessage()]]);
        } catch (StudentImportNotCommittableException $e) {
            return $this->page($context, $mfa, ['payload' => $rows, 'rows' => $e->rows, 'summary' => $e->summary, 'committable' => false, 'alreadyImported' => null, 'issuedAt' => null, 'signature' => null, 'resultDigest' => null], $e->getMessage());
        } catch (StudentImportRowFailedException $e) {
            throw ValidationException::withMessages(['refusal' => [$e->getMessage()]]);
        }

        return redirect('/app/students/import')->with('status', "Imported {$batch->students_created} Student(s), {$batch->guardians_created} Guardian(s) and {$batch->placements_created} placement(s).");
    }

    /** Every key of the import, whole School (the service re-reads them fresh at commit). */
    private function authorizeImport(TenantContext $context): void
    {
        foreach (StudentGuardianImport::AUTHORITY as $capability) {
            Gate::forUser($context->actor())->authorize('capability', [$capability, $context->requireSchool()]);
        }
    }

    /** @param  array<string, mixed>|null  $preview */
    private function page(TenantContext $context, FreshMfaRequirement $mfa, ?array $preview, ?string $refusal = null): Response
    {
        $school = $context->requireSchool();

        return Inertia::render('App/Students/Import', [
            'preview' => $preview,
            'refusal' => $refusal,
            'format' => StudentGuardianImport::FORMAT,
            'limits' => ['rows' => StudentGuardianImport::MAX_ROWS, 'kilobytes' => StudentGuardianImport::MAX_BYTES / 1024, 'minutes' => StudentGuardianImport::PREVIEW_TTL_MINUTES],
            'columns' => StudentGuardianImport::columns(),
            'relationships' => array_map(fn (RelationshipType $t) => $t->value, RelationshipType::cases()),
            'codes' => $this->codes($context, $school),
            'history' => $context->withSchool($school, fn () => StudentImportBatch::query()->where('school_id', $school->id)
                ->leftJoin('users', 'users.id', '=', 'student_import_batches.committed_by_user_id')
                ->orderByDesc('committed_at')->limit(20)
                ->get(['student_import_batches.id', 'committed_at', 'format_version', 'row_count', 'students_created', 'guardians_created', 'placements_created', 'users.name as actor'])
                ->map(fn ($b) => ['id' => $b->id, 'committedAt' => $b->committed_at->toIso8601String(), 'format' => $b->format_version, 'rows' => $b->row_count,
                    'students' => $b->students_created, 'guardians' => $b->guardians_created, 'placements' => $b->placements_created, 'actor' => $b->getAttribute('actor')])->all()),
            'hasMfaFactor' => $mfa->hasActiveFactor($context->actor()),
        ]);
    }

    /**
     * The School's own codes for the template: years (not closed), Campuses,
     * Grades and active Sections. Four bounded reads.
     *
     * @return array<string, mixed>
     */
    private function codes(TenantContext $context, School $school): array
    {
        return $context->withSchool($school, fn () => [
            'years' => AcademicYear::query()->where('school_id', $school->id)->where('status', '!=', 'closed')->orderBy('starts_on')->get(['code', 'name', 'starts_on', 'ends_on'])
                ->map(fn ($y) => ['code' => $y->code, 'name' => $y->name, 'startsOn' => $y->starts_on->toDateString(), 'endsOn' => $y->ends_on->toDateString()])->all(),
            'campuses' => Campus::query()->where('school_id', $school->id)->where('status', 'active')->orderBy('code')->get(['code', 'name'])->map(fn ($c) => ['code' => $c->code, 'name' => $c->name])->all(),
            'grades' => GradeLevel::query()->where('school_id', $school->id)->where('status', 'active')->orderBy('sequence')->get(['code', 'name'])->map(fn ($g) => ['code' => $g->code, 'name' => $g->name])->all(),
            'sections' => DB::table('sections as s')->join('academic_years as y', 'y.id', '=', 's.academic_year_id')->join('campuses as c', 'c.id', '=', 's.campus_id')->join('grade_levels as g', 'g.id', '=', 's.grade_level_id')
                ->where('s.school_id', $school->id)->where('s.status', 'active')->where('y.status', '!=', 'closed')
                ->orderBy('y.starts_on')->orderBy('c.code')->orderBy('g.sequence')->orderBy('s.code')
                ->get(['y.code as year', 'c.code as campus', 'g.code as grade', 's.code as section'])->map(fn ($s) => (array) $s)->all(),
        ]);
    }
}
