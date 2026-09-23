<?php

namespace Database\Seeders\Demo;

use App\Models\School;

final class DemoBuildResult
{
    /**
     * @param  array<int, array{persona: string, email: string, school: string, access: string}>  $accounts
     * @param  array<int, string>  $notes
     */
    public function __construct(
        public readonly School $school,
        public readonly School $secondSchool,
        public readonly array $accounts,
        public readonly array $notes,
    ) {}
}
