<?php

namespace App\Domains\Workforce\Application\Data;

use App\Models\LeaveRequest;
use App\Shared\Data\BaseData;

/**
 * Une demande en attente dans la file de l'administration — ex-Admin\LeaveController::requests().
 *
 * ⚠️ Elle PORTE le nom de l'agent, contrairement à `LeaveRequestData` qui sert l'écran de
 * l'agent lui-même : celui-ci sait de qui il s'agit, l'administrateur non. C'est la seule
 * différence, et elle justifie une classe distincte plutôt qu'un champ facultatif qui
 * vaudrait `null` la moitié du temps.
 */
final class AdminLeaveRequestData extends BaseData
{
    public function __construct(
        public string $id,
        public string $driverId,
        public ?string $driverName,
        public string $startDate,
        public int $requestedDays,
        public ?string $expectedEndDate,
        /** Solde disponible de l'agent AU MOMENT de la lecture : c'est ce qui décide. */
        public int $availableLeaveDays,
        public bool $hasActiveContract,
        public string $requestedAt,
    ) {}

    public static function fromModel(LeaveRequest $demande): self
    {
        $agent = $demande->driver;

        return new self(
            id: $demande->id,
            driverId: (string) $demande->driver_id,
            driverName: $agent?->user?->name,
            startDate: $demande->start_date?->format('Y-m-d') ?? '',
            requestedDays: (int) $demande->requested_days,
            expectedEndDate: $demande->expected_end_date?->format('Y-m-d'),
            // ⚠️ L'information qui manque le plus pour trancher : approuver une demande
            // sans voir le solde oblige à ouvrir la fiche de l'agent à chaque fois.
            availableLeaveDays: $agent?->soldeDeReference()->availableDays ?? 0,
            hasActiveContract: $agent?->activeDriverContract !== null,
            requestedAt: $demande->created_at->toIso8601String(),
        );
    }
}
