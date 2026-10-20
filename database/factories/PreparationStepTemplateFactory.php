<?php

namespace Database\Factories;

use App\Models\PreparationStepTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PreparationStepTemplate> */
class PreparationStepTemplateFactory extends Factory
{
    public function definition(): array
    {
        return ['label' => 'Pose de l\'équipement '.fake()->unique()->numberBetween(1, 9999), 'position' => 1];
    }
}
