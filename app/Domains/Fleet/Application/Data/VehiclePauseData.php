<?php

namespace App\Domains\Fleet\Application\Data;

use App\Models\LeaveRequest;
use App\Models\Vehicle;
use App\Models\VehiclePause;
use App\Shared\Data\BaseData;
use App\Domains\Fleet\Domain\Enums\VehiclePauseReason;
use Carbon\Carbon;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

final class VehiclePauseData extends BaseData
{
    public function __construct(
        public string $id,
        public string $startDate,
        public ?string $endDate,
        #[TypeScriptType(VehiclePauseReason::class)]
        public string $reasonType,
        public string $reasonLabel,
        public ?string $reasonNotes,
        public bool $isAuto,
        public ?int $daysCount,
    ) {}

    public static function fromModel(VehiclePause $pause): self
    {
        return new self(
            id: $pause->id,
            startDate: $pause->start_date->toDateString(),
            endDate: $pause->end_date?->toDateString(),
            reasonType: $pause->reason_type,
            reasonLabel: $pause->reason_label,
            reasonNotes: $pause->reason_notes,
            isAuto: (bool) $pause->is_auto,
            // ⚠️ NE PAS utiliser l'accesseur $pause->days : il omet le « + 1 » et
            // substitue now() à une date de fin absente, donc il renvoie un autre
            // nombre que celui affiché aujourd'hui. Les deux formules coexistent dans
            // le code ; celle de l'écran est figée ici.
            daysCount: self::daysCount($pause->start_date, $pause->end_date),
        );
    }

    /**
     * L'historique des pauses d'un véhicule, de la plus récente à la plus ancienne.
     *
     * Attend un véhicule ayant chargé `pauses` et `driverContracts.leaveRequests`.
     *
     * @return list<self>
     */
    public static function historyOf(Vehicle $vehicle): array
    {
        return self::history(
            $vehicle->pauses,
            $vehicle->driverContracts->flatMap(fn ($contract) => $contract->leaveRequests),
        );
    }

    /**
     * Des pauses véhicule et des pauses d'agent du même périmètre (un véhicule, un
     * contrat propriétaire, un contrat agent), réunies de la plus récente à la plus
     * ancienne.
     *
     * ⚠️ `vehicle_pauses` ne suffit pas : une pause d'agent saisie après coup
     * (`AddHistoricalLeave`) n'a jamais de pause véhicule, alors que le solde la compte
     * (`ContractMonthCalculator`). Ces pauses d'agent s'ajoutent donc aux lignes, comme
     * celles dont la pause véhicule a disparu (défaut du 2026-10-05). Une pause d'agent
     * dont la pause véhicule automatique existe n'apparaît qu'une fois, par celle-ci —
     * même si cette pause véhicule est rattachée ailleurs.
     *
     * @param  iterable<VehiclePause>  $vehiclePauses
     * @param  iterable<LeaveRequest>  $agentLeaves
     * @return list<self>
     */
    public static function history(iterable $vehiclePauses, iterable $agentLeaves): array
    {
        $agentLeaves = collect($agentLeaves)
            ->filter(fn (LeaveRequest $leave) => in_array($leave->status, ['ongoing', 'completed'], true));

        $linkedIds = $agentLeaves->pluck('vehicle_pause_id')->filter()->unique();
        $existingIds = $linkedIds->isEmpty()
            ? []
            : VehiclePause::query()->whereIn('id', $linkedIds)->pluck('id')->all();

        return collect($vehiclePauses)
            ->map(fn (VehiclePause $pause) => self::fromModel($pause))
            ->concat($agentLeaves
                ->reject(fn (LeaveRequest $leave) => in_array($leave->vehicle_pause_id, $existingIds, true))
                ->map(fn (LeaveRequest $leave) => self::fromAgentLeave($leave)))
            ->sortByDesc('startDate')
            ->values()
            ->all();
    }

    /**
     * Une pause d'agent sans pause véhicule, présentée comme la pause automatique
     * qu'elle aurait créée. Son motif reste vide : le propriétaire n'a pas à lire celui
     * de l'agent, et `is_auto` tient la ligne à l'écart des actions de l'administration
     * sur les pauses véhicule.
     */
    public static function fromAgentLeave(LeaveRequest $leave): self
    {
        return new self(
            id: $leave->id,
            startDate: $leave->start_date->toDateString(),
            endDate: $leave->end_date?->toDateString(),
            reasonType: VehiclePauseReason::AgentLeave->value,
            reasonLabel: VehiclePauseReason::AgentLeave->label(),
            reasonNotes: null,
            isAuto: true,
            daysCount: self::daysCount($leave->start_date, $leave->end_date),
        );
    }

    private static function daysCount(Carbon $start, ?Carbon $end): ?int
    {
        return $end ? (int) $start->diffInDays($end) + 1 : null;
    }
}
