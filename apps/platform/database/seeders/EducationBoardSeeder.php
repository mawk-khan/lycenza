<?php

namespace Database\Seeders;

use App\Models\EducationBoard;
use Illuminate\Database\Seeder;

/**
 * Phase 0D sections 92-93: a deliberately minimal, factual reference
 * catalog -- name and code only, no policy/compliance claims. State
 * Boards are NOT seeded here: there are dozens, and reliable codes are
 * not already sourced (section 93) -- a School using a State Board
 * uses the "other/custom" entry, or a future checkpoint can add a
 * properly-sourced State Board catalog once that data is verified.
 */
class EducationBoardSeeder extends Seeder
{
    public function run(): void
    {
        $boards = [
            ['code' => 'cbse', 'name' => 'Central Board of Secondary Education (CBSE)'],
            ['code' => 'cisce', 'name' => 'Council for the Indian School Certificate Examinations (CISCE)'],
            ['code' => 'ib', 'name' => 'International Baccalaureate (IB)'],
            ['code' => 'cambridge', 'name' => 'Cambridge Assessment International Education'],
            ['code' => 'other', 'name' => 'Other / Custom'],
        ];

        foreach ($boards as $board) {
            EducationBoard::query()->updateOrCreate(['code' => $board['code']], $board + ['status' => 'active']);
        }
    }
}
