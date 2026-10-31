<?php

namespace App\Http\Controllers\App;

use App\Domain\Students\Application\Groups\ActivityCategory;
use App\Domain\Students\Application\Groups\StudentGroupType;
use App\Domain\Students\Infrastructure\StudentGroup;
use App\Support\Authorization\Campus\CampusScope;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SIS 11.8: the one Student Communities directory page (Houses, Clubs,
 * Activities). Presentation only: the rows and their scoped counts come from
 * the type's administration service.
 */
trait PresentsCommunityDirectory
{
    /** @param  Collection<int, array{group: StudentGroup, members: int}>  $rows */
    private function directory(StudentGroupType $type, Collection $rows, CampusScope $scope, bool $canManage): Response
    {
        $base = '/app/students/'.strtolower($type->plural());
        $filter = "{$type->value}_id";

        return Inertia::render('App/Students/CommunityDirectory', [
            'type' => ['value' => $type->value, 'label' => $type->label(), 'plural' => $type->plural(), 'baseUrl' => $base],
            'groups' => $rows->map(fn (array $row) => [
                'id' => $row['group']->id,
                'name' => $row['group']->name,
                'code' => $row['group']->code,
                'description' => $row['group']->description,
                'category' => $row['group']->activity_category?->value,
                'categoryLabel' => $row['group']->activity_category?->label(),
                'status' => $row['group']->status,
                'displayOrder' => $row['group']->display_order,
                'version' => $row['group']->version,
                'members' => $row['members'],
                'rosterUrl' => '/app/students/reports/students.'.strtolower($type->plural()).'?'.http_build_query([$filter => $row['group']->id]),
            ])->values()->all(),
            'categories' => $type === StudentGroupType::ACTIVITY ? ActivityCategory::options() : [],
            'countsScope' => $scope->isWholeSchool() ? 'whole_school' : 'campus',
            'canManage' => $canManage,
        ]);
    }
}
