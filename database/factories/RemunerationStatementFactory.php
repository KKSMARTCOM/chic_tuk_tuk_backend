<?php

namespace Database\Factories;

use App\Models\RemunerationStatement;
use App\Models\VehicleContract;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RemunerationStatement>
 */
class RemunerationStatementFactory extends Factory
{
    protected $model = RemunerationStatement::class;

    public function definition(): array
    {
        return [
            'vehicle_contract_id' => VehicleContract::factory(),
            'month' => '2026-10-01',
            'status' => 'draft',
        ];
    }

    public function validated(): static
    {
        return $this->state(fn () => [
            'status' => 'validated',
            'number' => 'FR-2026-10-'.fake()->unique()->numerify('###'),
            'validated_at' => now(),
            'figures' => [],
            'balance_due' => 0,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancel_reason' => 'Erreur de saisie',
        ]);
    }
}
