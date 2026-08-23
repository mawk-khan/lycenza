<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EducationBoard;
use Illuminate\Http\JsonResponse;

/**
 * Phase 0D section 11: read-only platform reference catalog. No
 * capability gate -- any authenticated user may see the list of
 * available Education Boards (not School-owned, not sensitive).
 */
class EducationBoardController extends Controller
{
    public function index(): JsonResponse
    {
        $boards = EducationBoard::query()->where('status', 'active')->orderBy('name')->get();

        return response()->json([
            'data' => $boards->map(fn (EducationBoard $b) => [
                'id' => $b->id,
                'code' => $b->code,
                'name' => $b->name,
            ])->all(),
        ]);
    }
}
