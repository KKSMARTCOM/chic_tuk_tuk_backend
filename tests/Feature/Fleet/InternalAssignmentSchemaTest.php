<?php

namespace Tests\Feature\Fleet;

use App\Models\Driver;
use App\Models\InternalAssignment;
use App\Models\VehicleContract;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Les garanties en base de la spec 2026-10-09, §3. */
class InternalAssignmentSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_pending_contract_has_no_start_date_and_only_it(): void
    {
        $pending = VehicleContract::factory()->create(['status' => 'pending', 'start_date' => null]);
        $this->assertNull($pending->fresh()->start_date);

        $this->expectException(QueryException::class);
        VehicleContract::factory()->create(['status' => 'active', 'start_date' => null]);
    }

    public function test_a_pending_contract_cannot_carry_a_start_date(): void
    {
        $this->expectException(QueryException::class);
        VehicleContract::factory()->create(['status' => 'pending', 'start_date' => '2026-10-01']);
    }

    public function test_one_ongoing_assignment_per_vehicle_contract(): void
    {
        $contract = VehicleContract::factory()->create();
        InternalAssignment::factory()->for($contract, 'vehicleContract')->create();

        $this->expectException(QueryException::class);
        InternalAssignment::factory()->for($contract, 'vehicleContract')->create();
    }

    public function test_one_ongoing_assignment_per_driver(): void
    {
        $driver = Driver::factory()->create();
        InternalAssignment::factory()->for($driver)->create();

        $this->expectException(QueryException::class);
        InternalAssignment::factory()->for($driver)->create();
    }

    public function test_ended_assignments_do_not_count(): void
    {
        $contract = VehicleContract::factory()->create();
        InternalAssignment::factory()->for($contract, 'vehicleContract')->ended('2026-09-30')->create(['start_date' => '2026-09-01']);
        $ongoing = InternalAssignment::factory()->for($contract, 'vehicleContract')->create(['start_date' => '2026-10-01']);

        $this->assertTrue($contract->activeInternalAssignment->is($ongoing));
        $this->assertCount(2, $contract->internalAssignments);
    }

    public function test_the_live_contract_of_a_vehicle_is_active_or_pending(): void
    {
        $pending = VehicleContract::factory()->create(['status' => 'pending', 'start_date' => null]);

        $this->assertTrue($pending->vehicle->liveVehicleContract->is($pending));
        $this->assertNull($pending->vehicle->activeVehicleContract);
    }
}
