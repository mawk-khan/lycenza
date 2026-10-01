<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Infrastructure\Document;
use App\Domain\LMS\Application\AssignmentService;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
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
 * TCH.5C (ADR 0063 sections 34.9, 36): Learning Content attachments follow
 * their PARENT row's owned rule through LmsParentResourceAuthorization --
 * reads where the teacher may read the row, writes only for the owner
 * teaching every audience Section -- never merely because the teacher
 * teaches the Offering. Each parent kind needs its own owned capability
 * (Assignments: TeacherAssignmentDocumentsTest). Documents itself names no
 * LMS capability.
 */
class TeacherLearningContentDocumentsTest extends TestCase
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
        $row = $this->teacherContent($w, $owner);

        $documentId = $this->upload($w, $owner, 'learning-content', $row->id)->assertCreated()->json('data.document_id');
        $this->as($owner)->getJson($this->base($w)."/learning-content/{$row->id}/documents")->assertOk()->assertJsonCount(1, 'data');
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
        $published = $this->teacherContent($w, $owner, status: 'published');
        $offeringWide = $this->adminContent($w);
        $documentId = $this->upload($w, $owner, 'learning-content', $published->id)->json('data.document_id');
        $adminDocumentId = $this->upload($w, $w['admin'], 'learning-content', $offeringWide->id)->assertCreated()->json('data.document_id');

        foreach ([[$published->id, $documentId], [$offeringWide->id, $adminDocumentId]] as [$rowId, $docId]) {
            $this->as($coTeacher)->getJson($this->base($w)."/learning-content/{$rowId}/documents")->assertOk()->assertJsonCount(1, 'data');
            $this->as($coTeacher)->getJson($this->base($w)."/documents/{$docId}")->assertOk();
            $this->as($coTeacher)->get($this->base($w)."/documents/{$docId}/content")->assertOk();
            $this->upload($w, $coTeacher, 'learning-content', $rowId)->assertForbidden();
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
        $draft = $this->teacherContent($w, $owner);
        $adminDraft = $this->adminContent($w, 'draft');
        $documentId = $this->upload($w, $owner, 'learning-content', $draft->id)->json('data.document_id');
        $adminDocumentId = $this->upload($w, $w['admin'], 'learning-content', $adminDraft->id)->json('data.document_id');

        // TCH.6 (ADR 0063 section 18): not merely the same status -- the
        // same body as an unknown id (the requested id normalized out),
        // never naming the hidden parent row.
        $unknownRow = (string) Str::uuid7();
        $unknownDoc = (string) Str::uuid7();
        $body = fn (TestResponse $r, string $id) => str_replace($id, ':id', (string) json_encode(Arr::except($r->assertNotFound()->json('error'), ['requestId'])));

        foreach ([$coTeacher, $elsewhere] as $teacher) {
            $unknown = [
                'list' => $body($this->as($teacher)->getJson($this->base($w)."/learning-content/{$unknownRow}/documents"), $unknownRow),
                'show' => $body($this->as($teacher)->getJson($this->base($w)."/documents/{$unknownDoc}"), $unknownDoc),
                'content' => $body($this->as($teacher)->get($this->base($w)."/documents/{$unknownDoc}/content"), $unknownDoc),
                'archive' => $body($this->as($teacher)->postJson($this->base($w)."/documents/{$unknownDoc}/archive"), $unknownDoc),
                'upload' => $body($this->upload($w, $teacher, 'learning-content', $unknownRow), $unknownRow),
            ];

            foreach ([[$draft->id, $documentId], [$adminDraft->id, $adminDocumentId]] as [$rowId, $docId]) {
                $hidden = [
                    'list' => $body($this->as($teacher)->getJson($this->base($w)."/learning-content/{$rowId}/documents"), $rowId),
                    'show' => $body($this->as($teacher)->getJson($this->base($w)."/documents/{$docId}"), $docId),
                    'content' => $body($this->as($teacher)->get($this->base($w)."/documents/{$docId}/content"), $docId),
                    'archive' => $body($this->as($teacher)->postJson($this->base($w)."/documents/{$docId}/archive"), $docId),
                    'upload' => $body($this->upload($w, $teacher, 'learning-content', $rowId), $rowId),
                ];

                $this->assertSame($unknown, $hidden);
                $this->assertStringNotContainsString($rowId, implode(' ', [$hidden['show'], $hidden['content'], $hidden['archive']]));
            }
        }
    }

    #[Test]
    public function the_owner_loses_attachment_writes_with_the_teaching_relationship(): void
    {
        $w = $this->contentWorld();
        [$owner, , , [$a, $b]] = $this->contentTeacher($w, ['sectionA', 'sectionB']);
        $row = $this->teacherContent($w, $owner, ['sectionA', 'sectionB'], 'published');
        $documentId = $this->upload($w, $owner, 'learning-content', $row->id)->json('data.document_id');

        $this->endTeaching($w, $b);

        $this->upload($w, $owner, 'learning-content', $row->id)->assertStatus(422)->assertJsonPath('error.code', 'LEARNING_CONTENT_OUTSIDE_TEACHING_ASSIGNMENT');
        $this->as($owner)->postJson($this->base($w)."/documents/{$documentId}/archive")->assertStatus(422);
        $this->as($owner)->getJson($this->base($w)."/documents/{$documentId}")->assertOk();
        $this->assertSame(1, $this->documents($w), 'No new bytes or row for a refused write.');
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    #[Test]
    public function each_parent_kind_needs_its_own_owned_capability(): void
    {
        // A role carrying only lms.content.teacher reaches Learning Content
        // attachments, never Assignment ones (TCH.5D's own capability).
        $w = $this->contentWorld();
        $role = Role::query()->create(['key' => 'test.content_only.'.Str::uuid(), 'name' => 'Content only', 'scope' => 'school', 'is_system' => false]);
        $role->capabilities()->sync(['lms.content.teacher']);
        [$teacher] = $this->contentTeacher($w, roleKey: $role->key);
        $assignment = app(AssignmentService::class)->create($w['school'], $w['offering']->id, ['title' => 'Worksheet', 'due_on' => '2026-10-30'], $w['admin']);
        app(AssignmentService::class)->publish($w['school'], $assignment, $w['admin']);
        $documentId = $this->upload($w, $w['admin'], 'assignments', $assignment->id)->assertCreated()->json('data.document_id');
        $row = $this->teacherContent($w, $teacher);

        $this->upload($w, $teacher, 'learning-content', $row->id)->assertCreated();
        $this->as($teacher)->getJson($this->base($w)."/assignments/{$assignment->id}/documents")->assertForbidden();
        $this->as($teacher)->getJson($this->base($w)."/documents/{$documentId}")->assertForbidden();
        $this->upload($w, $teacher, 'assignments', $assignment->id)->assertForbidden();
        $this->as($w['admin'])->getJson($this->base($w)."/documents/{$documentId}")->assertOk();
    }
}
