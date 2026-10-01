<?php

use App\Domain\Documents\Application\CreateDocumentData;
use App\Domain\Documents\Application\DocumentOwner;
use App\Domain\Documents\Application\DocumentService;
use App\Domain\HR\Application\Exceptions\ActingEmployeeUnavailableException;
use App\Domain\LMS\Application\AssignmentService;
use App\Domain\LMS\Application\Exceptions\LmsException;
use App\Domain\LMS\Application\TeacherAssignmentAccess;
use App\Domain\LMS\Infrastructure\Assignment;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for TeacherAssignmentConcurrencyTest
// (TCH.5D, ADR 0063 sections 20, 37): one OWNED teacher Assignment write in a
// GENUINELY separate OS process -- AssignmentService with the
// TeacherAssignmentGuard, or a Documents attachment write through
// LmsParentResourceAuthorization, exactly as the /my/ and Documents endpoints
// call them. The ineligibility side reuses acting-employee-op.php,
// staff-account-op.php and teaching-assignment-op.php.
//
// Usage:
//   php teacher-assignment-op.php create <schoolId> <userId> <offeringId> <sectionId,sectionId,...>  (client order)
//   php teacher-assignment-op.php update <schoolId> <userId> <assignmentId>
//   php teacher-assignment-op.php attach <schoolId> <userId> <assignmentId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);
$context = $app->make(TenantContext::class);

// The School context is held for the whole unit of work, exactly as the
// School-route middleware holds it for a whole request: a teacher-owned row's
// deferred ">= 1 audience" check runs at COMMIT under RLS (ADR 0063 §35).
$school = School::query()->findOrFail($args[0]);

try {
    echo $context->withSchool($school, fn () => HeldTransaction::run(function () use ($app, $context, $operation, $args, $school): string {
        $user = User::query()->findOrFail($args[1]);
        $service = $app->make(AssignmentService::class);
        $guard = $app->make(TeacherAssignmentAccess::class)->guard($user);

        switch ($operation) {
            case 'create':
                $assignment = $service->createOwned($school, $args[2], ['title' => 'Race worksheet', 'due_on' => '2026-10-30'], explode(',', $args[3]), $user, $guard);

                return 'created:'.$assignment->id;
            case 'update':
                $assignment = $context->withSchool($school, fn () => Assignment::query()->findOrFail($args[2]));
                $service->update($school, $assignment, ['title' => 'Race edit'], $user, $guard);

                return 'updated';
            case 'attach':
                $path = tempnam(sys_get_temp_dir(), 'race').'.pdf';
                file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
                $file = new UploadedFile($path, 'handout.pdf', 'application/pdf', null, true);
                $document = $app->make(DocumentService::class)->create($school, new CreateDocumentData(DocumentOwner::assignment($args[2]), 'internal', $file), $user);

                return 'attached:'.$document->id;
        }

        return 'unknown';
    }));
} catch (ActingEmployeeUnavailableException $e) {
    echo 'denied:'.$e->reason;
} catch (LmsException $e) {
    echo 'rejected:'.$e->errorCode();
} catch (ModelNotFoundException) {
    echo 'not_found';
} catch (Throwable $e) {
    echo 'error:'.$e::class.':'.$e->getMessage();
} finally {
    $context->clearAll();
}
