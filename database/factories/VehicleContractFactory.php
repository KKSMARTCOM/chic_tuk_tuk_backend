<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleContract>
 */
class VehicleContractFactory extends Factory
{
    protected $model = VehicleContract::class;

    public function definition(): array
    {
        return [
            'vehicle_id' => Vehicle::factory(),
            'owner_id' => User::factory(),
            // Les montants du contrat de 24 mois, tels que la migration du 2026-09-29 amorce
            // les réglages.
            'total_amount' => 3_100_000,
            'monthly_payment' => 130_000,
            'contract_months' => 24,
            'start_date' => now()->subMonths(6)->startOfMonth(),
            'end_date' => null,
            'status' => 'active',
            'daily_amount' => 6112,
            'daily_tax' => 241,
            'unlimited_internet' => 5_000,
            'spotify_premium' => 2_500,
            'manager_remuneration' => 20_000,
        ];
    }

    /**
     * Rattache le contrat à un véhicule ET reprend son propriétaire.
     *
     * Sans cela, `owner_id` du contrat et `owner_id` du véhicule désignent deux
     * personnes différentes. Cela n'arrive jamais en production, et fausserait
     * silencieusement les tests de portée de l'API.
     */
    public function forVehicle(Vehicle $vehicle): static
    {
        return $this->state(fn () => [
            'vehicle_id' => $vehicle->id,
            'owner_id' => $vehicle->owner_id,
        ]);
    }

    /** Contrat terminé : `status` hors `active`, donc invisible d'`activeVehicleContract`. */
    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => 'completed',
            'end_date' => now()->subDay(),
        ]);
    }
}
