<?php

namespace Tests\Feature\App;

use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Library\Application\LibraryFinePolicyService;
use App\Domain\Library\Application\LibraryLoanService;
use App\Domain\Library\Infrastructure\LibraryFine;
use App\Domain\Library\Infrastructure\LibraryFinePolicy;
use App\Domain\Library\Infrastructure\LibraryFineVoid;
use App\Domain\Library\Infrastructure\LibraryLoan;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Fees\Concerns\CreatesFeeConcessionFixtures;
use Tests\TestCase;

/**
 * SWC.3 (ADR 0073 sections 19.4-19.5): Library fines on the web over the
 * OPF.4 services -- the fine policy (current, history, publish with the X5
 * expected-version guard) and one loan's fine (view, void). Amounts are the
 * stored NUMERIC strings; there is no School-wide fine list (X2).
 */
class LibraryFineUiTest extends TestCase
{
    use CreatesFeeConcessionFixtures;

    /** @return array<string, mixed> */
    private function world(): array
    {
        $w = $this->concessionWorld();
        $w['fineHead'] = $this->makeFeeHead($w, ['code' => 'LIBFINE', 'name' => 'Library fines']);
        $w['librarian'] = $this->createUserWithCapabilities($w['school'], ['library.circulation.view', 'library.circulation.manage', 'library.catalogue.view', 'library.fines.view']);
        $w['officer'] = $this->createUserWithCapabilities($w['school'], ['library.circulation.view', 'library.fines.view', 'library.fines.manage', 'library.fines.void']);
        $w['title'] = $this->createLibraryTitle($w['school']);

        return $w;
    }

    private function as(array $w, User $user): static
    {
        $this->actingAs($user)->post("/app/schools/{$w['school']->id}/activate");

        return $this->actingAs($user);
    }

    private function policy(array $w): array
    {
        return ['status' => 'active', 'fee_head_id' => $w['fineHead']->id, 'daily_rate' => '5.00', 'grace_days' => 2, 'max_amount' => '50.00'];
    }

    private function overdueReturnedLoan(array $w): LibraryLoan
    {
        $this->travelTo(Carbon::parse('2026-07-01 10:00:00', 'UTC'));
        $copy = $this->createLibraryCopy($w['title']);
        $loan = $this->inSchool($w['school'], fn () => app(LibraryLoanService::class)->checkout($copy, $w['student'], Carbon::parse('2026-07-08 10:00:00', 'UTC'), $w['librarian']));
        $this->travelTo(Carbon::parse('2026-07-20 10:00:00', 'UTC'));

        return $loan;
    }

    private function versions(array $w): int
    {
        return $this->inSchool($w['school'], fn () => LibraryFinePolicy::query()->count());
    }

    #[Test]
    public function the_policy_page_shows_current_and_history_and_publish_is_guarded_against_repeat_and_stale_forms(): void
    {
        $w = $this->world();

        $this->as($w, $w['officer'])->get('/app/library/fine-policy')->assertOk()->assertInertia(fn ($page) => $page
            ->component('App/Library/FinePolicy')->where('current', null)->has('versions', 0)->where('canManage', true)
            ->where('options', fn ($o) => collect($o)->pluck('id')->contains($w['fineHead']->id)));

        $this->as($w, $w['officer'])->post('/app/library/fine-policy', [...$this->policy($w), 'expected_version' => 0])
            ->assertRedirect(route('app.library.fine-policy'))->assertSessionHasNoErrors();
        // X5: the same form submitted again (still based on "no version") is refused -- no duplicate version.
        $this->as($w, $w['officer'])->post('/app/library/fine-policy', [...$this->policy($w), 'expected_version' => 0])
            ->assertSessionHasErrors(['policy' => 'The fine policy changed since this form was opened. Review the current policy and publish again if needed.']);
        $this->assertSame(1, $this->versions($w));

        // Someone else publishes version 2 meanwhile (the API, without a guard): a form based on version 1 is stale.
        app(LibraryFinePolicyService::class)->publish($w['school'], ['status' => 'disabled'], $w['officer']);
        $this->as($w, $w['officer'])->post('/app/library/fine-policy', [...$this->policy($w), 'daily_rate' => '9.00', 'expected_version' => 1])->assertSessionHasErrors('policy');
        $this->assertSame(2, $this->versions($w));
        $this->as($w, $w['officer'])->post('/app/library/fine-policy', [...$this->policy($w), 'daily_rate' => '9.00', 'expected_version' => 2])->assertSessionHasNoErrors();

        $this->as($w, $w['officer'])->get('/app/library/fine-policy')->assertInertia(fn ($page) => $page
            ->where('current.version', 3)->where('current.dailyRate', '9.00')->where('current.maxAmount', '50.00')->where('current.currency', 'INR')
            ->where('versions', fn ($v) => collect($v)->pluck('version')->all() === [3, 2, 1])
            ->where('versions.1.status', 'disabled'));
        $this->assertSame(3, $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', 'library.fine_policy.published')->count()));

        // The service's validation is a form error, and writes nothing.
        $this->as($w, $w['officer'])->post('/app/library/fine-policy', [...$this->policy($w), 'daily_rate' => '1.234', 'expected_version' => 3])->assertSessionHasErrors('policy');
        $foreign = $this->makeFeeHead($this->concessionWorld(), ['code' => 'FOREIGN']);
        $this->as($w, $w['officer'])->post('/app/library/fine-policy', [...$this->policy($w), 'fee_head_id' => $foreign->id, 'expected_version' => 3])->assertSessionHasErrors('policy');
        $this->as($w, $w['officer'])->post('/app/library/fine-policy', $this->policy($w))->assertSessionHasErrors('expected_version');
        $this->assertSame(3, $this->versions($w));
    }

    #[Test]
    public function a_check_in_that_assesses_a_fine_lands_on_the_loan_which_shows_the_stored_amount(): void
    {
        $w = $this->world();
        app(LibraryFinePolicyService::class)->publish($w['school'], $this->policy($w), $w['officer']);
        $loan = $this->overdueReturnedLoan($w);

        $this->as($w, $w['librarian'])->post("/app/library/circulation/{$loan->id}/check-in")->assertRedirect(route('app.library.circulation.show', $loan->id));
        $fine = $this->inSchool($w['school'], fn () => LibraryFine::query()->where('library_loan_id', $loan->id)->firstOrFail());
        $charge = $this->inSchool($w['school'], fn () => Charge::query()->findOrFail($fine->charge_id));

        $this->as($w, $w['librarian'])->get("/app/library/circulation/{$loan->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->component('App/Library/Circulation/Show')
            ->where('fine.amount', $fine->amount)        // the stored NUMERIC string, exactly
            ->where('fine.amount', (string) $charge->amount)
            ->where('fine.currency', 'INR')
            ->where('fine.policyVersion', 1)
            ->where('fine.status', 'assessed')
            ->where('canVoid', false));
        $this->assertSame('50.00', $fine->amount, '12 days late, 2 grace, 5.00/day = 50.00 (the cap)');

        // A loan without a fine still checks in back to the list.
        $copy = $this->createLibraryCopy($w['title']);
        $onTime = $this->inSchool($w['school'], fn () => app(LibraryLoanService::class)->checkout($copy, $w['student'], Carbon::parse('2026-08-01 10:00:00', 'UTC'), $w['librarian']));
        $this->as($w, $w['librarian'])->post("/app/library/circulation/{$onTime->id}/check-in")->assertRedirect('/app/library/circulation');
        $this->as($w, $w['librarian'])->get("/app/library/circulation/{$onTime->id}")->assertInertia(fn ($page) => $page->where('fine', null));
    }

    #[Test]
    public function voiding_is_a_reasoned_one_way_transition_and_a_repeat_is_a_form_error(): void
    {
        $w = $this->world();
        app(LibraryFinePolicyService::class)->publish($w['school'], $this->policy($w), $w['officer']);
        $loan = $this->overdueReturnedLoan($w);
        $this->as($w, $w['librarian'])->post("/app/library/circulation/{$loan->id}/check-in");
        $fine = $this->inSchool($w['school'], fn () => LibraryFine::query()->where('library_loan_id', $loan->id)->firstOrFail());

        $this->as($w, $w['officer'])->post("/app/library/fines/{$fine->id}/void", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->as($w, $w['librarian'])->post("/app/library/fines/{$fine->id}/void", ['reason' => 'Lost in post'])->assertForbidden();
        $this->as($w, $w['officer'])->post("/app/library/fines/{$fine->id}/void", ['reason' => 'Book returned to the drop box on time'])
            ->assertRedirect(route('app.library.circulation.show', $loan->id))->assertSessionHasNoErrors();
        $this->as($w, $w['officer'])->post("/app/library/fines/{$fine->id}/void", ['reason' => 'Again'])
            ->assertSessionHasErrors(['reason' => 'This Library fine has already been voided.']);

        $this->assertSame(1, $this->inSchool($w['school'], fn () => LibraryFineVoid::query()->where('library_fine_id', $fine->id)->count()));
        $this->assertNotNull($this->inSchool($w['school'], fn () => Charge::query()->findOrFail($fine->charge_id)->cancelled_at), 'the fine\'s charge is cancelled');
        $this->assertNotNull($this->inSchool($w['school'], fn () => LibraryFine::query()->find($fine->id)), 'void is a state, never a delete');
        $this->as($w, $w['officer'])->get("/app/library/circulation/{$loan->id}")->assertInertia(fn ($page) => $page
            ->where('fine.status', 'voided')->where('fine.voidReason', 'Book returned to the drop box on time')->where('canVoid', true));
    }

    #[Test]
    public function another_schools_loan_or_fine_is_not_found_and_no_school_wide_fine_list_exists(): void
    {
        $w = $this->world();
        app(LibraryFinePolicyService::class)->publish($w['school'], $this->policy($w), $w['officer']);
        $loan = $this->overdueReturnedLoan($w);
        $this->as($w, $w['librarian'])->post("/app/library/circulation/{$loan->id}/check-in");
        $fine = $this->inSchool($w['school'], fn () => LibraryFine::query()->where('library_loan_id', $loan->id)->firstOrFail());

        $other = $this->concessionWorld();
        $foreignOfficer = $this->createUserWithCapabilities($other['school'], ['library.circulation.view', 'library.fines.view', 'library.fines.void']);
        $this->actingAs($foreignOfficer)->post("/app/schools/{$other['school']->id}/activate");
        $this->assertSame(404, $this->actingAs($foreignOfficer)->get("/app/library/circulation/{$loan->id}")->getStatusCode());
        $this->assertSame(404, $this->actingAs($foreignOfficer)->post("/app/library/fines/{$fine->id}/void", ['reason' => 'x'])->getStatusCode());
        $this->assertSame(0, $this->inSchool($w['school'], fn () => LibraryFineVoid::query()->count()));

        $nobody = $this->createUserWithCapabilities($w['school'], []);
        $this->as($w, $nobody)->get('/app/library/fine-policy')->assertForbidden();
        $this->as($w, $nobody)->get("/app/library/circulation/{$loan->id}")->assertForbidden();
        // A circulation holder without fine capability sees the loan but not its fine.
        $circulationOnly = $this->createUserWithCapabilities($w['school'], ['library.circulation.view']);
        $this->as($w, $circulationOnly)->get("/app/library/circulation/{$loan->id}")->assertOk()->assertInertia(fn ($page) => $page->where('fine', null)->where('canViewFines', false));

        // X2: no route lists fines School-wide.
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (in_array('GET', $route->methods(), true) && str_contains($route->uri(), 'fine')) {
                $this->assertContains($route->uri(), [
                    'app/library/fine-policy',
                    'api/v1/schools/{school}/library-fine-policy',
                    'api/v1/schools/{school}/library-fine-policy/versions',
                    'api/v1/schools/{school}/library-loans/{libraryLoan}/fine',
                ], "unexpected fine read route {$route->uri()}");
            }
        }
    }
}
