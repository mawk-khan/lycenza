<?php

namespace Tests\Feature\Students;

use App\Domain\Hostel\Application\HostelTransferBlocker;
use App\Domain\Students\Application\CampusStudentTransferService;
use App\Domain\Students\Application\ElectiveTransferBlocker;
use App\Domain\Students\Application\StudentTransferBlocker;
use App\Domain\Students\Application\StudentTransferCampusGuard;
use App\Domain\Transport\Application\TransportTransferBlocker;
use App\Support\Authorization\Campus\CampusScopeCatalogue;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * ORG.7F-3 (ADR 0076 §47): the Campus transfer stays the narrow, named
 * operation it was approved as -- pinned on code and the database, never on
 * comments.
 */
class StudentCampusTransferArchitectureTest extends TestCase
{
    private function source(string $class): string
    {
        return (string) file_get_contents((string) (new ReflectionClass($class))->getFileName());
    }

    #[Test]
    public function the_database_freezes_every_placement_context_column(): void
    {
        $definition = (string) DB::connection('pgsql_admin')->selectOne("select pg_get_triggerdef(oid) as d from pg_trigger where tgname = 'student_enrollments_freeze_placement'")->d;
        $this->assertStringContainsString('BEFORE UPDATE OF academic_year_id, campus_id, grade_level_id, section_id ON public.student_enrollments', $definition);
        $function = (string) DB::connection('pgsql_admin')->selectOne("select prosrc from pg_proc where proname = 'student_enrollments_freeze_placement'")->prosrc;
        foreach (['academic_year_id', 'campus_id', 'grade_level_id', 'section_id'] as $column) {
            $this->assertStringContainsString("NEW.{$column} IS DISTINCT FROM OLD.{$column}", $function);
        }
    }

    #[Test]
    public function the_campus_transfer_is_the_named_transfer_with_its_guard_and_never_generic_enroll(): void
    {
        $service = $this->source(CampusStudentTransferService::class);
        $this->assertStringContainsString('->lockedTransferTarget(', $service);
        $this->assertStringContainsString('->transferPlacement(', $service);
        $this->assertStringContainsString('new StudentTransferCampusGuard(', $service);
        $this->assertStringNotContainsString('->enroll(', $service, 'a Campus transfer never enrolls an existing Student generically');

        $built = [];
        foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
            if (str_contains($file->getContents(), 'new StudentTransferCampusGuard(')) {
                $built[] = $file->getRelativePathname();
            }
        }
        $this->assertSame(['Domain/Students/Application/CampusStudentTransferService.php'], $built, 'only the Campus transfer service builds the dual-scope guard');

        $guard = $this->source(StudentTransferCampusGuard::class);
        foreach (['->covers($lockedSource->campus_id)', '->covers($lockedTarget->campus_id)', '$lockedSource->campus_id === $lockedTarget->campus_id', '->blocksCampusTransfer('] as $check) {
            $this->assertStringContainsString($check, $guard);
        }
    }

    #[Test]
    public function blockers_only_answer_and_every_one_is_registered(): void
    {
        $registered = array_map(fn ($b) => $b::class, iterator_to_array(app()->tagged(StudentTransferBlocker::TAG)));
        sort($registered);
        $this->assertSame([HostelTransferBlocker::class, ElectiveTransferBlocker::class, TransportTransferBlocker::class], $registered);

        foreach ([...$registered, CampusStudentTransferService::class, StudentTransferCampusGuard::class] as $class) {
            $source = $this->source($class);
            foreach (['->update(', '->delete(', '->forceFill(', '->save(', '->end(', '->withdraw(', '->cancel(', 'DB::table('] as $write) {
                $this->assertStringNotContainsString($write, $source, "{$class} never changes another domain's records ({$write})");
            }
        }
        foreach ($registered as $class) {
            $this->assertStringContainsString('->exists()', $this->source($class));
        }
    }

    #[Test]
    public function only_the_current_placement_transfer_is_campus_aware(): void
    {
        foreach (['app.enrollments.transfer.create', 'app.enrollments.transfer.store', 'app.students.enrollments.store', 'app.enrollments.withdraw', 'app.enrollment-rollovers.start', 'app.students.status'] as $route) {
            $this->assertArrayHasKey($route, CampusScopeCatalogue::WHOLE_SCHOOL_OPERATIONS, "{$route} stays whole-School");
        }
        $this->assertArrayNotHasKey('api.v1.schools.enrollments.transfer', CampusScopeCatalogue::WHOLE_SCHOOL_OPERATIONS);
        $this->assertStringContainsString("'capability:enrollments.manage,campus'", (string) file_get_contents(base_path('routes/api.php')));
    }
}
