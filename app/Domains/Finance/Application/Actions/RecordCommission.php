<?php

namespace App\Domains\Finance\Application\Actions;

use App\Models\Commission;

/**
 * Enregistre la commission d'une course terminée — ex-`CommissionService::create()`,
 * déplacé sans changement le 2026-09-27.
 */
final class RecordCommission
{
    public function __invoke(array $data)
    {
        $commission = Commission::create([
            'driver_id' => $data['driver_id'],
            'booking_id' => $data['booking_id'],
            'amount' => $data['amount'],
            'status' => $data['status'],
            'date' => $data['date'],
        ]);

        return $commission;
    }
}
