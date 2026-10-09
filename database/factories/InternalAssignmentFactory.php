<?php

namespace Database\Factories;

use App\Models\Driver;
use App\Models\InternalAssignment;
use App\Models\VehicleContract;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InternalAssignment> */
class InternalAssignmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'vehicle_contract_id' => VehicleContract::factory(),
            'driver_id' => Driver::factory(),
            'start_date' => '2026-10-01',
            'end_date' => null,
        ];
    }

    public function ended(string $date): static
    {
        return $this->state(['end_date' => $date, 'ended_reason' => 'manual']);
    }
}
