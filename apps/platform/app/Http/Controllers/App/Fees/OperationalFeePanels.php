<?php

namespace App\Http\Controllers\App\Fees;

use App\Domain\Admissions\Application\AdmissionFeeSelectionService;
use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Admissions\Infrastructure\AdmissionFeeSelection;
use App\Domain\Fees\Application\Sources\FeeHeadDirectory;
use App\Domain\Hostel\Application\HostelFeeSelectionService;
use App\Domain\Hostel\Infrastructure\Hostel;
use App\Domain\Hostel\Infrastructure\HostelRoom;
use App\Domain\Transport\Application\TransportFeeSelectionService;
use App\Domain\Transport\Infrastructure\TransportRoute;
use App\Models\School;

/**
 * SWC.3 (ADR 0073 section 19): the read side of the OPF panels on existing
 * pages -- a Transport route's fee head, a Hostel's and a room's fee head,
 * and a converted admission application's fee intent. Every value comes
 * from the OPF services (or, for fee-head labels, the Fees seam
 * FeeHeadDirectory); nothing here computes an
 * amount or decides a rule. Fee intent is recorded optional-fee selection,
 * never a charge or a payment.
 */
final class OperationalFeePanels
{
    public function __construct(
        private readonly FeeHeadDirectory $fees,
        private readonly TransportFeeSelectionService $transport,
        private readonly HostelFeeSelectionService $hostels,
        private readonly AdmissionFeeSelectionService $admissions,
    ) {}

    /**
     * The fee-head picker: the School's ACTIVE heads (the only ones an OPF
     * mapping accepts). Empty for a read-only viewer.
     *
     * @return list<array{id: string, code: string, name: string}>
     */
    public function feeHeadOptions(School $school, bool $canManage): array
    {
        if (! $canManage) {
            return [];
        }

        return array_values(array_map(
            fn (array $head) => ['id' => $head['id'], 'code' => $head['code'], 'name' => $head['name']],
            array_filter($this->fees->forSchool($school), fn (array $head) => $head['active']),
        ));
    }

    /** @return array<string, mixed> */
    public function transportRoute(School $school, TransportRoute $route, bool $canManage): array
    {
        return [
            'feeHead' => $this->label($school, $this->transport->routeFeeHead($route)),
            'options' => $this->feeHeadOptions($school, $canManage),
            'canManage' => $canManage,
        ];
    }

    /** @return array<string, mixed> */
    public function hostel(School $school, Hostel $hostel, bool $canManage): array
    {
        return [
            'feeHead' => $this->label($school, $this->hostels->hostelFeeHead($hostel)),
            'options' => $this->feeHeadOptions($school, $canManage),
            'canManage' => $canManage,
        ];
    }

    /**
     * A room's own override and its Hostel's default, and which of the two
     * governs new fee intent -- the service's precedence (room override,
     * else Hostel default; ADR 0067 OPF.2), reported, never re-decided.
     *
     * @return array<string, mixed>
     */
    public function room(School $school, HostelRoom $room, bool $canManage): array
    {
        $override = $this->label($school, $this->hostels->roomFeeHead($room));
        $default = $this->label($school, $this->hostels->hostelFeeHead($room->hostel));

        return [
            'feeHead' => $override,
            'hostelDefault' => $default,
            'governing' => $override !== null ? 'room' : ($default !== null ? 'hostel' : null),
            'options' => $this->feeHeadOptions($school, $canManage),
            'canManage' => $canManage,
        ];
    }

    /**
     * The School's configured admission fee head and, for a converted
     * application, the fee intent its conversion recorded (if any). There is
     * no per-conversion choice (ADR 0067 D7).
     *
     * @return array<string, mixed>
     */
    public function admissionApplication(School $school, AdmissionApplication $application): array
    {
        $link = AdmissionFeeSelection::query()->where('admission_application_id', $application->id)->first();
        $directory = $this->directory($school);

        return [
            'configured' => $this->label($school, $this->admissions->feeHead($school)),
            'intent' => $link === null ? null : [
                'feeHead' => $directory[$link->fee_head_id] ?? null,
                'academicYearId' => $link->academic_year_id,
                'selectionOutcome' => $link->selection_outcome,
                'recordedAt' => $link->created_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * A mapped head with its active flag (an OPF read returns an empty
     * code/name for a head that is no longer active).
     *
     * @param  array{feeHeadId: string, code: string, name: string}|null  $mapping
     * @return array{id: string, code: string, name: string, active: bool}|null
     */
    public function label(School $school, ?array $mapping): ?array
    {
        if ($mapping === null) {
            return null;
        }

        return $this->directory($school)[$mapping['feeHeadId']] ?? ['id' => $mapping['feeHeadId'], 'code' => '', 'name' => '', 'active' => false];
    }

    /** @return array<string, array{id: string, code: string, name: string, active: bool}> */
    public function directory(School $school): array
    {
        $byId = [];
        foreach ($this->fees->forSchool($school) as $head) {
            $byId[$head['id']] = $head;
        }

        return $byId;
    }
}
