<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Support\Retention\Erasure\ErasureCaseException;
use App\Support\Retention\Erasure\ErasureCaseService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * E21.2F (E21-D10): records a data-subject erasure REQUEST as a reviewed
 * case. This is an operator console action; nothing is decided or deleted.
 * It prints only the case id and codes.
 */
class OpenErasureCase extends Command
{
    protected $signature = 'platform:erasure-case-open
        {--subject-type= : student|guardian|employee|user}
        {--subject= : The subject id}
        {--school= : The School id (every subject type except user)}
        {--channel= : written|email|in_person|other}';

    protected $description = 'Record a data-subject erasure request as a reviewed case (E21-D10; operator only, audited).';

    public function handle(ErasureCaseService $cases): int
    {
        $schoolId = $this->option('school');
        $school = is_string($schoolId) && $schoolId !== '' ? School::query()->find($schoolId) : null;

        if (is_string($schoolId) && $schoolId !== '' && $school === null) {
            $this->error('No such School.');

            return self::FAILURE;
        }

        try {
            $case = $cases->open($school, (string) $this->option('subject-type'), (string) $this->option('subject'), (string) $this->option('channel'));
        } catch (ErasureCaseException|InvalidArgumentException $e) {
            $this->error('Refused: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Opened erasure case {$case->id} ({$case->scope}, {$case->subject_type}); status: requested.");

        return self::SUCCESS;
    }
}
