<?php

namespace App\Domain\Transport\Application;

use App\Domain\Campuses\Application\ActiveCampus;
use App\Domain\Transport\Infrastructure\TransportRoute;
use App\Domain\Transport\Infrastructure\TransportRouteAssignment;
use App\Domain\Transport\Infrastructure\TransportStop;
use App\Domain\Transport\Infrastructure\TransportStudentAssignment;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ORG.7D-5 (ADR 0076 §41): the one writer of a Transport Route, for the
 * whole-School `transport.routes.manage` on web and API alike. Campus-scoped
 * Transport staff never create a Route or change its Campus.
 *
 * - Campus: an active Campus of this School (ActiveCampus), or none -- a
 *   School-shared route with whole-School authority only.
 * - Once used, a Route's Campus is fixed: it has a Stop, a rider assignment
 *   or a vehicle/driver assignment (any status -- history follows the
 *   route). The check runs under the Route row lock (FOR UPDATE); every
 *   writer of those dependents share-locks the Route first
 *   (lockForDependent()), so a dependent and a Campus change serialize.
 */
final class TransportRouteService
{
    public const string IN_USE_MESSAGE = 'A Route\'s Campus cannot change once it has Stops, riders or vehicle assignments.';

    public function __construct(private readonly ActiveCampus $campuses, private readonly AuditRecorder $audit) {}

    /** @param  array{code: string, name: string, description?: string|null, campus_id?: string|null}  $attributes */
    public function create(School $school, array $attributes, ?User $actor): TransportRoute
    {
        return DB::transaction(function () use ($school, $attributes, $actor): TransportRoute {
            $campusId = ($attributes['campus_id'] ?? null) ?: null;
            if ($campusId !== null) {
                $this->campuses->require($school, $campusId);
            }

            $route = TransportRoute::query()->create([...$attributes, 'campus_id' => $campusId, 'school_id' => $school->id, 'status' => 'active']);

            $this->audit->school($school, 'transport.route.created', actor: $actor, subject: $route, metadata: [
                'code' => $route->code,
                'name' => $route->name,
                'campusId' => $route->campus_id,
            ]);

            return $route;
        });
    }

    /** @param  array{name?: string, description?: string|null, campus_id?: string|null, status?: string}  $changes */
    public function update(School $school, string $routeId, array $changes, ?User $actor): TransportRoute
    {
        return DB::transaction(function () use ($school, $routeId, $changes, $actor): TransportRoute {
            $route = TransportRoute::query()->where('school_id', $school->id)->whereKey($routeId)->lockForUpdate()->firstOrFail();

            if (array_key_exists('campus_id', $changes)) {
                $changes['campus_id'] = $changes['campus_id'] ?: null;
                if ($changes['campus_id'] !== $route->campus_id) {
                    if ($changes['campus_id'] !== null) {
                        $this->campuses->require($school, $changes['campus_id']);
                    }
                    if ($this->inUse($route)) {
                        throw ValidationException::withMessages(['campus_id' => [self::IN_USE_MESSAGE]]);
                    }
                }
            }

            $before = $route->only(array_keys($changes));
            $route->update($changes);

            $this->audit->school($school, 'transport.route.updated', actor: $actor, subject: $route, metadata: [
                'before' => $before,
                'after' => $changes,
            ]);

            return $route->refresh();
        });
    }

    /**
     * Share-locks the Route inside a writer's transaction before it adds a
     * Stop, a rider or a vehicle/driver assignment -- a concurrent Campus
     * change waits, or has committed and is seen.
     */
    public static function lockForDependent(TransportRoute $route): TransportRoute
    {
        return TransportRoute::query()->where('school_id', $route->school_id)->whereKey($route->id)->sharedLock()->firstOrFail();
    }

    private function inUse(TransportRoute $route): bool
    {
        return TransportStop::query()->where('route_id', $route->id)->exists()
            || TransportStudentAssignment::query()->where('route_id', $route->id)->exists()
            || TransportRouteAssignment::query()->where('route_id', $route->id)->exists();
    }
}
