<?php

namespace Tests\Feature\TeachingAssignments;

use App\Domain\HR\Application\EmploymentEndParticipant;
use App\Domain\TeachingAssignments\Application\EmploymentEndedTeachingOwnership;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * S7 (ADR 0063 §47): structural pins for "ending employment ends teaching
 * ownership". The behaviour is proven by EmploymentEndTeachingOwnershipTest
 * (and its real-process races); these keep its shape:
 * - HR calls a port it owns, inside end()'s transaction, after the
 *   EmploymentRecord lock -- and never references Teaching Assignments;
 * - the one registered participant ends BOTH ownership facts, each through
 *   its table's one writer; nobody else calls that path;
 * - the path never deletes, never rewrites a start, never takes the create
 *   key (the lock order stays EmploymentRecord -> assignment rows);
 * - ActingEmployee stays a separate use-time predicate.
 */
class EmploymentEndArchitectureGuardTest extends TestCase
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
    public function hr_ends_through_its_own_port_inside_the_end_transaction(): void
    {
        $end = $this->method('Domain/HR/Application/EmploymentService.php', 'end');
        $this->assertInOrder($end, [
            'DB::transaction(',
            'EmploymentRecord::query()->where(\'id\', $employment->id)->lockForUpdate()',
            '$locked->update([\'ends_on\' => $endsOn',
            'foreach ($this->endParticipants as $participant)',
            '$participant->employmentEnded($school, $locked->employee_id, $endsOn, $actor)',
            'event(new EmploymentEnded(',
        ], 'end(): lock, end, participants, event -- one transaction');
        $this->assertStringContainsString('#[Tag(EmploymentEndParticipant::TAG)] private readonly iterable $endParticipants', $this->code('Domain/HR/Application/EmploymentService.php'));

        foreach (['Domain/HR/Application/EmploymentEndParticipant.php', 'Domain/HR/Application/EmploymentService.php'] as $file) {
            $this->assertStringNotContainsString('TeachingAssignments', $this->code($file), "{$file} never references the module that depends on HR");
        }
    }

    #[Test]
    public function the_one_participant_ends_both_ownership_facts_through_their_writers(): void
    {
        $tagged = array_map(fn (object $p) => $p::class, iterator_to_array(app()->tagged(EmploymentEndParticipant::TAG), false));
        $this->assertSame([EmploymentEndedTeachingOwnership::class], $tagged, 'a new employment-end participant needs its own review');

        $participant = $this->method('Domain/TeachingAssignments/Application/EmploymentEndedTeachingOwnership.php', 'employmentEnded');
        $this->assertInOrder($participant, ['$this->required->endForEmployment(', '$this->elective->endForEmployment('], 'required, then elective -- one lock order');

        $callers = [];
        exec('grep -rln -F --include=*.php '.escapeshellarg('endForEmployment(').' '.escapeshellarg(app_path()), $callers);
        $callers = array_map(fn (string $f) => substr($f, strlen(app_path()) + 1), $callers);
        sort($callers);
        $this->assertSame([
            'Domain/TeachingAssignments/Application/ElectiveTeachingAssignmentService.php',
            'Domain/TeachingAssignments/Application/EmploymentEndedTeachingOwnership.php',
            'Domain/TeachingAssignments/Application/TeachingAssignmentService.php',
        ], $callers, 'only the participant calls the employment-end path');
    }

    #[Test]
    public function the_employment_end_path_shortens_only_and_takes_no_create_key(): void
    {
        foreach (['TeachingAssignmentService', 'ElectiveTeachingAssignmentService'] as $service) {
            $body = $this->method("Domain/TeachingAssignments/Application/{$service}.php", 'endForEmployment');
            $this->assertStringContainsString('authorizeCapabilityFor($actor, TeachingAssignmentService::CAPABILITY_EMPLOYMENT_END, $school)', $body, "{$service}: the HR capability, re-checked");
            $this->assertInOrder($body, ['->orderBy(\'id\')', '->lockForUpdate()', '->forceFill([', '->save()', '$this->audit->school('], "{$service}: rows FOR UPDATE in id order, then each ending audited");
            $this->assertStringContainsString('END_REASON_EMPLOYMENT_ENDED', $body);
            foreach (['delete(', '\'starts_on\' =>', 'lockKey(', 'pg_advisory', 'ActingEmployee', 'DB::table('] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $body, "{$service}::endForEmployment() never uses {$forbidden}");
            }

            $create = $this->method("Domain/TeachingAssignments/Application/{$service}.php", 'create');
            $this->assertInOrder($create, ['$this->coverage->hold(', '$this->coverage->coveringEndsOn(', 'throw new AssignmentBeyondEmploymentException', '->save()'],
                "{$service}::create(): ownership never outlives the covering employment");
        }
    }

    #[Test]
    public function acting_employee_stays_a_separate_use_time_predicate(): void
    {
        $resolver = $this->code('Domain/HR/Application/ActingEmployeeResolver.php');
        foreach (['EmploymentEndParticipant', 'TeachingAssignment', 'endParticipants'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $resolver);
        }
        $this->assertStringNotContainsString('ActingEmployee', $this->code('Domain/TeachingAssignments/Application/EmploymentEndedTeachingOwnership.php'));
    }
}
