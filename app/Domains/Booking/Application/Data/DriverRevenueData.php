<?php

namespace App\Domains\Booking\Application\Data;

use App\Models\Driver;
use App\Shared\Data\BaseData;

/**
 * Un agent dans le classement « Top 5 par revenus » du tableau de bord admin.
 *
 * ⚠️ `commissionDue` est la commission DUE, pas la commission générée : c'est la
 * différence entre ce que l'agent doit et ce qu'il a déjà versé. Le tableau Blade la
 * calcule dans le contrôleur, boucle sur les agents ; la formule est reprise telle
 * quelle — commissions cumulées moins paiements de type `commission`.
 */
final class DriverRevenueData extends BaseData
{
    public function __construct(
        public string $id,
        public ?string $name,
        /** Gains cumulés sur les courses terminées. */
        public float $earnings,
        public float $commissionDue,
    ) {}

    public static function fromModel(Driver $driver): self
    {
        return new self(
            id: $driver->id,
            name: $driver->user?->name,
            earnings: (float) ($driver->bookings_sum_driver_earning ?? 0),
            commissionDue: (float) ($driver->commission_due ?? 0),
        );
    }
}
