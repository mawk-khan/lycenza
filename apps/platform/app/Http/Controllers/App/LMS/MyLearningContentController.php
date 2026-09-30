<?php

namespace App\Http\Controllers\App\LMS;

use App\Domain\HR\Application\Exceptions\ActingEmployeeUnavailableException;
use App\Domain\LMS\Application\Exceptions\LmsException;
use App\Domain\LMS\Application\LearningContentService;
use App\Domain\LMS\Application\TeacherLearningContentAccess;
use App\Domain\LMS\Application\TeacherLearningContentReadService;
use App\Domain\LMS\Infrastructure\LearningContent;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * TCH.5C (ADR 0063 section 36) -- "My Learning Content": the session-
 * authenticated OWNED (Tier 2) Learning Content page. It shows only what the
 * teacher may read (filtered in SQL) and the classes they teach today, and
 * writes only through LearningContentService with the teacher guard.
 *
 * A capability holder who is not an eligible Employee sees an empty page and
 * every write is refused (403). A row they may not touch is 404, or 403 when
 * they may read it but do not own it. No Student data, no Assignment, no
 * attachment UI (attachments stay on the shared Documents API).
 */
class MyLearningContentController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, TenantContext $context, TeacherLearningContentAccess $access, TeacherLearningContentReadService $reads): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(TeacherLearningContentAccess::CAPABILITY, $school);

        $validated = $request->validate(['subject_offering_id' => ['sometimes', 'uuid']]);

        try {
            $scope = $access->scope($request->user(), $school);
        } catch (ActingEmployeeUnavailableException) {
            $scope = null;
        }

        $contexts = $scope === null ? [] : $reads->contexts($school, $scope);
        $selected = $validated['subject_offering_id'] ?? null;
        if ($selected !== null && ! in_array($selected, array_column($contexts, 'subjectOfferingId'), true)) {
            $selected = null;
        }

        return Inertia::render('App/LMS/Mine', [
            'contexts' => $contexts,
            'filters' => ['subjectOfferingId' => $selected ?? ''],
            'content' => $scope === null ? [] : $reads->list($school, $scope, $selected, 200)->items(),
            'canAuthor' => $scope !== null,
        ]);
    }

    public function store(Request $request, TenantContext $context, TeacherLearningContentAccess $access, LearningContentService $service): RedirectResponse
    {
        $school = $context->requireSchool();

        $validated = $request->validate([
            'subject_offering_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'sequence' => ['sometimes', 'integer', 'min:0'],
            'audience_section_ids' => ['required', 'array', 'min:1', 'max:50'],
            'audience_section_ids.*' => ['required', 'uuid', 'distinct'],
        ]);

        $content = $this->guarded(fn () => $service->createOwned($school, $validated['subject_offering_id'], [
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'sequence' => $validated['sequence'] ?? 0,
        ], array_values($validated['audience_section_ids']), $request->user(), $access->guard($request->user())), 'audience_section_ids');

        return $this->back($content, 'Learning content created.');
    }

    public function update(Request $request, TenantContext $context, TeacherLearningContentAccess $access, LearningContentService $service, string $learningContent): RedirectResponse
    {
        $school = $context->requireSchool();
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'sequence' => ['sometimes', 'integer', 'min:0'],
        ]);

        $updated = $this->guarded(fn () => $service->update($school, $this->find($learningContent), $validated, $request->user(), $access->guard($request->user())), 'title');

        return $this->back($updated, 'Learning content updated.');
    }

    public function publish(Request $request, TenantContext $context, TeacherLearningContentAccess $access, LearningContentService $service, string $learningContent): RedirectResponse
    {
        $school = $context->requireSchool();
        $updated = $this->guarded(fn () => $service->publish($school, $this->find($learningContent), $request->user(), $access->guard($request->user())), 'status');

        return $this->back($updated, 'Learning content published.');
    }

    public function archive(Request $request, TenantContext $context, TeacherLearningContentAccess $access, LearningContentService $service, string $learningContent): RedirectResponse
    {
        $school = $context->requireSchool();
        $updated = $this->guarded(fn () => $service->archive($school, $this->find($learningContent), $request->user(), $access->guard($request->user())), 'status');

        return $this->back($updated, 'Learning content archived.');
    }

    /**
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    private function guarded(callable $write, string $field): mixed
    {
        try {
            return $write();
        } catch (ActingEmployeeUnavailableException) {
            abort(403);
        } catch (LmsException $e) {
            if ($e->getStatusCode() === 403) {
                abort(403, $e->getMessage());
            }

            throw ValidationException::withMessages([$field => $e->getMessage()]);
        }
    }

    private function find(string $learningContent): LearningContent
    {
        abort_unless(Str::isUuid($learningContent), 404);

        return LearningContent::query()->findOrFail($learningContent);
    }

    private function back(LearningContent $content, string $status): RedirectResponse
    {
        return redirect()
            ->route('app.my-learning-content.index', ['subject_offering_id' => $content->subject_offering_id])
            ->with('status', $status);
    }
}
