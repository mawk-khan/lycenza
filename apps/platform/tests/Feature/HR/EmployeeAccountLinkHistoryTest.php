<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\AccountLinks\EmployeeAccountLinkHistory;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAccountLink;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.R1-C7 (OD-HR-25): `employees.user_id` stays the one current link; its history is
 * written by a trigger, so no path changes the link without leaving a record.
 * Corrections are EmployeeAccountLinkCorrectionTest.
 */
class EmployeeAccountLinkHistoryTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private User $hr;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->hr = $this->fullHrActor($this->school);
        $this->employee = $this->createEmployee($this->school, ['user_id' => null]);
    }

    private function member(?School $school = null): User
    {
        $user = $this->createUser();
        $this->createMembership($user, $school ?? $this->school);

        return $user;
    }

    private function in(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }

    private function linkTo(User $user): void
    {
        $this->in(fn () => app(EmployeeService::class)->linkUser($this->employee->fresh(), $user->id, $this->hr));
    }

    private function unlink(): void
    {
        $this->in(fn () => app(EmployeeService::class)->unlinkUser($this->employee->fresh(), $this->hr));
    }

    /** @return list<EmployeeAccountLink> newest first */
    private function links(?Employee $employee = null): array
    {
        return $this->in(fn () => EmployeeAccountLink::query()->where('employee_id', ($employee ?? $this->employee)->id)->orderByDesc('linked_at')->get()->all());
    }

    #[Test]
    public function a_link_is_recorded_with_who_made_it(): void
    {
        $user = $this->member();
        $this->linkTo($user);

        $links = $this->links();
        $this->assertCount(1, $links);
        $this->assertSame($user->id, $links[0]->user_id);
        $this->assertSame($this->hr->id, $links[0]->linked_by_user_id);
        $this->assertSame('recorded', $links[0]->origin);
        $this->assertNull($links[0]->ended_at);
        $this->assertSame($user->id, $this->in(fn () => $this->employee->fresh()->user_id), 'the current link is unchanged in shape');
    }

    #[Test]
    public function an_authorized_unlink_works_and_ends_the_interval_with_who_ended_it(): void
    {
        $user = $this->member();
        $this->linkTo($user);
        $this->unlink();

        $this->assertNull($this->in(fn () => $this->employee->fresh()->user_id), 'an unlink stays possible');
        $link = $this->links()[0];
        $this->assertNotNull($link->ended_at);
        $this->assertSame($this->hr->id, $link->ended_by_user_id);
        $this->assertNull($link->voided_at, 'an unlink says nothing about whether the link was true');
    }

    #[Test]
    public function a_failed_link_leaves_no_false_history(): void
    {
        $taken = $this->member();
        $other = $this->createEmployee($this->school, ['user_id' => null]);
        $this->linkTo($taken);

        try {
            $this->in(fn () => app(EmployeeService::class)->linkUser($other->fresh(), $taken->id, $this->hr));
            $this->fail('one account links one Employee');
        } catch (\Throwable) {
        }

        $this->assertCount(0, $this->links($other));
    }

    #[Test]
    public function a_relink_and_an_account_replacement_keep_every_interval_in_order(): void
    {
        [$first, $second, $third] = [$this->member(), $this->member(), $this->member()];
        $this->linkTo($first);
        $this->unlink();
        $this->linkTo($second);
        $this->unlink();
        $this->linkTo($third);

        $links = $this->links();
        $this->assertSame([$third->id, $second->id, $first->id], array_map(fn ($l) => $l->user_id, $links));
        $this->assertNull($links[0]->ended_at, 'exactly one is open');
        $this->assertLessThanOrEqual($links[1]->linked_at, $links[2]->ended_at, 'intervals never overlap');
        $this->assertLessThanOrEqual($links[0]->linked_at, $links[1]->ended_at);
    }

    #[Test]
    public function the_open_row_is_the_current_link_and_the_history_answers_as_of_a_time(): void
    {
        [$first, $second] = [$this->member(), $this->member()];
        $this->linkTo($first);
        $during = $this->links()[0]->linked_at->copy()->addMicroseconds(1)->format('Y-m-d H:i:s.u');
        usleep(2000);
        $this->unlink();
        $this->linkTo($second);

        $asOf = fn (string $at) => $this->in(fn () => DB::table('employee_account_links')->where('employee_id', $this->employee->id)->whereNull('voided_at')
            ->where('linked_at', '<=', $at)->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $at))->value('user_id'));
        $this->assertSame($first->id, $asOf($during), 'who it was then, not who it is now');
        $this->assertSame($second->id, $asOf(now()->addMinute()->format('Y-m-d H:i:s.u')));

        $open = $this->in(fn () => DB::table('employee_account_links')->where('employee_id', $this->employee->id)->whereNull('ended_at')->pluck('user_id')->all());
        $this->assertSame([$this->in(fn () => $this->employee->fresh()->user_id)], $open);
    }

    #[Test]
    public function an_employee_created_with_an_account_is_recorded_too(): void
    {
        $user = $this->member();
        $created = $this->in(fn () => app(EmployeeService::class)->create($this->school, ['full_name' => 'Linked From Birth', 'user_id' => $user->id], $this->hr));

        $links = $this->links($created);
        $this->assertCount(1, $links);
        $this->assertSame($this->hr->id, $links[0]->linked_by_user_id);
    }

    #[Test]
    public function an_employee_never_linked_has_no_history_which_stays_unknown(): void
    {
        $this->assertSame([], $this->in(fn () => app(EmployeeAccountLinkHistory::class)->forEmployee($this->employee->fresh(), $this->hr)));
    }

    #[Test]
    public function the_history_reader_keeps_what_is_known_apart_and_shows_only_names_and_dates(): void
    {
        $user = $this->member();
        $this->linkTo($user);
        $this->unlink();
        $this->linkTo($second = $this->member());

        $history = $this->in(fn () => app(EmployeeAccountLinkHistory::class)->forEmployee($this->employee->fresh(), $this->hr));

        $this->assertSame(['current', 'ended'], array_column($history, 'state'));
        $this->assertSame(['id', 'account', 'state', 'evidence', 'linkedAt', 'endedAt', 'correctedAt', 'correctionReason', 'mayRequestCorrection', 'correctionRequest'], array_keys($history[0]));
        $this->assertStringNotContainsString($second->email, json_encode($history));
    }

    #[Test]
    public function link_and_unlink_audits_name_the_historical_row(): void
    {
        $user = $this->member();
        $this->linkTo($user);
        $this->unlink();

        $linkId = $this->links()[0]->id;
        foreach (['employee.user_linked', 'employee.user_unlinked'] as $type) {
            $event = $this->in(fn () => SchoolAuditEvent::query()->where('event_type', $type)->where('subject_id', $this->employee->id)->firstOrFail());
            $this->assertSame($linkId, $event->metadata['linkId'], $type);
        }
    }

    #[Test]
    public function a_user_being_disabled_changes_neither_the_link_nor_its_history(): void
    {
        $user = $this->member();
        $this->linkTo($user);
        DB::connection('pgsql_admin')->table('users')->where('id', $user->id)->update(['is_disabled' => true]);

        $this->assertSame($user->id, $this->in(fn () => $this->employee->fresh()->user_id));
        $this->assertNull($this->links()[0]->ended_at, 'no access comes from this, and no history goes');
    }
}
