<?php

namespace Database\Seeders\Demo;

use App\Domain\Guardians\Application\StudentSiblingReader;
use App\Domain\Students\Application\Certificates\StudentCertificateEligibility;
use App\Domain\Students\Application\Documents\StudentDocumentCategory;
use App\Domain\Students\Application\Movements\StudentMovementRegister;
use App\Domain\Students\Application\StudentAccess;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\Campus\CampusScopeResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * SIS.EXP-1: proves the canonical SIS demonstration after every build --
 * semantic facts, read through the real readers where authority matters,
 * never by trusting the seed. DEMO-ONLY: run by DemoSeeder after
 * `ddev demo-reset` and by the automated suite; never a production health
 * check, endpoint or command.
 */
final class DemoSisVerifier
{
    /** @return list<string> problems (empty = the SIS demonstration is intact) */
    public function problems(): array
    {
        $school = School::query()->where('slug', DemoSisData::SCHOOL_SLUG)->first();
        if ($school === null) {
            return ['Northstar Gachibowli is missing'];
        }

        return app(TenantContext::class)->withSchool($school, function () use ($school): array {
            $problems = [];
            $check = function (bool $ok, string $message) use (&$problems): void {
                if (! $ok) {
                    $problems[] = $message;
                }
            };

            $placements = DB::table('student_enrollments as e')->join('campuses as c', 'c.id', '=', 'e.campus_id')
                ->where('e.school_id', $school->id)->where('e.status', 'active')
                ->selectRaw('c.code, count(*) as n')->groupBy('c.code')->pluck('n', 'code')->all();
            $check(Student::query()->count() === 18, 'Gachibowli does not have exactly 18 Students');
            $check(($placements['GCB-MAIN'] ?? 0) === 13 && ($placements['GCB-EY'] ?? 0) === 3, 'Gachibowli current placements are not 13 on the Main Campus and 3 on Early Years');

            // SIS.EXP-2: the movement history, read through the register itself.
            $register = app(StudentMovementRegister::class);
            $of = fn (string $type): array => array_map(fn (array $r) => $r['student']['name'], $register->page($school, ['type' => $type], true)->items());
            $check($of('promotion') === ['Rohan Pillai'], 'the 2025-26 rollover does not show Rohan Pillai promoted');
            $check($of('retention') === ['Anika Joshi'], 'the 2025-26 rollover does not show Anika Joshi repeating');
            $check($of('placement_completed') === ['Arjun Mehta'], 'Arjun Mehta is not the one completed exit');
            $check($of('campus_transfer') === ['Kiara Sen'] && $of('section_change') === ['Kiara Sen'], 'Kiara Sen does not show one Campus transfer then one Section change');
            $transfer = $register->page($school, ['type' => 'campus_transfer'], true)->items()[0] ?? null;
            $check($transfer !== null && [$transfer['from']['campus'], $transfer['to']['campus']] === ['Gachibowli Early Years Campus', 'Gachibowli Main Campus'], 'the Campus transfer does not run Early Years → Main');

            $aarav = Student::query()->where('first_name', 'Aarav')->where('last_name', 'Menon')->first();
            $diya = Student::query()->where('first_name', 'Diya')->where('last_name', 'Menon')->first();
            if ($aarav === null || $diya === null) {
                return [...$problems, 'the Menon siblings are missing'];
            }
            $check(DB::table('student_guardian_relationships')->where('student_id', $aarav->id)->count() === 2, 'Aarav Menon does not have two Guardians');
            $check(array_keys(app(StudentSiblingReader::class)->siblingsOf($aarav)) === [(string) $diya->id], 'Aarav Menon\'s only sibling is not Diya Menon');
            $placement = DB::table('student_enrollments as e')->join('sections as s', 's.id', '=', 'e.section_id')->join('grade_levels as g', 'g.id', '=', 'e.grade_level_id')->join('campuses as c', 'c.id', '=', 'e.campus_id')
                ->where('e.student_id', $aarav->id)->where('e.status', 'active')->first(['g.name as grade', 's.name as section', 'c.code as campus']);
            $check($placement !== null && [$placement->grade, $placement->section, $placement->campus] === ['Grade 5', 'A', 'GCB-MAIN'], 'Aarav Menon is not in Grade 5 · A on the Main Campus');

            // His Student 360 has something in every implemented card.
            $check(DB::table('charges')->where('student_id', $aarav->id)->whereNull('cancelled_at')->count() === 1, 'Aarav Menon has no single open Charge');
            $check(DB::table('payment_allocations')->join('charges', 'charges.id', '=', 'payment_allocations.charge_id')->where('charges.student_id', $aarav->id)->exists(), 'Aarav Menon\'s Charge has no payment');
            $check(DB::table('transport_student_assignments')->where('student_id', $aarav->id)->where('status', 'active')->exists(), 'Aarav Menon has no rider assignment');
            $check(DB::table('library_loans')->where('student_id', $aarav->id)->where('status', 'active')->exists(), 'Aarav Menon has no library loan');
            $check(DB::table('attendance_records as r')->join('student_enrollments as e', 'e.id', '=', 'r.student_enrollment_id')->where('e.student_id', $aarav->id)->count() === 5, 'Aarav Menon does not have five attendance records');

            // No relationship crosses a School (the composite keys say so; the demo proves it).
            $check(! DB::table('student_guardian_relationships as r')->join('students as st', 'st.id', '=', 'r.student_id')->join('guardians as g', 'g.id', '=', 'r.guardian_id')
                ->whereColumn('st.school_id', '!=', 'g.school_id')->exists(), 'a Student-Guardian link crosses Schools');

            // SIS.EXP-3: the fictional record documents -- approved categories only,
            // each record on its own Student and School, every file present.
            $docs = fn (Student $s, bool $active) => DB::table('student_documents')->where('student_id', $s->id)
                ->when($active, fn ($q) => $q->whereNull('archived_at'), fn ($q) => $q->whereNotNull('archived_at'))->orderBy('category')->pluck('category')->all();
            $check($docs($aarav, true) === ['consent_form', 'enrollment_support', 'prior_school_record'], 'Aarav Menon does not have his three active documents');
            $check($docs($aarav, false) === ['enrollment_support'], 'Aarav Menon does not have one archived (superseded) document');
            $check($docs($diya, true) === ['correspondence'], 'Diya Menon does not have her one document');
            $check(DB::table('student_documents')->whereNotIn('category', StudentDocumentCategory::values())->doesntExist(), 'a Student document has an unapproved category');
            $check(! DB::table('student_documents as sd')->join('documents as d', 'd.id', '=', 'sd.document_id')->join('students as st', 'st.id', '=', 'sd.student_id')
                ->where(fn ($q) => $q->whereColumn('d.school_id', '!=', 'sd.school_id')->orWhereColumn('st.school_id', '!=', 'sd.school_id')->orWhereColumn('d.student_id', '!=', 'sd.student_id'))->exists(), 'a Student document crosses a Student or School');
            $files = DB::table('student_documents as sd')->join('documents as d', 'd.id', '=', 'sd.document_id')->get(['d.storage_disk', 'd.storage_path', 'd.classification_tier']);
            $check($files->count() === 8 && $files->every(fn ($f) => $f->classification_tier === 'highly_sensitive' && Storage::disk($f->storage_disk)->exists($f->storage_path)), 'a Gachibowli Student document file is missing or not Highly Sensitive');

            // SIS.EXP-4: Meera's certificates -- LC-000001 voided as replaced by
            // LC-000002, and TC-000001; each with its own PDF Student document.
            $meera = Student::query()->where('first_name', 'Meera')->where('last_name', 'Iyer')->first();
            if ($meera === null) {
                $problems[] = 'Meera Iyer (the certificate leaver) is missing';
            } else {
                $certs = DB::table('student_certificates as c')->join('student_documents as sd', 'sd.document_id', '=', 'c.document_id')->join('documents as d', 'd.id', '=', 'c.document_id')
                    ->where('c.student_id', $meera->id)->orderBy('c.certificate_type')->orderBy('c.serial')
                    ->get(['c.id', 'c.display_number', 'c.status', 'c.void_reason', 'c.supersedes_certificate_id', 'c.student_name', 'sd.category', 'd.mime_type', 'd.storage_disk', 'd.storage_path']);
                $check($certs->map(fn ($c) => "{$c->display_number}:{$c->status}")->all() === ['LC-000001:voided', 'LC-000002:issued', 'TC-000001:issued'], 'Meera Iyer does not have LC-000001 (void), LC-000002 and TC-000001');
                $check($certs->count() === 3 && $certs[1]->supersedes_certificate_id === $certs[0]->id && $certs[0]->void_reason === 'replaced_by_corrected_certificate', 'LC-000002 does not replace the voided LC-000001');
                $check($certs->count() === 3 && [$certs[0]->student_name, $certs[1]->student_name] === ['Meera Iyr', 'Meera Iyer'], 'the original keeps its frozen (misspelt) snapshot and the replacement the corrected one');
                $check($certs->every(fn ($c) => $c->category === 'issued_certificate' && $c->mime_type === 'application/pdf' && Storage::disk($c->storage_disk)->exists($c->storage_path)), 'a certificate PDF is missing or not an issued-certificate Student document');
            }
            $arjun = Student::query()->where('first_name', 'Arjun')->where('last_name', 'Mehta')->first();
            $check($arjun !== null && app(StudentCertificateEligibility::class)->evaluate($school, $arjun)['refusal'] === 'exit_not_recorded', 'Arjun Mehta is not the "exit not recorded" (ineligible) example');
            $check(DB::table('student_certificates')->count() === 3 && DB::table('student_certificates')->whereColumn('school_id', '!=', DB::raw("'".$school->id."'"))->doesntExist(), 'a certificate exists outside Meera\'s three');

            // The Early Years Principal reaches exactly the Early Years Students.
            $principal = User::query()->where('email', 'gachibowli.ey.principal@example.test')->first();
            if ($principal === null) {
                $problems[] = 'the Early Years Principal is missing';
            } else {
                $scope = app(CampusScopeResolver::class)->campusScope($principal, 'students.view', $school);
                $reached = app(StudentAccess::class)->students($school, $scope)->orderBy('first_name')->pluck('first_name')->all();
                $check(! $scope->isWholeSchool() && $reached === ['Diya', 'Neel', 'Tara'], 'the Early Years Principal does not reach exactly the Early Years Students');
            }

            return $problems;
        });
    }

    /** @return array<string, int> */
    public function summary(): array
    {
        $school = School::query()->where('slug', DemoSisData::SCHOOL_SLUG)->firstOrFail();

        return app(TenantContext::class)->withSchool($school, fn (): array => [
            'gachibowliStudents' => Student::query()->count(),
            'gachibowliGuardians' => DB::table('guardians')->where('school_id', $school->id)->count(),
            'gachibowliActivePlacements' => DB::table('student_enrollments')->where('school_id', $school->id)->where('status', 'active')->count(),
            'gachibowliMovements' => app(StudentMovementRegister::class)->page($school, [], true)->total(),
            'gachibowliStudentDocuments' => DB::table('student_documents')->where('school_id', $school->id)->count(),
            'gachibowliCertificates' => DB::table('student_certificates')->where('school_id', $school->id)->count(),
        ]);
    }
}
