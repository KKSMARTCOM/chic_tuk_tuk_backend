<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

/**
 * Enregistre les attributs d'un véhicule — ex-`VehicleService::update()`, déplacé sans
 * changement le 2026-09-27 : la modification et le changement de statut le partagent.
 */
final class SaveVehicleAttributes
{
    public function __invoke(Vehicle $vehicle, array $data): Vehicle
    {
        return DB::transaction(function () use ($data, $vehicle) {
            // Mettre à jour le véhicule
            $vehicle->update([
                'vehicle_number' => $data['vehicle_number'] ?? $vehicle->vehicle_number,
                'vehicle_type' => $data['vehicle_type'] ?? $vehicle->vehicle_type,
                // Une note vidée efface la note (corrigé le 2026-09-25 : `??` gardait
                // l'ancienne). `toggleStatus` et les autres appelants n'envoient pas la clé.
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $vehicle->notes,
                'is_active' => $data['is_active'] ?? $vehicle->is_active,
            ]);

            return $vehicle->refresh();
        });
    }
}
