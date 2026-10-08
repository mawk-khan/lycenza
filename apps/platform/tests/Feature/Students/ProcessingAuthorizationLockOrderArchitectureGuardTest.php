<?php

namespace Tests\Feature\Students;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * S5 (ADR 0038 lock-order amendment, 2026-10-07): structural pins for the one
 * canonical order -- Student FOR UPDATE -> its processing-authorization grants
 * -> its guardian relationships -- and for the narrow StudentMark translation
 * of a deadlock / serialization abort. The behaviour itself is proven by
 * GuardianProcessingAuthorizationLockOrderTest (real processes).
 */
class ProcessingAuthorizationLockOrderArchitectureGuardTest extends TestCase
{
    private function code(string $relative): string
    {
        return (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents(app_path($relative)));
    }

    /** The body of one method, comments stripped. */
    private function method(string $relative, string $name): string
    {
        preg_match('/function '.$name.'\(.*?\n    \}/s', $this->code($relative), $m);
        $this->assertNotEmpty($m, "{$relative}::{$name}()");

        return $m[0];
    }

    private function assertInOrder(string $code, array $needles, string $message): void
    {
        $positions = array_map(fn (string $n) => strpos($code, $n), $needles);
        $this->assertNotContains(false, $positions, $message.' (missing step)');
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, $message);
    }

    #[Test]
    public function the_student_is_the_root_of_every_path(): void
    {
        $seam = $this->method('Domain/Students/Application/StudentProcessingAuthorizationReadService.php', 'lockQualifyingAuthorizationIdForProcessing');
        $this->assertInOrder($seam, ['Student::query()->lockForUpdate()', 'StudentProcessingAuthorization::query()', 'StudentGuardianRelationship::query()->lockForUpdate()'],
            'the ADR 0038 seam: Student, then grants, then relationships');

        $terminate = $this->method('Domain/Students/Application/StudentProcessingAuthorizationService.php', 'terminate');
        $this->assertInOrder($terminate, ['Student::query()', 'lockForUpdate()', 'StudentProcessingAuthorization::query()'], 'withdraw / revoke / supersede: Student before grants');

        $lock = $this->code('Domain/Students/Application/StudentLockOrder.php');
        $this->assertStringContainsString("Student::query()->where('school_id', \$school->id)->whereKey(\$studentId)->lockForUpdate()", $lock, 'one School-scoped Student row, FOR UPDATE');
        $this->assertStringNotContainsString('LOCK TABLE', $lock);
        $this->assertStringNotContainsString('pg_advisory', $lock);
    }

    #[Test]
    public function guardian_relationship_writers_take_the_student_first(): void
    {
        $service = 'Domain/Guardians/Application/StudentGuardianRelationshipService.php';
        foreach (['unlink', 'setPrimary', 'update'] as $writer) {
            $body = $this->method($service, $writer);
            $this->assertMatchesRegularExpression('/DB::transaction\(function \(\) use \([^)]*\) \{\s*\$this->lockOrder->holdStudent\(\$relationship->school, \$relationship->student_id\);/', $body,
                "{$writer}() takes the Student first, before any relationship or grant row");
        }
        // Guardian writers never run their own processing-authorization logic. The one sanctioned use (Guardian
        // unlink, 2026-10-07) is the Students read seam's existence check, after the Student lock.
        $code = str_replace(['StudentProcessingAuthorizationReadService', '$this->authorizations->isGuardianRelationshipReferenced('], '', $this->code($service));
        foreach (['StudentProcessingAuthorization', 'lockQualifyingAuthorizationId', 'student_processing_authorizations', '$this->authorizations->'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code, 'Guardian writers never run their own processing-authorization lookup');
        }
        $this->assertInOrder($this->method($service, 'unlink'), ['$this->lockOrder->holdStudent(', '->isGuardianRelationshipReferenced(', '$this->deleteRelationship($relationship)'],
            'unlink: Student first, then the retained-evidence check, then the delete');
        $this->assertStringContainsString('GuardianRelationshipInUseException::isViolation($e)', $this->method($service, 'unlink'), 'the RESTRICT key is the backstop, translated narrowly');
    }

    #[Test]
    public function the_student_mark_translation_stays_narrow_and_covers_every_marks_write(): void
    {
        $abort = $this->code('Domain/Examinations/Application/Marks/RetryableAbort.php');
        $this->assertStringContainsString("public const array SQLSTATES = ['40P01', '40001'];", $abort);
        $this->assertStringContainsString('catch (QueryException|PDOException $e)', $abort);
        $this->assertDoesNotMatchRegularExpression('/catch \((\\\\?Throwable|\\\\?Exception)\b/', $abort, 'never a blanket catch');

        $marks = 'Domain/Examinations/Application/Marks/';
        // The S5 observability follow-up: each boundary names its own closed operation (the metric label).
        foreach (['StudentMarkService.php' => ['record' => 'Record'], 'StudentMarkCorrectionService.php' => ['request' => 'CorrectionRequest', 'approve' => 'CorrectionApprove', 'reject' => 'CorrectionReject'], 'StudentMarkLockService.php' => ['lock' => 'PaperLock']] as $file => $methods) {
            foreach ($methods as $method => $operation) {
                $this->assertMatchesRegularExpression('/return RetryableAbort::translate\(StudentMarkOperation::'.$operation.', fn \(\): \w+ => \$this->context->withSchool\(/', $this->method($marks.$file, $method), "{$file}::{$method}() translates a retryable abort as {$operation}");
            }
        }
        foreach (['StudentMarkService.php', 'StudentMarkCorrectionService.php'] as $file) {
            $this->assertMatchesRegularExpression('/catch \(QueryException \$e\) \{\s*if \(RetryableAbort::is\(\$e\)\) \{\s*throw \$e;\s*\}/', $this->method($marks.$file, 'save'),
                "{$file}::save() lets a retryable abort reach the boundary instead of reporting it as a rejected value");
        }
    }
}
