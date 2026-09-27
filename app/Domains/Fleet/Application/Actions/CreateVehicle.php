<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Application\Data\SaveVehicleData;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

/** Créer un véhicule — ex-Admin\VehicleController::store(). */
final class CreateVehicle
{
    public function __invoke(SaveVehicleData $data): Vehicle
    {
        return $this->createVehicle($data->toServicePayload());
    }

    private function createVehicle(array $data): Vehicle
    {
        return DB::transaction(function () use ($data) {
            // Créer le véhicule
            $vehicle = Vehicle::create([
                'vehicle_number' => $data['vehicle_number'],
                'vehicle_type' => $data['vehicle_type'],
                'notes' => $data['notes'] ?? null,
                'is_active' => true,
            ]);

            return $vehicle;
        });
    }
}
