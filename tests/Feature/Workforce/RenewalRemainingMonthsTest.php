<?php

namespace Tests\Feature\Workforce;

use App\Domains\Finance\Domain\ContractTargets;
use App\Domains\Workforce\Application\Actions\CreateRenewalContract;
use App\Models\Driver;
use App\Models\DriverContract;
use App\Models\VehicleContract;
use App\Models\VehiclePause;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RenewalRemainingMonthsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_immobilized_month_does_not_consume_the_contract(): void
    {
        Carbon::setTestNow('2026-06-30 09:00:00');
        $contract = VehicleContract::factory()->create(['contract_months' => 24, 'start_date' => '2026-01-01']);
        // Mars immobilisé en entier : 5 mois travaillés sur 6 écoulés.
        VehiclePause::factory()->forContract($contract)->create([
            'start_date' => '2026-03-01', 'end_date' => '2026-03-31', 'reason_type' => 'agent_change',
        ]);

        $this->assertSame(19, ContractTargets::remainingMonths($contract));
    }

    public function test_a_fully_worked_active_contract_still_renews_for_one_month(): void
    {
        // Contrat de 1 mois, travaillé en entier, cumuls non atteints : il levait « Ce contrat
        // propriétaire ne dispose plus de temps restant ». Appel direct, comme
        // `TakeOverVehicleTest::test_a_renewal_ignores_a_pending_contract`.
        Carbon::setTestNow('2026-10-12 09:00:00');
        $contract = VehicleContract::factory()->create(['contract_months' => 1, 'start_date' => '2026-07-01']);
        // L'agent précédent, parti fin septembre : 3 mois calendaires consommés pour l'ancien calcul.
        DriverContract::factory()->forVehicleContract($contract)->create([
            'start_date' => '2026-07-01', 'end_date' => '2026-09-30', 'status' => 'ended', 'contract_months' => 1,
        ]);
        $driver = Driver::factory()->create();

        app(CreateRenewalContract::class)($driver, [
            'renewal_vehicle_id' => $contract->vehicle_id, 'renewal_owner_id' => $contract->vehicle->owner_id,
            'renewal_contract_months' => 1, 'renewal_start_date' => '2026-10-12',
        ]);

        $this->assertDatabaseHas('driver_contracts', ['driver_id' => $driver->id, 'vehicle_contract_id' => $contract->id, 'contract_months' => 1]);
    }

    public function test_less_than_the_remaining_worked_months_is_refused(): void
    {
        Carbon::setTestNow('2026-10-12 09:00:00');
        $contract = VehicleContract::factory()->create(['contract_months' => 24, 'start_date' => '2026-07-01']);

        $this->expectExceptionMessage('inférieure au temps restant (20 mois)');

        app(CreateRenewalContract::class)(Driver::factory()->create(), [
            'renewal_vehicle_id' => $contract->vehicle_id, 'renewal_owner_id' => $contract->vehicle->owner_id,
            'renewal_contract_months' => 12, 'renewal_start_date' => '2026-10-12',
        ]);
    }
}
