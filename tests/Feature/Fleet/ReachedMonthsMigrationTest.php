<?php

namespace Tests\Feature\Fleet;

use App\Models\RemunerationStatement;
use App\Models\VehicleContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReachedMonthsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function backfill(): void
    {
        $migration = require database_path('migrations/2026_10_12_100000_add_reached_months_to_vehicle_contracts.php');
        $migration->backfill();
    }

    public function test_the_first_validated_sheet_that_reaches_each_target(): void
    {
        $contract = VehicleContract::factory()->create([
            'total_amount' => 200_000, 'contract_months' => 2, 'start_date' => '2026-07-01',
            'unlimited_internet' => 5_000, 'spotify_premium' => 2_500, 'manager_remuneration' => 20_000,
        ]);
        foreach ([['2026-07-01', 150_000, 27_500], ['2026-08-01', 210_000, 55_000], ['2026-09-01', 260_000, 55_000]] as [$month, $revenue, $charges]) {
            RemunerationStatement::factory()->for($contract, 'contract')->validated()->create([
                'month' => $month, 'figures' => ['cumulative_revenue' => $revenue, 'cumulative_charges' => $charges],
            ]);
        }

        $this->backfill();

        $contract->refresh();
        $this->assertSame('2026-08-01', $contract->revenue_reached_month->toDateString());
        $this->assertSame('2026-08-01', $contract->charges_reached_month->toDateString());
        $this->assertTrue($contract->isReadyToSettle());
    }

    public function test_drafts_and_cancelled_sheets_do_not_count(): void
    {
        $contract = VehicleContract::factory()->create(['total_amount' => 100_000, 'contract_months' => 24]);
        RemunerationStatement::factory()->for($contract, 'contract')->create([
            'month' => '2026-07-01', 'figures' => ['cumulative_revenue' => 500_000],
        ]);
        RemunerationStatement::factory()->for($contract, 'contract')->cancelled()->create([
            'month' => '2026-08-01', 'figures' => ['cumulative_revenue' => 500_000],
        ]);

        $this->backfill();

        $this->assertNull($contract->refresh()->revenue_reached_month);
        $this->assertFalse($contract->isReadyToSettle());
    }
}
