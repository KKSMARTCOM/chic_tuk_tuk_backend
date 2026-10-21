<?php

namespace Tests\Feature\Workforce;

use App\Domains\Workforce\Application\Actions\EndDriverContract;
use App\Domains\Workforce\Application\Data\EndDriverContractData;
use App\Models\DriverContract;
use App\Models\LeaveRequest;
use App\Models\VehicleContract;
use App\Models\VehiclePause;
use App\Shared\Http\ApiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le départ d'un agent (spec 2026-10-21, §4.2) : véhicule immobilisé, agent indisponible,
 * compte désactivé, et aucune pause agent ne survit au contrat qu'elle concerne.
 */
class DriverDepartureTest extends TestCase
{
    use RefreshDatabase;

    private function activeContract(): DriverContract
    {
        $vehicleContract = VehicleContract::factory()->create();

        return DriverContract::factory()->forVehicleContract($vehicleContract)->create([
            'start_date' => now()->subMonths(3)->toDateString(),
        ]);
    }

    private function end(DriverContract $contract, ?string $date = null): DriverContract
    {
        return app(EndDriverContract::class)($contract, new EndDriverContractData(
            endDate: $date ?? now()->toDateString(),
            endReason: 'demission',
        ));
    }

    /** Une pause agent en cours, avec sa pause véhicule automatique, comme `AddOngoingLeave` la pose. */
    private function startedLeave(DriverContract $contract, int $daysAgo): LeaveRequest
    {
        $vehiclePause = VehiclePause::factory()->create([
            'vehicle_id' => $contract->vehicle_id,
            'vehicle_contract_id' => $contract->vehicle_contract_id,
            'driver_contract_id' => $contract->id,
            'start_date' => now()->subDays($daysAgo)->toDateString(),
            'end_date' => null,
            'reason_type' => 'agent_leave',
            'is_auto' => true,
        ]);

        return LeaveRequest::factory()->ongoing()->create([
            'driver_id' => $contract->driver_id,
            'driver_contract_id' => $contract->id,
            'start_date' => now()->subDays($daysAgo)->toDateString(),
            'vehicle_pause_id' => $vehiclePause->id,
        ]);
    }

    public function test_departure_disables_the_account_and_makes_the_agent_unavailable(): void
    {
        $contract = $this->activeContract();

        $this->end($contract);

        $driver = $contract->driver->refresh();
        $this->assertFalse($driver->is_available);
        $this->assertFalse((bool) $driver->user->refresh()->is_active);
    }

    public function test_departure_closes_a_started_pause_at_the_end_date(): void
    {
        $contract = $this->activeContract();
        $leave = $this->startedLeave($contract, 4);

        $this->end($contract);

        $leave->refresh();
        $this->assertSame('completed', $leave->status);
        $this->assertSame(now()->toDateString(), $leave->end_date->toDateString());
        $this->assertSame(now()->toDateString(), $leave->vehiclePause->refresh()->end_date->toDateString());
        $this->assertTrue(VehiclePause::where('driver_contract_id', $contract->id)
            ->where('reason_type', 'agent_change')->whereNull('end_date')->exists());
    }

    public function test_departure_does_not_tell_the_owner_the_vehicle_resumed(): void
    {
        $contract = $this->activeContract();
        $this->startedLeave($contract, 4);

        $this->end($contract);

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $contract->vehicle->owner_id,
            'title' => 'Reprise du véhicule',
        ]);
    }

    public function test_departure_deletes_a_pause_not_yet_started(): void
    {
        $contract = $this->activeContract();
        $leave = LeaveRequest::factory()->ongoing()->create([
            'driver_id' => $contract->driver_id,
            'driver_contract_id' => $contract->id,
            'start_date' => now()->addDays(5)->toDateString(),
        ]);

        $this->end($contract);

        $this->assertNull(LeaveRequest::find($leave->id));
        $this->assertFalse(VehiclePause::where('driver_contract_id', $contract->id)
            ->where('reason_type', 'agent_leave')->exists());
    }

    public function test_departure_keeps_the_vehicle_immobilized(): void
    {
        $contract = $this->activeContract();
        $this->startedLeave($contract, 4);

        $this->end($contract);

        $vehicle = $contract->vehicle->refresh();
        $this->assertFalse((bool) $vehicle->is_active);
        $this->assertSame('agent_change', $vehicle->activePause?->reason_type);
    }

    public function test_a_departure_dated_in_the_future_is_refused(): void
    {
        // Relecture finale : une fin future fermait le compte d'office, et une pause qui
        // commence entre aujourd'hui et la fin faisait échouer le départ sur un message égarant.
        $contract = $this->activeContract();
        LeaveRequest::factory()->ongoing()->create([
            'driver_id' => $contract->driver_id,
            'driver_contract_id' => $contract->id,
            'start_date' => now()->addDays(2)->toDateString(),
        ]);

        try {
            $this->end($contract, now()->addDays(5)->toDateString());
            $this->fail('Refus attendu.');
        } catch (ApiException $e) {
            $this->assertSame('DRIVER_CONTRACT_END_IN_FUTURE', $e->errorCode);
        }

        $this->assertSame('active', $contract->refresh()->status);
        $this->assertTrue((bool) $contract->driver->user->refresh()->is_active);
    }

    public function test_departure_rejects_the_pending_requests(): void
    {
        $contract = $this->activeContract();
        $request = LeaveRequest::factory()->pending()->create([
            'driver_id' => $contract->driver_id,
            'driver_contract_id' => $contract->id,
        ]);

        $this->end($contract);

        $request->refresh();
        $this->assertSame('rejected', $request->status);
        $this->assertSame('Départ de l\'agent.', $request->rejection_reason);
    }
}
