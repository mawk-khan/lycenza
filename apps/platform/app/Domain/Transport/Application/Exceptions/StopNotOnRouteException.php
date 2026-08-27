<?php

namespace App\Domain\Transport\Application\Exceptions;

/**
 * Checkpoint brief section 13 ("Stop integrity"): a friendly
 * application-level check before ever attempting the write --
 * `transport_stops`' `(id, route_id, school_id)` composite FK on
 * `transport_student_assignments` is the actual, always-on database
 * enforcement of this same invariant (see
 * tests/Feature/Postgres/TransportStopsRlsIsolationTest.php for the
 * direct-SQL proof); this exception exists only to turn what would
 * otherwise be a raw PostgreSQL foreign-key-violation QueryException
 * into the project's normal error envelope.
 */
class StopNotOnRouteException extends TransportException
{
    public function __construct()
    {
        parent::__construct(422, 'TRANSPORT_STOP_NOT_ON_ROUTE', 'The selected pickup/drop-off Stop does not belong to this Route.');
    }
}
