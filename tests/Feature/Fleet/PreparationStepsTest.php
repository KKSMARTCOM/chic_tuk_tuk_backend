<?php

namespace Tests\Feature\Fleet;

use App\Domains\Fleet\Domain\PreparationSteps;
use App\Models\PreparationStepTemplate;
use App\Models\VehicleContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** La préparation d'un véhicule (spec 2026-10-20, §3 et §4.1). */
class PreparationStepsTest extends TestCase
{
    use RefreshDatabase;

    private function templates(string ...$labels): void
    {
        PreparationStepTemplate::query()->delete();
        foreach ($labels as $i => $label) {
            PreparationStepTemplate::create(['label' => $label, 'position' => $i + 1]);
        }
    }

    private function pending(): VehicleContract
    {
        return VehicleContract::factory()->create(['status' => 'pending', 'start_date' => null]);
    }

    public function test_seed_copies_the_defaults_then_the_agent_arrival(): void
    {
        $this->templates('A', 'B');
        $contract = $this->pending();

        PreparationSteps::seedFor($contract);

        $steps = $contract->preparationSteps()->get();
        $this->assertSame(['A', 'B', 'Arrivée du premier agent'], $steps->pluck('label')->all());
        $this->assertSame([1, 2, 3], $steps->pluck('position')->all());
        $this->assertSame([false, false, true], $steps->pluck('is_agent_arrival')->all());
    }

    public function test_seed_is_idempotent(): void
    {
        $this->templates('A', 'B');
        $contract = $this->pending();

        PreparationSteps::seedFor($contract);
        PreparationSteps::seedFor($contract);

        $this->assertSame(3, $contract->preparationSteps()->count());
    }

    public function test_the_migration_gives_the_defaults_to_pending_contracts_only(): void
    {
        $migration = require database_path('migrations/2026_10_20_100000_create_preparation_steps_tables.php');
        $migration->down();
        $target = $this->pending();
        $others = collect(['active', 'completed', 'cancelled'])->map(fn ($status) => VehicleContract::factory()->create(['status' => $status]));

        $migration->up();

        $steps = $target->preparationSteps()->orderBy('position')->get();
        $this->assertCount(10, $steps);
        $this->assertSame(PreparationSteps::AGENT_ARRIVAL_LABEL, $steps->last()->label);
        $this->assertSame([false, true], [$steps->first()->is_agent_arrival, $steps->last()->is_agent_arrival]);
        $others->each(fn ($contract) => $this->assertSame(0, $contract->preparationSteps()->count()));
    }

    public function test_the_migration_seeds_the_nine_defaults(): void
    {
        $this->assertSame([
            'Acquisition du tricycle neuf',
            'Pose de la bâche de protection pluie',
            'Installation du Pocket Wifi',
            'Installation de la sonorisation Bluetooth',
            'Conception et pose des autocollants QR code Wifi',
            'Installation de la tablette Android et de son support',
            'Souscription de l\'assurance (1 an)',
            'Installation du tracking GPS',
            'Formalités administratives',
        ], PreparationStepTemplate::orderBy('position')->pluck('label')->all());
    }

    public function test_only_a_pending_contract_is_editable(): void
    {
        $this->assertTrue(PreparationSteps::isEditable($this->pending()));
        $this->assertFalse(PreparationSteps::isEditable(VehicleContract::factory()->create(['status' => 'active'])));
    }
}
