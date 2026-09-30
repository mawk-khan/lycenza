<?php

use App\Domain\Documents\Application\CreateDocumentData;
use App\Domain\Documents\Application\DocumentOwner;
use App\Domain\Documents\Application\DocumentService;
use App\Domain\HR\Application\Exceptions\ActingEmployeeUnavailableException;
use App\Domain\LMS\Application\Exceptions\LmsException;
use App\Domain\LMS\Application\LearningContentService;
use App\Domain\LMS\Application\TeacherLearningContentAccess;
use App\Domain\LMS\Infrastructure\LearningContent;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for TeacherLearningContentConcurrencyTest
// (TCH.5C, ADR 0063 sections 20, 36): one OWNED teacher Learning Content
// write in a GENUINELY separate OS process -- LearningContentService with the
// TeacherLearningContentGuard, or a Documents attachment write through
// LmsParentResourceAuthorization, exactly as the /my/ and Documents endpoints
// call them. The ineligibility side reuses acting-employee-op.php,
// staff-account-op.php and teaching-assignment-op.php.
//
// Usage:
//   php teacher-learning-content-op.php create <schoolId> <userId> <offeringId> <sectionId,sectionId,...>  (client order)
//   php teacher-learning-content-op.php update <schoolId> <userId> <learningContentId>
//   php teacher-learning-content-op.php attach <schoolId> <userId> <learningContentId>

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
        $service = $app->make(LearningContentService::class);
        $guard = $app->make(TeacherLearningContentAccess::class)->guard($user);

        switch ($operation) {
            case 'create':
                $content = $service->createOwned($school, $args[2], ['title' => 'Race reading'], explode(',', $args[3]), $user, $guard);

                return 'created:'.$content->id;
            case 'update':
                $content = $context->withSchool($school, fn () => LearningContent::query()->findOrFail($args[2]));
                $service->update($school, $content, ['title' => 'Race edit'], $user, $guard);

                return 'updated';
            case 'attach':
                $path = tempnam(sys_get_temp_dir(), 'race').'.pdf';
                file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
                $file = new UploadedFile($path, 'handout.pdf', 'application/pdf', null, true);
                $document = $app->make(DocumentService::class)->create($school, new CreateDocumentData(DocumentOwner::learningContent($args[2]), 'internal', $file), $user);

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
