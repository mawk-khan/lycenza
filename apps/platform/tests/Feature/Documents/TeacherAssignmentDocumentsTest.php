<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Infrastructure\Document;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesLmsOwnershipFixtures;
use Tests\Concerns\CreatesTeacherDeliveryFixtures;
use Tests\Concerns\CreatesTeacherLearningContentFixtures;
use Tests\Concerns\CreatesTeachingAssignmentFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * TCH.5D (ADR 0063 sections 34.9, 37): Assignment attachments follow
 * their PARENT row's owned rule through LmsParentResourceAuthorization --
 * reads where the teacher may read the row, writes only for the owner
 * teaching every audience Section -- never merely because the teacher
 * teaches the Offering. Learning Content attachments keep their TCH.5C
 * behaviour, and there is no Submission parent. Documents itself names no
 * LMS capability.
 */
class TeacherAssignmentDocumentsTest extends TestCase
{
    use CreatesLmsOwnershipFixtures, CreatesTeacherDeliveryFixtures, CreatesTeacherLearningContentFixtures, CreatesTeachingAssignmentFixtures, CreatesTenancyFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('test-device')->plainTextToken);
    }

    private function base(array $w): string
    {
        return "/api/v1/schools/{$w['school']->id}";
    }

    private function upload(array $w, User $user, string $owner, string $id): TestResponse
    {
        return $this->as($user)->post($this->base($w)."/{$owner}/{$id}/documents", [
            'file' => UploadedFile::fake()->create('handout.pdf', 10, 'application/pdf'),
        ]);
    }

    private function documents(array $w): int
    {
        return $this->withinSchool($w['school'], fn () => Document::query()->count());
    }

    #[Test]
    public function the_owner_attaches_reads_and_archives_on_their_own_row(): void
    {
        $w = $this->contentWorld();
        [$owner] = $this->contentTeacher($w);
        $row = $this->teacherAssignment($w, $owner);

        $documentId = $this->upload($w, $owner, 'assignments', $row->id)->assertCreated()->json('data.document_id');
        $this->as($owner)->getJson($this->base($w)."/assignments/{$row->id}/documents")->assertOk()->assertJsonCount(1, 'data');
        $this->as($owner)->getJson($this->base($w)."/documents/{$documentId}")->assertOk();
        $this->as($owner)->get($this->base($w)."/documents/{$documentId}/content")->assertOk();
        $this->as($owner)->postJson($this->base($w)."/documents/{$documentId}/archive")->assertNoContent();
    }

    #[Test]
    public function a_reader_of_a_published_row_reads_its_attachments_but_never_writes_them(): void
    {
        $w = $this->contentWorld();
        [$owner] = $this->contentTeacher($w);
        [$coTeacher] = $this->contentTeacher($w);
        $published = $this->teacherAssignment($w, $owner, status: 'published');
        $offeringWide = $this->adminAssignment($w);
        $documentId = $this->upload($w, $owner, 'assignments', $published->id)->json('data.document_id');
        $adminDocumentId = $this->upload($w, $w['admin'], 'assignments', $offeringWide->id)->assertCreated()->json('data.document_id');

        foreach ([[$published->id, $documentId], [$offeringWide->id, $adminDocumentId]] as [$rowId, $docId]) {
            $this->as($coTeacher)->getJson($this->base($w)."/assignments/{$rowId}/documents")->assertOk()->assertJsonCount(1, 'data');
            $this->as($coTeacher)->getJson($this->base($w)."/documents/{$docId}")->assertOk();
            $this->as($coTeacher)->get($this->base($w)."/documents/{$docId}/content")->assertOk();
            $this->upload($w, $coTeacher, 'assignments', $rowId)->assertForbidden();
            $this->as($coTeacher)->postJson($this->base($w)."/documents/{$docId}/archive")->assertForbidden();
        }
        $this->assertSame(2, $this->documents($w));
    }

    #[Test]
    public function attachments_of_a_row_the_teacher_may_not_read_are_not_found(): void
    {
        $w = $this->contentWorld();
        [$owner] = $this->contentTeacher($w);
        [$coTeacher] = $this->contentTeacher($w);
        [$elsewhere] = $this->contentTeacher($w, ['sectionB']);
        $draft = $this->teacherAssignment($w, $owner);
        $adminDraft = $this->adminAssignment($w, 'draft');
        $documentId = $this->upload($w, $owner, 'assignments', $draft->id)->json('data.document_id');
        $adminDocumentId = $this->upload($w, $w['admin'], 'assignments', $adminDraft->id)->json('data.document_id');

        foreach ([$coTeacher, $elsewhere] as $teacher) {
            foreach ([[$draft->id, $documentId], [$adminDraft->id, $adminDocumentId]] as [$rowId, $docId]) {
                $this->as($teacher)->getJson($this->base($w)."/assignments/{$rowId}/documents")->assertNotFound();
                $this->as($teacher)->getJson($this->base($w)."/documents/{$docId}")->assertNotFound();
                $this->as($teacher)->get($this->base($w)."/documents/{$docId}/content")->assertNotFound();
                $this->as($teacher)->postJson($this->base($w)."/documents/{$docId}/archive")->assertNotFound();
                $this->upload($w, $teacher, 'assignments', $rowId)->assertNotFound();
            }
        }
    }

    #[Test]
    public function the_owner_loses_attachment_writes_with_the_teaching_relationship(): void
    {
        $w = $this->contentWorld();
        [$owner, , , [$a, $b]] = $this->contentTeacher($w, ['sectionA', 'sectionB']);
        $row = $this->teacherAssignment($w, $owner, ['sectionA', 'sectionB'], 'published');
        $documentId = $this->upload($w, $owner, 'assignments', $row->id)->json('data.document_id');

        $this->endTeaching($w, $b);

        $this->upload($w, $owner, 'assignments', $row->id)->assertStatus(422)->assertJsonPath('error.code', 'ASSIGNMENT_OUTSIDE_TEACHING_ASSIGNMENT');
        $this->as($owner)->postJson($this->base($w)."/documents/{$documentId}/archive")->assertStatus(422);
        $this->as($owner)->getJson($this->base($w)."/documents/{$documentId}")->assertOk();
        $this->assertSame(1, $this->documents($w), 'No new bytes or row for a refused write.');
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    #[Test]
    public function learning_content_and_admin_attachments_are_unchanged(): void
    {
        $w = $this->contentWorld();
        [$teacher] = $this->contentTeacher($w);
        [$coTeacher] = $this->contentTeacher($w);
        $content = $this->teacherContent($w, $teacher, status: 'published');
        $assignment = $this->teacherAssignment($w, $teacher, status: 'published');

        // Both parent kinds work for their owner, side by side.
        $contentDoc = $this->upload($w, $teacher, 'learning-content', $content->id)->assertCreated()->json('data.document_id');
        $assignmentDoc = $this->upload($w, $teacher, 'assignments', $assignment->id)->assertCreated()->json('data.document_id');
        foreach ([$contentDoc, $assignmentDoc] as $docId) {
            $this->as($coTeacher)->getJson($this->base($w)."/documents/{$docId}")->assertOk();
            $this->as($coTeacher)->postJson($this->base($w)."/documents/{$docId}/archive")->assertForbidden();
            $this->as($w['admin'])->getJson($this->base($w)."/documents/{$docId}")->assertOk();
        }
        $this->as($w['admin'])->postJson($this->base($w)."/documents/{$assignmentDoc}/archive")->assertNoContent();
    }

    #[Test]
    public function a_role_with_only_the_assignment_capability_reaches_no_learning_content_attachment(): void
    {
        $w = $this->contentWorld();
        $role = Role::query()->create(['key' => 'test.assignments_only.'.Str::uuid(), 'name' => 'Assignments only', 'scope' => 'school', 'is_system' => false]);
        $role->capabilities()->sync(['lms.assignments.teacher']);
        [$teacher] = $this->contentTeacher($w, roleKey: $role->key);
        $shared = $this->adminContent($w);
        $documentId = $this->upload($w, $w['admin'], 'learning-content', $shared->id)->json('data.document_id');
        $mine = $this->teacherAssignment($w, $teacher);

        $this->upload($w, $teacher, 'assignments', $mine->id)->assertCreated();
        $this->as($teacher)->getJson($this->base($w)."/learning-content/{$shared->id}/documents")->assertForbidden();
        $this->as($teacher)->getJson($this->base($w)."/documents/{$documentId}")->assertForbidden();
    }
}
