<?php

namespace Tests\Feature\Workforce;

use App\Domains\Fleet\Application\Actions\AssignInternalDriver;
use App\Domains\Workforce\Application\Actions\UpdateDriverContract;
use App\Domains\Workforce\Application\Data\UpdateDriverContractData;
use App\Models\Driver;
use App\Models\DriverContract;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use App\Models\VehiclePause;
use App\Shared\Http\ApiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les gardes d'affectation (spec 2026-10-21, §3.5 et §4.4) : un véhicule quitté par son agent
 * ne repart que par reconduction, et un compte fermé ne reçoit plus de travail. Les chemins
 * du formulaire d'agent sont dans `AdminDriverUpdateApiTest`.
 */
class AssignmentGuardsTest extends TestCase
{
    use RefreshDatabase;

    /** Un véhicule sous contrat propriétaire actif, dont l'agent est parti. */
    private function vehicleAwaitingRenewal(): Vehicle
    {
        $vehicle = Vehicle::factory()->create(['is_active' => false]);
        $vehicleContract = VehicleContract::factory()->forVehicle($vehicle)->create();
        $ended = DriverContract::factory()->forVehicleContract($vehicleContract)->create([
            'status' => 'ended', 'end_date' => now()->subDays(3)->toDateString(),
        ]);
        VehiclePause::factory()->forContract($vehicleContract)->create([
            'driver_contract_id' => $ended->id,
            'start_date' => now()->subDays(2)->toDateString(),
            'end_date' => null,
            'reason_type' => 'agent_change',
            'is_auto' => true,
        ]);

        return $vehicle;
    }

    private function assertRefused(string $code, callable $act): void
    {
        try {
            $act();
            $this->fail("Refus {$code} attendu.");
        } catch (ApiException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    public function test_moving_a_contract_to_a_vehicle_awaiting_renewal_is_refused(): void
    {
        $vehicle = $this->vehicleAwaitingRenewal();
        $contract = DriverContract::factory()->create();

        $this->assertRefused('VEHICLE_AWAITS_RENEWAL', fn () => app(UpdateDriverContract::class)(
            $contract,
            new UpdateDriverContractData($contract->start_date->toDateString(), 24, $vehicle->id),
        ));
    }

    public function test_an_internal_assignment_of_a_closed_account_is_refused(): void
    {
        $driver = Driver::factory()->create();
        $driver->user->update(['is_active' => false]);
        $vehicleContract = VehicleContract::factory()->create();

        $this->assertRefused('DRIVER_ACCOUNT_DISABLED', fn () => app(AssignInternalDriver::class)(
            $vehicleContract, $driver, now()->toDateString(), null, null,
        ));
    }
}
