<?php

namespace Database\Factories;

use App\Domains\Fleet\Domain\PreparationSteps;
use App\Models\VehicleContract;
use App\Models\VehicleContractPreparationStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VehicleContractPreparationStep> */
class VehicleContractPreparationStepFactory extends Factory
{
    public function definition(): array
    {
        return [
            'vehicle_contract_id' => VehicleContract::factory()->state(['status' => 'pending', 'start_date' => null]),
            'label' => 'Installation du Pocket Wifi',
            'position' => 1,
        ];
    }

    public function done(string $date): static
    {
        return $this->state(['done_on' => $date, 'done_at' => now()]);
    }

    public function agentArrival(): static
    {
        return $this->state(['label' => PreparationSteps::AGENT_ARRIVAL_LABEL, 'is_agent_arrival' => true, 'position' => 99]);
    }
}
